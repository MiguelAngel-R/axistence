<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Subida segura del paquete de certificados.
//  POST multipart/form-data con el campo de archivo 'archivo'.
//  Valida tipo (zip/rar) y tamaño, lo guarda en uploads/ssl/ con un
//  nombre ALEATORIO (evita colisiones, adivinacion y path traversal) y
//  devuelve la ruta relativa para asociarla luego al certificado.
//  El archivo NO es accesible directamente por web (ver uploads/.htaccess);
//  se descarga con endpoints/ssl/descargar.php (con permisos + auditoria).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');

// Requiere sesion y permiso de crear o editar SSL.
requiere_login();
if (!tiene_permiso('SSL', 'crear') && !tiene_permiso('SSL', 'editar')) {
    json_error('No tienes permiso para subir certificados', 403);
}

const SSL_EXTENSIONES = ['zip', 'rar'];
const SSL_MAX_BYTES   = 20971520; // 20 MB

if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
    json_error('No se recibio ningun archivo', 422);
}

$archivo = $_FILES['archivo'];

if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $codigo = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) {
        json_error('El archivo excede el tamaño permitido', 422);
    }
    json_error('No se pudo recibir el archivo', 422);
}

if (($archivo['size'] ?? 0) <= 0) {
    json_error('El archivo esta vacio', 422);
}
if ($archivo['size'] > SSL_MAX_BYTES) {
    json_error('El archivo es demasiado grande (maximo 20 MB)', 422);
}
if (!is_uploaded_file($archivo['tmp_name'])) {
    json_error('Origen de archivo no valido', 422);
}

$ext = strtolower(pathinfo((string)$archivo['name'], PATHINFO_EXTENSION));
if (!in_array($ext, SSL_EXTENSIONES, true)) {
    json_error('Solo se permiten paquetes comprimidos .zip o .rar', 422);
}

// Carpeta destino: uploads/ssl/
$dir = UPLOADS_DIR . '/ssl';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_error('No se pudo preparar el almacenamiento', 500);
}

// Nombre aleatorio, no adivinable.
$nombre  = bin2hex(random_bytes(16)) . '.' . $ext;
$destino = $dir . '/' . $nombre;

if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
    json_error('No se pudo guardar el archivo', 500);
}
@chmod($destino, 0640);

json_ok([
    'ruta'            => 'uploads/ssl/' . $nombre,
    'nombre_original' => basename((string)$archivo['name']),
], 'Archivo subido correctamente');
