<?php
declare(strict_types=1);

// =====================================================================
//  Tokens temporales de la Consola SSH (modulo VPS).
//
//  Diseño: token AUTOFIRMADO (stateless), sin tabla en BD. PHP lo emite
//  al abrir la consola (tras validar sesion + permiso) y lo verifica el
//  server Node reenviandolo a consola_validar.php. El secreto (HMAC) vive
//  solo en PHP -> Node nunca puede forjar un token.
//
//  Formato:  base64url(payloadJson) . "." . base64url(hmacSha256)
//  Payload:  { v, usuario_id, vps_id, credencial_id, ip, iat, exp }
//
//  Requiere que config.php ya este cargado (CONSOLA_SECRET / TTL).
// =====================================================================

const CONSOLA_TOKEN_VERSION = 1;

// base64url sin padding (seguro para transporte en cabeceras/query/JSON).
function consola_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function consola_b64url_decode(string $txt): string
{
    $b64 = strtr($txt, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($b64, true);
    return $bin === false ? '' : $bin;
}

/**
 * Firma un token de consola.
 *
 * @param array $datos  Debe traer al menos usuario_id y vps_id; credencial_id
 *                      e ip son opcionales.
 * @param int|null $ttl Vigencia en segundos (por defecto CONSOLA_TOKEN_TTL).
 * @return string       Token firmado.
 */
function consola_firmar_token(array $datos, ?int $ttl = null): string
{
    $ahora   = time();
    $ttl     = $ttl ?? CONSOLA_TOKEN_TTL;
    $payload = [
        'v'             => CONSOLA_TOKEN_VERSION,
        'usuario_id'    => $datos['usuario_id']    ?? null,
        'vps_id'        => $datos['vps_id']        ?? null,
        'credencial_id' => $datos['credencial_id'] ?? null,
        'ip'            => $datos['ip']            ?? null,
        'iat'           => $ahora,
        'exp'           => $ahora + $ttl,
    ];

    $cuerpo = consola_b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $firma  = consola_b64url_encode(hash_hmac('sha256', $cuerpo, CONSOLA_SECRET, true));

    return $cuerpo . '.' . $firma;
}

/**
 * Verifica un token de consola (firma + expiracion).
 *
 * @param string $token
 * @return array|null  Payload decodificado si es valido; null si no lo es.
 */
function consola_verificar_token(string $token): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 2) {
        return null;
    }
    [$cuerpo, $firma] = $partes;
    if ($cuerpo === '' || $firma === '') {
        return null;
    }

    // Firma esperada; comparacion en tiempo constante para evitar timing attacks.
    $esperada = consola_b64url_encode(hash_hmac('sha256', $cuerpo, CONSOLA_SECRET, true));
    if (!hash_equals($esperada, $firma)) {
        return null;
    }

    $payload = json_decode(consola_b64url_decode($cuerpo), true);
    if (!is_array($payload)) {
        return null;
    }
    if (($payload['v'] ?? null) !== CONSOLA_TOKEN_VERSION) {
        return null;
    }
    if (!isset($payload['exp']) || time() >= (int)$payload['exp']) {
        return null; // expirado
    }

    return $payload;
}

/**
 * Lee el token del cuerpo JSON y lo verifica; corta con 422/401 si falta o
 * es invalido. Atajo para los endpoints server-to-server de la consola.
 * Requiere response.php (body_json/json_error), cargado por bootstrap.php.
 *
 * @return array{0: array, 1: array}  [payload, cuerpoJson]
 */
function consola_token_de_body(): array
{
    $in    = body_json();
    $token = trim((string)($in['token'] ?? ''));
    if ($token === '') {
        json_error('Token requerido', 422);
    }
    $payload = consola_verificar_token($token);
    if ($payload === null) {
        json_error('Token invalido o expirado', 401);
    }
    return [$payload, $in];
}
