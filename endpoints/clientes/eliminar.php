<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Eliminar un cliente (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia (guarda el nombre para la auditoria)
//    -> DELETE. Por diseno del esquema, al borrar el cliente caen en cascada
//    sus contactos, notas, relaciones N:N (VPS/dominios/correo/hosting/otros)
//    y sus PROYECTOS (cliente_id ON DELETE CASCADE). -> audita ELIMINAR -> JSON.
//  Si alguna FK RESTRICT lo impidiera, se responde 409 con un mensaje claro.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Clientes', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// El cliente debe existir (guardamos su nombre para la auditoria).
$stmt = $pdo->prepare('SELECT nombre_razon_social FROM public.clientes WHERE id = :id');
$stmt->execute([':id' => $id]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('Cliente no encontrado', 404);
}

try {
    $pdo->prepare('DELETE FROM public.clientes WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el cliente porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Clientes',
    'Elimino el cliente ' . $nombre,
    $id,
    ['nombre_razon_social' => $nombre],
    null
);

json_ok(['id' => $id], 'Cliente eliminado correctamente');
