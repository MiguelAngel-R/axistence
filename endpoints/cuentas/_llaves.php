<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas / 2FA - Llaves de recuperacion (persistencia en archivos).
//  Cada metodo 2FA puede tener una lista de llaves de recuperacion. Se
//  guardan en un .txt (una llave por linea) dentro de:
//     uploads/llaves_2fa/<cuenta_id>/<2fa_id>/llaves.txt
//  (una carpeta por cuenta y, dentro, una carpeta por cada 2FA). En la BD
//  solo se guarda la ruta relativa. Los archivos NO son accesibles por web
//  directamente (uploads/.htaccess); se consultan via endpoint con sesion.
// =====================================================================

const LLAVES_2FA_BASE   = 'uploads/llaves_2fa';
const LLAVES_2FA_ARCH   = 'llaves.txt';
const LLAVES_2FA_MAX    = 50;    // maximo de llaves por 2FA
const LLAVES_2FA_MAXLEN = 255;   // longitud maxima de una llave
const UUID_RE_LLAVES    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// Normaliza la entrada de llaves (arreglo o texto con saltos de linea) a un
// arreglo limpio: sin vacios, sin duplicados, recortadas y acotadas.
function normalizar_llaves_2fa($entrada): array
{
    if (is_string($entrada)) {
        $entrada = preg_split('/\r\n|\r|\n/', $entrada) ?: [];
    }
    if (!is_array($entrada)) {
        return [];
    }
    $limpias = [];
    foreach ($entrada as $llave) {
        $l = trim((string)$llave);
        if ($l === '') { continue; }
        if (mb_strlen($l) > LLAVES_2FA_MAXLEN) {
            $l = mb_substr($l, 0, LLAVES_2FA_MAXLEN);
        }
        $limpias[] = $l;
    }
    // Sin duplicados, respetando el orden de aparicion, y acotado al maximo.
    $limpias = array_values(array_unique($limpias));
    return array_slice($limpias, 0, LLAVES_2FA_MAX);
}

// Guarda las llaves de un 2FA en su archivo .txt y devuelve la ruta relativa
// (uploads/...). Si no hay llaves, no crea nada y devuelve null. Lanza
// RuntimeException si no puede escribir.
function guardar_llaves_2fa(string $cuentaId, string $faId, array $llaves): ?string
{
    if (!preg_match(UUID_RE_LLAVES, $cuentaId) || !preg_match(UUID_RE_LLAVES, $faId)) {
        throw new RuntimeException('Identificadores invalidos para guardar llaves 2FA');
    }
    if (!$llaves) {
        return null;
    }
    $dir = UPLOADS_DIR . '/llaves_2fa/' . $cuentaId . '/' . $faId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo preparar la carpeta de llaves 2FA');
    }
    $destino = $dir . '/' . LLAVES_2FA_ARCH;
    // Una llave por linea. Salto de linea final para prolijidad.
    $contenido = implode("\n", $llaves) . "\n";
    if (file_put_contents($destino, $contenido, LOCK_EX) === false) {
        throw new RuntimeException('No se pudieron guardar las llaves 2FA');
    }
    @chmod($destino, 0640);

    return LLAVES_2FA_BASE . '/' . $cuentaId . '/' . $faId . '/' . LLAVES_2FA_ARCH;
}

// Lee las llaves de un 2FA desde su ruta relativa almacenada en la BD.
// Devuelve un arreglo (vacio si no hay ruta o el archivo no existe).
function leer_llaves_2fa(?string $rutaRelativa): array
{
    if ($rutaRelativa === null || $rutaRelativa === '') {
        return [];
    }
    // Solo se acepta la estructura esperada (evita path traversal).
    $patron = '#^' . preg_quote(LLAVES_2FA_BASE, '#')
            . '/[0-9a-f-]{36}/[0-9a-f-]{36}/' . preg_quote(LLAVES_2FA_ARCH, '#') . '$#i';
    if (!preg_match($patron, $rutaRelativa)) {
        return [];
    }
    $abs = UPLOADS_DIR . '/' . substr($rutaRelativa, strlen('uploads/'));
    if (!is_file($abs)) {
        return [];
    }
    $lineas = file($abs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $lineas === false ? [] : array_values(array_filter(array_map('trim', $lineas), static fn($l) => $l !== ''));
}
