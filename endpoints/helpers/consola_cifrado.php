<?php
declare(strict_types=1);

// =====================================================================
//  Cifrado de secretos de la Consola SSH (credenciales del VPS).
//
//  Las credenciales SSH (password o clave privada, y su passphrase) se
//  guardan CIFRADAS en `vps_credenciales_ssh` — NUNCA en texto plano. Se
//  usa AES-256-GCM (cifrado autenticado: detecta manipulacion y sirve de
//  verificador implicito de la clave).
//
//  Dos esquemas conviven por version (prefijo):
//    g1:  LEGACY. Clave derivada de CONSOLA_CRYPT_KEY (config.php) con
//         SHA-256. La clave vive junto a los datos (env/config) -> debil.
//         Se conserva SOLO para detectar credenciales viejas y pedir
//         recapturarlas; ver consola_descifrar().
//    g2:  ACTUAL. La clave (32 bytes) llega POR PARAMETRO. Es la DEK que
//         abre la llave maestra (ver consola_llave_maestra.php). La palabra
//         maestra no vive en el sistema.
//
//  Formato almacenado (ambos):  "<pref>" . base64( iv(12) | tag(16) | ct )
//
//  Requiere que config.php ya este cargado (solo para el esquema g1 legacy).
// =====================================================================

const CONSOLA_CIFRADO_ALGO       = 'aes-256-gcm';
const CONSOLA_CIFRADO_PREFIJO    = 'g1:'; // LEGACY (clave desde env)
const CONSOLA_GCM_PREFIJO        = 'g2:'; // ACTUAL (clave por parametro / DEK)

// ---------------------------------------------------------------------
//  Primitivas AES-256-GCM parametrizadas por clave (esquema g2)
// ---------------------------------------------------------------------

/**
 * Cifra un texto plano con una clave de 32 bytes. Devuelve la cadena lista
 * para guardar en BD, con prefijo de version g2:.
 */
function consola_gcm_cifrar(string $plano, string $clave32): string
{
    if (strlen($clave32) !== 32) {
        throw new RuntimeException('Clave de cifrado invalida (se esperaban 32 bytes)');
    }
    $iv  = random_bytes(12); // 96 bits recomendado para GCM
    $tag = '';
    $ct  = openssl_encrypt($plano, CONSOLA_CIFRADO_ALGO, $clave32, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('No se pudo cifrar el secreto');
    }
    return CONSOLA_GCM_PREFIJO . base64_encode($iv . $tag . $ct);
}

/**
 * Descifra un valor cifrado con consola_gcm_cifrar() usando la clave de 32
 * bytes. Devuelve null si el formato es invalido, la clave es incorrecta o
 * la autenticacion GCM falla.
 */
function consola_gcm_descifrar(string $almacenado, string $clave32): ?string
{
    if (strlen($clave32) !== 32) {
        return null;
    }
    if (strncmp($almacenado, CONSOLA_GCM_PREFIJO, strlen(CONSOLA_GCM_PREFIJO)) !== 0) {
        return null;
    }
    $bin = base64_decode(substr($almacenado, strlen(CONSOLA_GCM_PREFIJO)), true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) {
        return null;
    }
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $plano = openssl_decrypt($ct, CONSOLA_CIFRADO_ALGO, $clave32, OPENSSL_RAW_DATA, $iv, $tag);
    return $plano === false ? null : $plano;
}

/**
 * ¿El valor almacenado usa el esquema legacy g1: (clave desde env)?
 * Sirve para detectar credenciales viejas y pedir recapturarlas.
 */
function consola_es_legacy(string $almacenado): bool
{
    return strncmp($almacenado, CONSOLA_CIFRADO_PREFIJO, strlen(CONSOLA_CIFRADO_PREFIJO)) === 0;
}

// ---------------------------------------------------------------------
//  LEGACY (esquema g1): descifrado con la clave del env. Solo lectura,
//  para compatibilidad; el alta/edicion nuevas usan g2 con la DEK.
// ---------------------------------------------------------------------

function consola_cifrado_clave(): string
{
    // 32 bytes para AES-256 a partir del secreto configurado (legacy).
    return hash('sha256', CONSOLA_CRYPT_KEY, true);
}

/**
 * Descifra un valor legacy (g1:) con la clave del env. Devuelve null si el
 * formato es invalido o la autenticacion falla.
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
