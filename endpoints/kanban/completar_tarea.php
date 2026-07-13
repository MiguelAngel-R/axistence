<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Marcar/desmarcar una tarjeta como completada.
//  POST JSON: { proyecto_id, tarea_id, completada: bool }
//  Usa tareas_kanban.fecha_finalizacion_real como marca de cumplimiento:
//  completada=true  -> fecha_finalizacion_real = ahora
//  completada=false -> fecha_finalizacion_real = NULL
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');
$completada = filter_var($in['completada'] ?? false, FILTER_VALIDATE_BOOLEAN);

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT titulo FROM public.tareas_kanban WHERE id = :t AND proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$titulo = $stmt->fetchColumn();
if ($titulo === false) {
    json_error('La tarea no pertenece al proyecto', 404);
}

$stmt = $pdo->prepare(
    'UPDATE public.tareas_kanban
        SET fecha_finalizacion_real = ' . ($completada ? 'CURRENT_TIMESTAMP' : 'NULL') . '
      WHERE id = :t AND proyecto_id = :p
      RETURNING fecha_finalizacion_real'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$fecha = $stmt->fetchColumn();

registrar_auditoria(
    'CAMBIO_ESTADO',
    'Kanban',
    ($completada ? 'Marco como completada' : 'Reabrio') . ' la tarea "' . $titulo . '"',
    $tareaId,
    null,
    ['completada' => $completada]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que marquen/desmarquen la tarjeta como completada sin recargar. Viajan
// proyecto_id (para filtrar por detalle), tarea_id y el nuevo estado. Es
// idempotente: aplicar el mismo estado deja la misma tarjeta. Se emite SOLO tras
// la actualizacion y la auditoria (fire-and-forget: nunca rompe la operacion).
notificar_socket('proyectos', 'tarea:completada', [
    'proyecto_id' => $proyectoId,
    'tarea_id'    => $tareaId,
    'completada'  => $completada,
]);

json_ok([
    'completada'              => $completada,
    'fecha_finalizacion_real' => $fecha !== false ? $fecha : null,
], $completada ? 'Tarea completada' : 'Tarea reabierta');
