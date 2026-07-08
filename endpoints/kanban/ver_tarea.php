<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Detalle completo de una tarjeta (tarea).
//  GET params: proyecto_id (uuid), tarea_id (uuid)
//  Devuelve la tarea con su columna actual + responsables, comentarios,
//  adjuntos e historial de movimientos entre columnas.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('GET');
requiere_permiso('Kanban', 'ver');

$proyectoId = kanban_uuid_o_error($_GET['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($_GET['tarea_id']    ?? '', 'Tarea no valida');

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT t.id, t.titulo, t.descripcion, t.prioridad, t.columna_id,
            c.nombre AS columna,
            t.fecha_inicio, t.fecha_limite, t.fecha_finalizacion_real,
            t.created_at, t.updated_at
       FROM public.tareas_kanban t
       JOIN public.proyecto_columnas c ON c.id = t.columna_id
      WHERE t.id = :t AND t.proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$tarea = $stmt->fetch();
if (!$tarea) {
    json_error('La tarea no pertenece al proyecto', 404);
}

// Responsables (N:N).
$stmt = $pdo->prepare(
    'SELECT u.id, u.nombre_completo, u.correo
       FROM public.tarea_responsables tr
       JOIN public.usuarios_internos u ON u.id = tr.usuario_id
      WHERE tr.tarea_id = :t
      ORDER BY u.nombre_completo ASC'
);
$stmt->execute([':t' => $tareaId]);
$responsables = $stmt->fetchAll();

// Equipo del proyecto (candidatos asignables como responsables).
$stmt = $pdo->prepare(
    "SELECT u.id, u.nombre_completo
       FROM public.proyecto_equipo pe
       JOIN public.usuarios_internos u ON u.id = pe.usuario_id
      WHERE pe.proyecto_id = :p AND u.estado = 'Activo'
      ORDER BY u.nombre_completo ASC"
);
$stmt->execute([':p' => $proyectoId]);
$equipo = $stmt->fetchAll();

// Comentarios (mas recientes primero).
$stmt = $pdo->prepare(
    'SELECT co.id, co.comentario, co.fecha, u.nombre_completo AS autor
       FROM public.tarea_comentarios co
       LEFT JOIN public.usuarios_internos u ON u.id = co.autor_id
      WHERE co.tarea_id = :t
      ORDER BY co.fecha DESC'
);
$stmt->execute([':t' => $tareaId]);
$comentarios = $stmt->fetchAll();

// Adjuntos.
$stmt = $pdo->prepare(
    'SELECT a.id, a.nombre_archivo, a.tipo_mime, a.tamano_bytes, a.fecha_subida,
            u.nombre_completo AS subido_por
       FROM public.tarea_adjuntos a
       LEFT JOIN public.usuarios_internos u ON u.id = a.subido_por_id
      WHERE a.tarea_id = :t
      ORDER BY a.fecha_subida DESC'
);
$stmt->execute([':t' => $tareaId]);
$adjuntos = $stmt->fetchAll();

json_ok([
    'tarea'        => $tarea,
    'responsables' => $responsables,
    'equipo'       => $equipo,
    'comentarios'  => $comentarios,
    'adjuntos'     => $adjuntos,
]);
