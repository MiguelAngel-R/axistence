<?php
declare(strict_types=1);

// =====================================================================
//  Notificaciones - validacion de token (server-to-server).
//
//  Lo llama el server Node cuando un socket emite 'autenticar_usuario'. Verifica
//  el token firmado y devuelve el usuario al que pertenece, para que Node una el
//  socket a la sala 'usuario:<id>'. Autorizado con la clave compartida Node<->PHP
//  (misma que la consola), asi solo el server Node puede invocarlo.
//
//  POST { token }  ->  200 { data: { usuario_id } }
//                       401 token invalido/expirado ; 403 sin node-key
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
consola_requiere_node_key();

[$payload, $in] = consola_token_de_body();

$usuarioId = trim((string)($payload['usuario_id'] ?? ''));
if ($usuarioId === '') {
    json_error('Token sin usuario', 422);
}

json_ok(['usuario_id' => $usuarioId]);
