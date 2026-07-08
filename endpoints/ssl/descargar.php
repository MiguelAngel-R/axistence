<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Descarga segura del paquete de certificados.
//  GET params: id (uuid del certificado)
//  Solo usuarios con permiso 'SSL/ver' pueden descargar; cada descarga
//  se registra en el log de auditoria (DESCARGA_SEGURA). El archivo se
//  entrega por streaming (no es accesible directamente por web).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('SSL', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();
$stmt = $pdo->prepare(
    'SELECT s.archivo_paquete_path, d.nombre_dominio
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
      WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$cert = $stmt->fetch();
if (!$cert) {
    json_error('Certificado SSL no encontrado', 404);
}

$ruta = (string)$cert['archivo_paquete_path'];
// Se usa basename para impedir cualquier salto de directorio.
$abs = UPLOADS_DIR . '/ssl/' . basename($ruta);
if (!preg_match('~^uploads/ssl/[a-f0-9]{32}\.(zip|rar)$~', $ruta) || !is_file($abs)) {
    json_error('El archivo del certificado no esta disponible', 404);
}

// Auditoria de la descarga (antes de enviar el binario).
registrar_auditoria(
    'DESCARGA_SEGURA',
    'SSL',
    'Descargo el paquete de certificados del dominio ' . $cert['nombre_dominio'],
    $id
);

// Nombre de descarga amigable a partir del dominio.
$ext          = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$slugDominio  = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$cert['nombre_dominio']);
$nombreSalida = 'certificado-' . trim($slugDominio, '-') . '.' . $ext;

// Se limpia cualquier salida previa y se envia el archivo.
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $nombreSalida . '"');
header('Content-Length: ' . (string)filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit;
