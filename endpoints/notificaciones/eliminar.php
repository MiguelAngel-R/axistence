<?php
declare(strict_types=1);

// =====================================================================
//  Notificaciones - eliminar (las del usuario en sesion).
//
//  DELETE { id }        -> borra UNA notificacion (404 si no es suya/no existe).
//  DELETE { todas:true } -> borra TODAS las del usuario ("limpiar").
//
//  Personales: el WHERE siempre acota por usuario_id de la sesion. Devuelve el
//  nuevo contador de no leidas para refrescar el badge.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('DELETE');
$u = requiere_login();

$in    = body_json();
$todas = !empty($in['todas']);
$id    = trim((string)($in['id'] ?? ''));

$pdo = Database::get();

if ($todas) {
    $stmt = $pdo->prepare('DELETE FROM public.notificaciones WHERE usuario_id = :uid');
    $stmt->execute([':uid' => $u['id']]);
    $afectadas = $stmt->rowCount();
} else {
    if (!preg_match(NOTIF_UUID_RE, $id)) {
        json_error('id no valido', 422);
    }
    $stmt = $pdo->prepare(
        'DELETE FROM public.notificaciones WHERE id = :id AND usuario_id = :uid RETURNING id'
    );
    $stmt->execute([':id' => $id, ':uid' => $u['id']]);
    if ($stmt->fetchColumn() === false) {
        json_error('Notificacion no encontrada', 404);
    }
    $afectadas = 1;
}

$stmtNo = $pdo->prepare(
    'SELECT count(*) FROM public.notificaciones WHERE usuario_id = :uid AND leida = false'
);
$stmtNo->execute([':uid' => $u['id']]);
$noLeidas = (int)$stmtNo->fetchColumn();

// Sincroniza las OTRAS pestañas del mismo usuario (evento 'notificacion:consumida'
// a su propia sala). La pestaña que disparo la accion tambien lo recibe, pero el
// frontend es idempotente. fire-and-forget (no rompe la respuesta).
notificar_socket('notificaciones', 'notificacion:consumida', [
    'usuario_id' => $u['id'],
    'payload'    => [
        'accion'    => $todas ? 'todas_eliminadas' : 'eliminada',
        'id'        => $todas ? null : $id,
        'no_leidas' => $noLeidas,
    ],
]);

json_ok(['afectadas' => $afectadas, 'no_leidas' => $noLeidas], 'Notificaciones eliminadas');
