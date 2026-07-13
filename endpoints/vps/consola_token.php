<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Emision de token (frontend, con sesion).
//
//  El navegador pide un token ANTES de conectarse por Socket.IO. Aqui es
//  donde se valida la sesion y el permiso (VPS.editar, porque abrir la
//  consola permite EJECUTAR comandos), se fija la IP real del operador y
//  la credencial elegida, y se firma un token de corta vida.
//
//  POST { vps_id, credencial_id? }
//  200 -> { ok, data: { token, ttl } }
//  Si el VPS tiene una sola credencial, credencial_id es opcional.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
$u = requiere_permiso('VPS', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in          = body_json();
$vpsId       = trim((string)($in['vps_id'] ?? ''));
$credencialId = trim((string)($in['credencial_id'] ?? ''));

if (!preg_match(UUID_RE, $vpsId)) {
    json_error('vps_id no valido', 422);
}

$pdo = Database::get();

$chk = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
$chk->execute([':id' => $vpsId]);
if (!$chk->fetchColumn()) {
    json_error('VPS no encontrado', 404);
}

// Resolver la credencial: la indicada (debe pertenecer al VPS) o la unica
// que tenga el VPS. Si hay varias y no se indica, se exige elegir.
if ($credencialId !== '') {
    if (!preg_match(UUID_RE, $credencialId)) {
        json_error('credencial_id no valido', 422);
    }
    $q = $pdo->prepare('SELECT id FROM public.vps_credenciales_ssh WHERE id = :id AND vps_id = :vps');
    $q->execute([':id' => $credencialId, ':vps' => $vpsId]);
    if (!$q->fetchColumn()) {
        json_error('La credencial no pertenece a este VPS', 404);
    }
} else {
    $q = $pdo->prepare('SELECT id FROM public.vps_credenciales_ssh WHERE vps_id = :vps');
    $q->execute([':vps' => $vpsId]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 0) {
        json_error('El VPS no tiene credenciales SSH configuradas', 409);
    }
    if (count($ids) > 1) {
        json_error('El VPS tiene varias credenciales; indica cual usar', 422);
    }
    $credencialId = (string)$ids[0];
}

$token = consola_firmar_token([
    'usuario_id'    => $u['id'],
    'vps_id'        => $vpsId,
    'credencial_id' => $credencialId,
    'ip'            => $_SERVER['REMOTE_ADDR'] ?? null,
]);

json_ok(['token' => $token, 'ttl' => CONSOLA_TOKEN_TTL], 'Token emitido');
