<?php
declare(strict_types=1);

// =====================================================================
//  Helpers de contrasena.
//  Por requerimiento del proyecto, las contrasenas se almacenan con SHA1.
//  El login, sin embargo, verifica de forma COMPATIBLE para no invalidar
//  a los usuarios creados antes de este cambio (hash bcrypt de password_hash):
//    - Si el hash almacenado tiene forma SHA1 (40 hex) -> compara SHA1.
//    - En caso contrario (bcrypt/argon heredado)       -> password_verify.
//
//  NOTA DE SEGURIDAD: SHA1 es un algoritmo debil y sin sal; se implementa
//  aqui unicamente porque el proyecto lo exige. Toda la logica queda
//  centralizada en estas dos funciones para poder endurecerla en un solo
//  lugar el dia que se decida migrar a un hash mas fuerte.
// =====================================================================

// Genera el hash de almacenamiento de una contrasena (SHA1, 40 hex).
function hash_clave(string $clave): string
{
    return sha1($clave);
}

// Verifica una contrasena en claro contra el hash almacenado.
// Soporta el formato SHA1 actual y los hashes bcrypt heredados.
function verificar_clave(string $clave, string $hash): bool
{
    // Formato SHA1: exactamente 40 caracteres hexadecimales.
    if (preg_match('/^[0-9a-f]{40}$/i', $hash) === 1) {
        return hash_equals(strtolower($hash), sha1($clave));
    }
    // Compatibilidad con usuarios previos (password_hash / bcrypt).
    return password_verify($clave, $hash);
}
