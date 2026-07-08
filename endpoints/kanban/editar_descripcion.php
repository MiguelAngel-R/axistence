<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Editar la descripcion de una tarjeta (tarea).
//  POST JSON: { proyecto_id, tarea_id, descripcion }
//  Actualiza tareas_kanban.descripcion (vacio -> NULL). Devuelve la
//  descripcion guardada y el updated_at (para refrescar el detalle).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');
$descrip    = trim((string)($in['descripcion'] ?? ''));

if (mb_strlen($descrip) > 4000) {
    json_error('La descripcion es demasiado larga (maximo 4000 caracteres)', 422);
}

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

$descripBd = $descrip !== '' ? $descrip : null;

$stmt = $pdo->prepare(
    'UPDATE public.tareas_kanban
        SET descripcion = :d
      WHERE id = :t AND proyecto_id = :p
      RETURNING descripcion, updated_at'
);
$stmt->execute([':d' => $descripBd, ':t' => $tareaId, ':p' => $proyectoId]);
$fila = $stmt->fetch();

registrar_auditoria(
    'MODIFICAR',
    'Kanban',
    'Edito la descripcion de la tarea "' . $titulo . '"',
    $tareaId
);

json_ok([
    'descripcion' => $fila['descripcion'],
    'updated_at'  => $fila['updated_at'],
], 'Descripcion actualizada');
