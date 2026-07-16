<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Asignar responsables a una tarjeta (tarea).
//  POST JSON: { proyecto_id, tarea_id, usuarios: [uuid, ...] }
//  Sincroniza (reemplaza) el conjunto de responsables en tarea_responsables.
//  Solo se admiten integrantes del EQUIPO del proyecto (candidatos validos).
//  Devuelve la lista actualizada de responsables (con nombre y correo).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');

// Ids de usuario deseados (unicos y validos).
$brutos   = is_array($in['usuarios'] ?? null) ? $in['usuarios'] : [];
$usuarios = [];
foreach ($brutos as $x) {
    $x = trim((string)$x);
    if ($x !== '' && kanban_uuid_valido($x) && !in_array($x, $usuarios, true)) {
        $usuarios[] = $x;
    }
}

$pdo = Database::get();
$proyecto = kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT titulo FROM public.tareas_kanban WHERE id = :t AND proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$titulo = $stmt->fetchColumn();
if ($titulo === false) {
    json_error('La tarea no pertenece al proyecto', 404);
}

// Responsables ANTES del cambio: para notificar solo a los RECIEN agregados
// (el conjunto se reemplaza; los que ya estaban no reciben aviso de nuevo).
$stmt = $pdo->prepare('SELECT usuario_id FROM public.tarea_responsables WHERE tarea_id = :t');
$stmt->execute([':t' => $tareaId]);
$responsablesPrevios = array_column($stmt->fetchAll(), 'usuario_id');

// Solo se pueden asignar integrantes del equipo del proyecto.
if ($usuarios) {
    $stmt = $pdo->prepare(
        'SELECT usuario_id FROM public.proyecto_equipo WHERE proyecto_id = :p'
    );
    $stmt->execute([':p' => $proyectoId]);
    $equipo = array_column($stmt->fetchAll(), 'usuario_id');
    foreach ($usuarios as $uid) {
        if (!in_array($uid, $equipo, true)) {
            json_error('Solo puedes asignar integrantes del equipo del proyecto', 422);
        }
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM public.tarea_responsables WHERE tarea_id = :t')
        ->execute([':t' => $tareaId]);

    if ($usuarios) {
        $ins = $pdo->prepare(
            'INSERT INTO public.tarea_responsables (tarea_id, usuario_id) VALUES (:t, :u)'
        );
        foreach ($usuarios as $uid) {
            $ins->execute([':t' => $tareaId, ':u' => $uid]);
        }
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('Alguno de los usuarios seleccionados no existe', 422);
    }
    throw $e;
}

// Lista actualizada (para repintar en el frontend).
$stmt = $pdo->prepare(
    'SELECT u.id, u.nombre_completo, u.correo
       FROM public.tarea_responsables tr
       JOIN public.usuarios_internos u ON u.id = tr.usuario_id
      WHERE tr.tarea_id = :t
      ORDER BY u.nombre_completo ASC'
);
$stmt->execute([':t' => $tareaId]);
$responsables = $stmt->fetchAll();

registrar_auditoria(
    'MODIFICAR',
    'Kanban',
    'Actualizo los responsables de la tarea "' . $titulo . '"',
    $tareaId,
    null,
    ['responsables' => $usuarios]
);

// --- Notificacion in-app (Fase 7) -----------------------------------
// Avisa a los responsables RECIEN agregados (conjunto nuevo menos el previo),
// excluyendo a quien realiza la asignacion (no se autonotifica). El clic lleva
// al tablero del proyecto con la tarjeta abierta (deep-link ?detalle=&tarea=).
$actor         = usuario_actual();
$recienAsignados = array_values(array_filter(
    array_diff($usuarios, $responsablesPrevios),
    static fn ($uid) => $uid !== ($actor['id'] ?? null)
));
if ($recienAsignados) {
    notificar(
        $recienAsignados,
        'Proyectos',
        'tarea_asignada',
        'Te asignaron una tarea',
        'Tarea «' . $titulo . '» en el proyecto «' . ($proyecto['nombre_proyecto'] ?? '') . '»',
        'tarea',
        $tareaId,
        ['url' => 'index.php?vista=proyectos&detalle=' . $proyectoId . '&tarea=' . $tareaId]
    );
}

json_ok(['responsables' => $responsables], 'Responsables actualizados');
