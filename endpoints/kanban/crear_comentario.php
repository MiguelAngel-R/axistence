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
$proyecto = kanban_proyecto_o_error($pdo, $proyectoId);

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

$comentarioCreado = [
    'id'         => $fila['id'],
    'comentario' => $fila['comentario'],
    'fecha'      => $fila['fecha'],
    'autor'      => $u['nombre_completo'] ?? null,
];

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de esta MISMA tarjeta
// (modal) para que agreguen el comentario sin recargar. Viajan proyecto_id (para
// filtrar por detalle) y tarea_id (para saber si es la tarjeta abierta), ademas
// del comentario. Se emite SOLO tras la insercion y la auditoria (fire-and-forget).
notificar_socket('proyectos', 'comentario:creado', array_merge($comentarioCreado, [
    'proyecto_id' => $proyectoId,
    'tarea_id'    => $tareaId,
]));

// --- Notificacion in-app (Fase 7) -----------------------------------
// Avisa a quienes siguen la conversacion de la tarjeta: sus responsables y
// quienes ya comentaron antes, sin repetir y excluyendo al autor (no se
// autonotifica). El clic lleva al tablero con la tarjeta abierta.
// Nota: los prepares son nativos (EMULATE_PREPARES=false), por eso cada rama
// del UNION lleva su propio placeholder: pgsql no admite repetir el mismo.
$stmt = $pdo->prepare(
    'SELECT usuario_id FROM public.tarea_responsables WHERE tarea_id = :t1
     UNION
     SELECT autor_id   FROM public.tarea_comentarios  WHERE tarea_id = :t2 AND autor_id IS NOT NULL'
);
$stmt->execute([':t1' => $tareaId, ':t2' => $tareaId]);
$interesados = array_values(array_filter(
    array_column($stmt->fetchAll(), 'usuario_id'),
    static fn ($uid) => $uid !== ($u['id'] ?? null)
));

if ($interesados) {
    // Extracto corto: la notificacion es un aviso, no el comentario completo.
    $extracto = mb_strlen($comentario) > 120
        ? mb_substr($comentario, 0, 120) . '…'
        : $comentario;

    notificar(
        $interesados,
        'Proyectos',
        'comentario_nuevo',
        'Nuevo comentario en una tarea',
        ($u['nombre_completo'] ?? 'Alguien') . ' comento en «' . $titulo . '»: ' . $extracto,
        'tarea',
        $tareaId,
        ['url' => 'index.php?vista=proyectos&detalle=' . $proyectoId . '&tarea=' . $tareaId]
    );
}

json_ok($comentarioCreado, 'Comentario agregado');
