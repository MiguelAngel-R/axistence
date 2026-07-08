<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Agregar un comentario a una tarjeta (tarea).
//  POST JSON: { proyecto_id, tarea_id, comentario }
//  El autor es el usuario en sesion; la fecha la pone la BD.
//  Devuelve el comentario recien creado (id, texto, fecha, autor).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'crear');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');
$comentario = trim((string)($in['comentario'] ?? ''));

if ($comentario === '') {
    json_error('El comentario no puede estar vacio', 422, ['faltantes' => ['comentario']]);
}
if (mb_strlen($comentario) > 4000) {
    json_error('El comentario es demasiado largo (maximo 4000 caracteres)', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto (evita comentar en tableros ajenos).
$stmt = $pdo->prepare(
    'SELECT titulo FROM public.tareas_kanban
      WHERE id = :t AND proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$titulo = $stmt->fetchColumn();
if ($titulo === false) {
    json_error('La tarea no pertenece al proyecto', 404);
}

$u = usuario_actual();

$stmt = $pdo->prepare(
    'INSERT INTO public.tarea_comentarios (tarea_id, autor_id, comentario)
     VALUES (:t, :a, :c)
     RETURNING id, comentario, fecha'
);
$stmt->execute([':t' => $tareaId, ':a' => $u['id'] ?? null, ':c' => $comentario]);
$fila = $stmt->fetch();

registrar_auditoria(
    'CREAR',
    'Kanban',
    'Comento en la tarea "' . $titulo . '"',
    $tareaId,
    null,
    ['comentario' => $comentario]
);

json_ok([
    'id'         => $fila['id'],
    'comentario' => $fila['comentario'],
    'fecha'      => $fila['fecha'],
    'autor'      => $u['nombre_completo'] ?? null,
], 'Comentario agregado');
