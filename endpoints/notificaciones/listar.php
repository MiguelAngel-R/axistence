<?php
declare(strict_types=1);

// =====================================================================
//  Notificaciones - listar las del usuario en sesion.
//
//  GET -> { data: { notificaciones: [ { id, modulo, tipo, titulo, mensaje,
//              entidad_tipo, entidad_id, datos, leida, created_at } ],
//           no_leidas: int } }
//
//  Las notificaciones son PERSONALES: cada usuario ve solo las suyas, sin
//  importar permisos de modulo (por eso basta requiere_login, no un permiso).
//  Devuelve las mas recientes (LIMIT) para el panel de la campana + el contador
//  de no leidas para el badge.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
$u = requiere_login();

const NOTIF_LIMITE = 50;   // ultimas N para el panel de la campana

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT id, modulo, tipo, titulo, mensaje, entidad_tipo, entidad_id, datos, leida, created_at
       FROM public.notificaciones
      WHERE usuario_id = :uid
      ORDER BY created_at DESC
      LIMIT ' . NOTIF_LIMITE
);
$stmt->execute([':uid' => $u['id']]);

$notificaciones = [];
foreach ($stmt->fetchAll() as $fila) {
    $notificaciones[] = [
        'id'           => $fila['id'],
        'modulo'       => $fila['modulo'],
        'tipo'         => $fila['tipo'],
        'titulo'       => $fila['titulo'],
        'mensaje'      => $fila['mensaje'],
        'entidad_tipo' => $fila['entidad_tipo'],
        'entidad_id'   => $fila['entidad_id'],
        // jsonb llega como texto: se decodifica para el frontend.
        'datos'        => $fila['datos'] !== null ? json_decode((string)$fila['datos'], true) : null,
        // bool de pgsql puede venir como 't'/'f': se normaliza.
        'leida'        => $fila['leida'] === true || $fila['leida'] === 't',
        'created_at'   => $fila['created_at'],
    ];
}

$stmtNo = $pdo->prepare(
    'SELECT count(*) FROM public.notificaciones WHERE usuario_id = :uid AND leida = false'
);
$stmtNo->execute([':uid' => $u['id']]);
$noLeidas = (int)$stmtNo->fetchColumn();

json_ok(['notificaciones' => $notificaciones, 'no_leidas' => $noLeidas]);
