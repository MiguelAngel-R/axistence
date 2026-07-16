<?php
declare(strict_types=1);

// =====================================================================
//  Notificaciones - token de identidad para el socket (frontend, sesion).
//
//  POST -> { data: { token, ttl } }
//
//  El navegador pide este token (ya autenticado por sesion) y lo usa para
//  identificarse ante el server de sockets (evento 'autenticar_usuario'), que
//  lo valida contra PHP (validar.php) y une el socket a la sala 'usuario:<id>'.
//  Asi nadie puede suscribirse a las notificaciones de otro: el usuario del
//  token lo pone PHP, no el navegador.
//
//  Reutiliza el mecanismo de tokens firmados de la consola (HMAC, TTL corto):
//  es una prueba firmada de "soy el usuario X" (payload con usuario_id; vps_id
//  queda en null). El secreto vive solo en PHP -> Node no puede forjarlo.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
$u = requiere_login();

$token = consola_firmar_token([
    'usuario_id' => $u['id'],
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
]);


json_ok(['token' => $token, 'ttl' => CONSOLA_TOKEN_TTL]);
