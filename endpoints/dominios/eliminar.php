<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Eliminar un dominio (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE. Por diseno del esquema,
//    al borrar el dominio caen en cascada sus registros DNS, notas,
//    relaciones N:N (clientes/hosting/proyectos), sus CERTIFICADOS SSL y sus
//    RELACIONES DE CORREO. -> audita ELIMINAR -> JSON.
//    Si alguna FK RESTRICT lo impidiera, se responde 409.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT nombre_dominio FROM public.dominios WHERE id = :id');
$stmt->execute([':id' => $id]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('Dominio no encontrado', 404);
}

try {
    $pdo->prepare('DELETE FROM public.dominios WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el dominio porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Dominios',
    'Elimino el dominio ' . $nombre,
    $id,
    ['nombre_dominio' => $nombre],
    null
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que quiten la
// fila sin recargar. Solo viaja el id (basta para localizar y quitar la fila).
// Se emite SOLO tras el borrado y la auditoria (no se emite en el caso 409:
// ahi no hubo borrado).
notificar_socket('dominios', 'dominio:eliminado', ['id' => $id]);

json_ok(['id' => $id], 'Dominio eliminado correctamente');
