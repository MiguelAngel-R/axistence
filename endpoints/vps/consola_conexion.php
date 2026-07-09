<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Parametros de conexion DESCIFRADOS (server-to-server).
//
//  Lo llama el server Node justo antes de abrir el SSH (reemplaza el seam
//  de desarrollo `obtenerParametrosSsh`). Autorizacion = token firmado por
//  PHP. Devuelve el secreto DESCIFRADO (password o clave privada) para que
//  Node establezca la conexion. La sesion nunca vive en el navegador.
//
//  POST { token }
//  200 -> { ok, data: { host, puerto, usuario, tipo_auth, secreto, passphrase } }
//  401 token ; 404 credencial ; 409 credencial ambigua ; 500 fallo al descifrar
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';
require_once __DIR__ . '/../helpers/consola_cifrado.php';

solo_metodo('POST');
consola_requiere_node_key();

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

[$payload] = consola_token_de_body();

$vpsId       = (string)($payload['vps_id'] ?? '');
$credencialId = (string)($payload['credencial_id'] ?? '');

if (!preg_match(UUID_RE, $vpsId)) {
    json_error('VPS del token no valido', 422);
}

$pdo = Database::get();

if ($credencialId !== '' && preg_match(UUID_RE, $credencialId)) {
    $stmt = $pdo->prepare(
        'SELECT host, puerto, usuario, tipo_auth, secreto_cifrado, passphrase_cifrada
           FROM public.vps_credenciales_ssh
          WHERE id = :id AND vps_id = :vps'
    );
    $stmt->execute([':id' => $credencialId, ':vps' => $vpsId]);
    $cred = $stmt->fetch();
} else {
    // Sin credencial en el token: usar la unica del VPS (si hay exactamente una).
    $stmt = $pdo->prepare(
        'SELECT host, puerto, usuario, tipo_auth, secreto_cifrado, passphrase_cifrada
           FROM public.vps_credenciales_ssh
          WHERE vps_id = :vps'
    );
    $stmt->execute([':vps' => $vpsId]);
    $filas = $stmt->fetchAll();
    if (count($filas) > 1) {
        json_error('Credencial ambigua para el VPS', 409);
    }
    $cred = $filas[0] ?? false;
}

if (!$cred) {
    json_error('Credencial SSH no encontrada', 404);
}

$secreto = consola_descifrar((string)$cred['secreto_cifrado']);
if ($secreto === null) {
    json_error('No se pudo descifrar la credencial', 500);
}
$passphrase = null;
if (!empty($cred['passphrase_cifrada'])) {
    $passphrase = consola_descifrar((string)$cred['passphrase_cifrada']);
}

json_ok([
    'host'       => $cred['host'],
    'puerto'     => (int)$cred['puerto'],
    'usuario'    => $cred['usuario'],
    'tipo_auth'  => $cred['tipo_auth'],
    'secreto'    => $secreto,
    'passphrase' => $passphrase,
], 'Parametros de conexion');
