<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Eliminar una columna del tablero.
//  POST JSON: { proyecto_id, columna_id }
//  Reglas de seguridad:
//    - No se borra una columna que tenga tarjetas (FK ON DELETE RESTRICT):
//      hay que moverlas antes. Devuelve 409.
//    - El tablero debe conservar al menos una columna. Devuelve 409.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'eliminar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$columnaId  = kanban_uuid_o_error($in['columna_id']  ?? '', 'Columna no valida');

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La columna debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT nombre FROM public.proyecto_columnas
      WHERE id = :c AND proyecto_id = :p'
);
$stmt->execute([':c' => $columnaId, ':p' => $proyectoId]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('La columna no pertenece al proyecto', 404);
}

// No permitir dejar el tablero sin columnas.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM public.proyecto_columnas WHERE proyecto_id = :p'
);
$stmt->execute([':p' => $proyectoId]);
if ((int) $stmt->fetchColumn() <= 1) {
    json_error('El tablero debe tener al menos una columna', 409);
}

// No borrar columnas con tarjetas (hay que vaciarlas primero).
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM public.tareas_kanban WHERE columna_id = :c'
);
$stmt->execute([':c' => $columnaId]);
$tareas = (int) $stmt->fetchColumn();
if ($tareas > 0) {
    json_error(
        'No se puede eliminar una columna con tarjetas. Mueve o elimina sus ' .
        $tareas . ' tarjeta(s) primero.',
        409
    );
}

$pdo->prepare(
    'DELETE FROM public.proyecto_columnas WHERE id = :c AND proyecto_id = :p'
)->execute([':c' => $columnaId, ':p' => $proyectoId]);

registrar_auditoria(
    'ELIMINAR',
    'Kanban',
    'Elimino la columna "' . $nombre . '" del tablero',
    $columnaId,
    ['nombre' => $nombre],
    null
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que quiten la columna sin recargar. Viajan proyecto_id (para filtrar por
// detalle) y columna_id. La columna borrada no tenia tarjetas (ya validado). No
// se emite en los casos 409 (columna con tarjetas o unica columna): ahi no hubo
// borrado. Se emite SOLO tras el borrado y la auditoria (fire-and-forget).
notificar_socket('proyectos', 'columna:eliminada', [
    'proyecto_id' => $proyectoId,
    'columna_id'  => $columnaId,
]);

json_ok(['id' => $columnaId], 'Columna eliminada');
