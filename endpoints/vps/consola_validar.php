<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Validacion de token (server-to-server).
//
//  Lo consume el server Node (websockets/) durante el handshake de
//  Socket.IO, ANTES de abrir la conexion SSH. NO usa sesion de navegador:
//  la autorizacion es el propio token firmado por PHP (HMAC). Node reenvia
//  el token y aqui se verifica firma + expiracion.
//
//  POST { token }
//  200 -> { ok, data: { usuario_id, vps_id, credencial_id, iat, exp } }
//  401 -> token invalido o expirado
//
//  En F3/F5 este endpoint (o consola_credenciales.php) devolvera ademas
//  la credencial SSH descifrada y creara la fila en vps_consola_sesiones.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');

$in    = body_json();
$token = trim((string)($in['token'] ?? ''));
if ($token === '') {
    json_error('Token requerido', 422);
}

$payload = consola_verificar_token($token);
if ($payload === null) {
    json_error('Token invalido o expirado', 401);
}

json_ok([
    'usuario_id'    => $payload['usuario_id']    ?? null,
    'vps_id'        => $payload['vps_id']        ?? null,
    'credencial_id' => $payload['credencial_id'] ?? null,
    'iat'           => $payload['iat']           ?? null,
    'exp'           => $payload['exp']           ?? null,
], 'Token valido');
