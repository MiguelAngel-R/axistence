<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Eliminar un proyecto (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE.
//  Al borrar el proyecto caen en cascada su equipo, fases/sprints, hitos,
//  recursos N:N (dominios/SSL/hosting/VPS), notas y columnas del tablero.
//  IMPORTANTE: las tarjetas (tareas_kanban) referencian a la columna con
//  FK RESTRICT, que entraria en conflicto con la cascada de columnas. Por
//  eso se borran primero las tarjetas del proyecto, dentro de una
//  transaccion, y luego el proyecto. -> audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Proyectos', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT nombre_proyecto FROM public.proyectos WHERE id = :id');
$stmt->execute([':id' => $id]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('Proyecto no encontrado', 404);
}

$pdo->beginTransaction();
try {
    // Primero las tarjetas (evita el conflicto RESTRICT columna<-tarjeta al
    // cascadear las columnas del tablero); el resto cae por ON DELETE CASCADE.
    $pdo->prepare('DELETE FROM public.tareas_kanban WHERE proyecto_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM public.proyectos WHERE id = :id')->execute([':id' => $id]);
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el proyecto porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Proyectos',
    'Elimino el proyecto ' . $nombre,
    $id,
    ['nombre_proyecto' => $nombre],
    null
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que quiten la
// fila sin recargar. Solo viaja el id (basta para localizar y quitar la fila).
// Se emite SOLO tras el borrado y la auditoria (no se emite en el caso 409:
// ahi no hubo borrado).
notificar_socket('proyectos', 'proyecto:eliminado', ['id' => $id]);

json_ok(['id' => $id], 'Proyecto eliminado correctamente');
