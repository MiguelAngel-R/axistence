<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Archivar / restaurar una tarjeta (tarea).
//  POST JSON: { proyecto_id, tarea_id, archivar: bool }
//  Usa tareas_kanban.fecha_archivado como marca:
//    archivar=true  -> fecha_archivado = ahora  (sale del tablero)
//    archivar=false -> fecha_archivado = NULL    (vuelve al tablero)
//  Al restaurar, la tarjeta se recoloca al FINAL de su columna original
//  (orden_posicion = max(columna) + 1) para no colisionar con las que
//  quedaron en esa columna mientras estuvo archivada.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');
$archivar   = filter_var($in['archivar'] ?? false, FILTER_VALIDATE_BOOLEAN);

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT titulo, columna_id FROM public.tareas_kanban
      WHERE id = :t AND proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$tarea = $stmt->fetch();
if (!$tarea) {
    json_error('La tarea no pertenece al proyecto', 404);
}

if ($archivar) {
    // Archivar: marca la fecha y la saca del tablero.
    $stmt = $pdo->prepare(
        'UPDATE public.tareas_kanban
            SET fecha_archivado = CURRENT_TIMESTAMP
          WHERE id = :t AND proyecto_id = :p'
    );
    $stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
} else {
    // Restaurar: limpia la marca y recoloca al final de su columna.
    $pos = $pdo->prepare(
        'SELECT COALESCE(MAX(orden_posicion) + 1, 0)
           FROM public.tareas_kanban
          WHERE columna_id = :c AND fecha_archivado IS NULL'
    );
    $pos->execute([':c' => $tarea['columna_id']]);
    $orden = (int) $pos->fetchColumn();

    $stmt = $pdo->prepare(
        'UPDATE public.tareas_kanban
            SET fecha_archivado = NULL, orden_posicion = :o
          WHERE id = :t AND proyecto_id = :p'
    );
    $stmt->execute([':o' => $orden, ':t' => $tareaId, ':p' => $proyectoId]);
}

registrar_auditoria(
    'CAMBIO_ESTADO',
    'Kanban',
    ($archivar ? 'Archivo' : 'Restauro') . ' la tarea "' . $tarea['titulo'] . '"',
    $tareaId,
    null,
    ['archivada' => $archivar]
);

json_ok(
    ['archivada' => $archivar],
    $archivar ? 'Tarea archivada' : 'Tarea restaurada'
);
