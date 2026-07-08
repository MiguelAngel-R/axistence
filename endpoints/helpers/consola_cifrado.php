<?php
declare(strict_types=1);

// =====================================================================
//  Cifrado de secretos de la Consola SSH (credenciales del VPS).
//
//  Las credenciales SSH (password o clave privada, y su passphrase) se
//  guardan CIFRADAS en `vps_credenciales_ssh` — NUNCA en texto plano. Se
//  usa AES-256-GCM (cifrado autenticado: detecta manipulacion).
//
//  Formato almacenado:  "g1:" . base64( iv(12) | tag(16) | ciphertext )
//  La clave se deriva de CONSOLA_CRYPT_KEY (config.php) con SHA-256.
//
//  Requiere que config.php ya este cargado.
// =====================================================================

const CONSOLA_CIFRADO_PREFIJO = 'g1:';
const CONSOLA_CIFRADO_ALGO    = 'aes-256-gcm';

function consola_cifrado_clave(): string
{
    // 32 bytes para AES-256 a partir del secreto configurado.
    return hash('sha256', CONSOLA_CRYPT_KEY, true);
}

/**
 * Cifra un texto plano. Devuelve la cadena lista para guardar en BD.
 */
function consola_cifrar(string $plano): string
{
    $iv  = random_bytes(12); // 96 bits recomendado para GCM
    $tag = '';
    $ct  = openssl_encrypt($plano, CONSOLA_CIFRADO_ALGO, consola_cifrado_clave(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('No se pudo cifrar el secreto');
    }
    return CONSOLA_CIFRADO_PREFIJO . base64_encode($iv . $tag . $ct);
}

/**
 * Descifra un valor previamente cifrado con consola_cifrar().
 * Devuelve null si el formato es invalido o la autenticacion falla.
 */
function consola_descifrar(string $almacenado): ?string
{
    if (strncmp($almacenado, CONSOLA_CIFRADO_PREFIJO, strlen(CONSOLA_CIFRADO_PREFIJO)) !== 0) {
        return null;
    }
    $bin = base64_decode(substr($almacenado, strlen(CONSOLA_CIFRADO_PREFIJO)), true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) {
        return null;
    }
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $plano = openssl_decrypt($ct, CONSOLA_CIFRADO_ALGO, consola_cifrado_clave(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plano === false ? null : $plano;
}
