<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Cerrar sesion (server-to-server).
//
//  Lo llama Node al terminar la sesion SSH (cierre normal, timeout o
//  error). Marca la fila de vps_consola_sesiones con estado final y fin.
//
//  Autenticacion: clave compartida Node<->PHP (X-Consola-Node-Key), NO el
//  token (que ya pudo expirar en una sesion larga). El sesion_id (UUID
//  generado por PHP) identifica la sesion a cerrar.
//
//  POST { sesion_id, estado? }   estado: 'cerrada' (def) | 'error'
//  200 -> { ok } ; 403 node key ; 404 sesion ; 422 datos
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
consola_requiere_node_key();

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in       = body_json();
$sesionId = trim((string)($in['sesion_id'] ?? ''));
$estado   = (string)($in['estado'] ?? 'cerrada');

if (!preg_match(UUID_RE, $sesionId)) {
    json_error('sesion_id no valido', 422);
}
if (!in_array($estado, ['cerrada', 'error'], true)) {
    $estado = 'cerrada';
}

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT estado, vps_id FROM public.vps_consola_sesiones WHERE id = :id');
$stmt->execute([':id' => $sesionId]);
$sesion = $stmt->fetch();

if (!$sesion) {
    json_error('Sesion de consola no encontrada', 404);
}

// Solo cerrar si sigue activa (idempotente: si ya estaba cerrada, no falla).
if ($sesion['estado'] === 'activa') {
    $upd = $pdo->prepare(
        'UPDATE public.vps_consola_sesiones
            SET estado = :estado, fin = CURRENT_TIMESTAMP
          WHERE id = :id AND estado = :activa'
    );
    $upd->execute([':estado' => $estado, ':id' => $sesionId, ':activa' => 'activa']);

    registrar_auditoria(
        'CAMBIO_ESTADO',
        'VPS',
        'Cierre de consola SSH (estado ' . $estado . ')',
        (string)$sesion['vps_id']
    );
}

json_ok(null, 'Sesion de consola cerrada');
