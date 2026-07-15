<?php
declare(strict_types=1);

// =====================================================================
//  Llave maestra de la Consola SSH (VPS) — esquema DEK/KEK.
//
//  La palabra maestra que teclea el usuario NUNCA se guarda. De ella se
//  DERIVA (PBKDF2-HMAC-SHA256) una KEK que envuelve/desenvuelve la DEK. La
//  DEK (32 bytes reales) es la que cifra las credenciales SSH (esquema g2).
//
//    palabra maestra --PBKDF2(salt,iter)--> KEK --(AES-GCM)--> DEK envuelta (BD)
//    DEK --(AES-GCM)--> credenciales SSH cifradas (BD)
//
//  En BD (tabla singleton consola_llave_maestra) solo viven: el salt y los
//  parametros del KDF (no secretos) y la DEK ENVUELTA. Sin la palabra, la
//  DEK envuelta es basura ilegible.
//
//  Nota sobre el KDF: se usa PBKDF2 (ext-hash, siempre disponible) en vez de
//  Argon2id porque la extension sodium no esta garantizada en todos los
//  despliegues. El algoritmo y las iteraciones usadas se guardan por fila
//  (kdf_algo/kdf_ops) para poder migrar a Argon2id en el futuro sin romper
//  las llaves existentes.
//
//  Requiere que consola_cifrado.php ya este cargado (usa consola_gcm_*).
// =====================================================================

const CONSOLA_KM_ALGO = 'pbkdf2-sha256';
const CONSOLA_KM_ITER = 600000;            // iteraciones PBKDF2-HMAC-SHA256 (OWASP)
const CONSOLA_KM_SALT_BYTES = 16;

/**
 * Deriva la KEK (32 bytes) desde la palabra maestra con PBKDF2-HMAC-SHA256.
 * El salt y las iteraciones se guardan junto a la llave para reproducir
 * SIEMPRE la misma KEK desde la misma palabra.
 */
function consola_km_kdf(string $clave, string $salt, int $iter): string
{
    return hash_pbkdf2('sha256', $clave, $salt, $iter, 32, true);
}

/**
 * ¿Ya esta configurada la llave maestra? (existe la fila singleton)
 */
function consola_km_estado(PDO $pdo): bool
{
    $q = $pdo->query('SELECT 1 FROM public.consola_llave_maestra LIMIT 1');
    return (bool)$q->fetchColumn();
}

/**
 * Abre la DEK a partir de la palabra maestra.
 * Devuelve:
 *   ['estado' => 'sin_configurar', 'dek' => null]   -> no hay llave aun
 *   ['estado' => 'clave_incorrecta', 'dek' => null] -> la palabra no abre la DEK
 *   ['estado' => 'ok', 'dek' => <32 bytes>]         -> DEK lista para cifrar/descifrar
 */
function consola_km_abrir(PDO $pdo, string $claveMaestra): array
{
    $q = $pdo->query('SELECT kdf_salt, kdf_ops, dek_cifrada FROM public.consola_llave_maestra LIMIT 1');
    $fila = $q->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return ['estado' => 'sin_configurar', 'dek' => null];
    }

    $salt = consola_km_bytes($fila['kdf_salt']);
    $kek  = consola_km_kdf($claveMaestra, $salt, (int)$fila['kdf_ops']);
    $dek  = consola_gcm_descifrar((string)$fila['dek_cifrada'], $kek);

    if ($dek === null) {
        return ['estado' => 'clave_incorrecta', 'dek' => null];
    }
    return ['estado' => 'ok', 'dek' => $dek];
}

/**
 * Configura la llave maestra por primera vez: genera DEK + salt, envuelve la
 * DEK con la KEK derivada de $nueva e inserta la fila singleton.
 * Lanza RuntimeException si ya existe una llave.
 */
function consola_km_configurar(PDO $pdo, string $nueva): void
{
    if (consola_km_estado($pdo)) {
        throw new RuntimeException('La llave maestra ya esta configurada');
    }

    $iter = CONSOLA_KM_ITER;
    $salt = random_bytes(CONSOLA_KM_SALT_BYTES);
    $dek  = random_bytes(32);

    $kek        = consola_km_kdf($nueva, $salt, $iter);
    $dekCifrada = consola_gcm_cifrar($dek, $kek);

    $sql = 'INSERT INTO public.consola_llave_maestra (kdf_salt, kdf_algo, kdf_ops, kdf_mem, dek_cifrada)
            VALUES (:salt, :algo, :ops, :mem, :dek)';
    $st = $pdo->prepare($sql);
    $st->bindValue(':salt', $salt, PDO::PARAM_LOB);
    $st->bindValue(':algo', CONSOLA_KM_ALGO);
    $st->bindValue(':ops', $iter, PDO::PARAM_INT);
    $st->bindValue(':mem', 0, PDO::PARAM_INT); // PBKDF2 no usa mem; se guarda 0
    $st->bindValue(':dek', $dekCifrada);
    $st->execute();
}

/**
 * Rota la palabra maestra: desenvuelve la DEK con $actual y la re-envuelve
 * con la KEK derivada de $nueva. Las credenciales cifradas NO se tocan.
 * Devuelve false si $actual es incorrecta o no hay llave configurada.
 */
function consola_km_rotar(PDO $pdo, string $actual, string $nueva): bool
{
    $abierta = consola_km_abrir($pdo, $actual);
    if ($abierta['estado'] !== 'ok') {
        return false;
    }
    $dek = $abierta['dek'];

    // Nuevo salt para forzar una KEK nueva desde la nueva palabra.
    $iter = CONSOLA_KM_ITER;
    $salt = random_bytes(CONSOLA_KM_SALT_BYTES);

    $kek        = consola_km_kdf($nueva, $salt, $iter);
    $dekCifrada = consola_gcm_cifrar($dek, $kek);

    $sql = 'UPDATE public.consola_llave_maestra
               SET kdf_salt = :salt, kdf_algo = :algo, kdf_ops = :ops,
                   kdf_mem = :mem, dek_cifrada = :dek';
    $st = $pdo->prepare($sql);
    $st->bindValue(':salt', $salt, PDO::PARAM_LOB);
    $st->bindValue(':algo', CONSOLA_KM_ALGO);
    $st->bindValue(':ops', $iter, PDO::PARAM_INT);
    $st->bindValue(':mem', 0, PDO::PARAM_INT);
    $st->bindValue(':dek', $dekCifrada);
    $st->execute();
    return true;
}

/**
 * Normaliza el salt leido de un campo bytea de PostgreSQL a bytes crudos.
 * PDO_pgsql suele entregar bytea como stream (resource) o como cadena en
 * formato hex "\x..."; aqui se cubre ambos casos.
 */
function consola_km_bytes(mixed $valor): string
{
    if (is_resource($valor)) {
        $valor = stream_get_contents($valor);
    }
    $valor = (string)$valor;
    if (strncmp($valor, '\\x', 2) === 0) {
        return (string)hex2bin(substr($valor, 2));
    }
    return $valor;
}
