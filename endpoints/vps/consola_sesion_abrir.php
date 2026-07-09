<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Abrir sesion (server-to-server).
//
//  Lo llama el server Node cuando el shell PTY queda listo (Fase 4).
//  Crea una fila en vps_consola_sesiones y devuelve su id, que Node usara
//  para ir registrando cada comando. Autorizacion = token firmado por PHP.
//
//  POST { token }
//  200 -> { ok, data: { sesion_id } }
//  401 -> token invalido/expirado ; 404 -> VPS inexistente
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
consola_requiere_node_key();

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

[$payload] = consola_token_de_body();

$vpsId       = (string)($payload['vps_id'] ?? '');
$usuarioId   = $payload['usuario_id'] ?? null;
$credencialId = $payload['credencial_id'] ?? null;
$ip          = $payload['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);

if (!preg_match(UUID_RE, $vpsId)) {
    json_error('VPS del token no valido', 422);
}
if ($usuarioId !== null && !preg_match(UUID_RE, (string)$usuarioId)) {
    $usuarioId = null;
}
if ($credencialId !== null && !preg_match(UUID_RE, (string)$credencialId)) {
    $credencialId = null;
}

$pdo = Database::get();

// El VPS debe existir (la FK lo garantiza, pero devolvemos 404 limpio).
$chk = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
$chk->execute([':id' => $vpsId]);
if (!$chk->fetchColumn()) {
    json_error('VPS no encontrado', 404);
}

$stmt = $pdo->prepare(
    'INSERT INTO public.vps_consola_sesiones
        (vps_id, credencial_id, usuario_id, estado, direccion_ip)
     VALUES
        (:vps_id, :credencial_id, :usuario_id, :estado, :ip)
     RETURNING id'
);
$stmt->execute([
    ':vps_id'        => $vpsId,
    ':credencial_id' => $credencialId,
    ':usuario_id'    => $usuarioId,
    ':estado'        => 'activa',
    ':ip'            => $ip,
]);
$sesionId = $stmt->fetchColumn();

registrar_auditoria(
    'CREAR',
    'VPS',
    'Apertura de consola SSH (usuario ' . ($usuarioId ?? 'desconocido') . ')',
    $vpsId
);

json_ok(['sesion_id' => $sesionId], 'Sesion de consola abierta');
