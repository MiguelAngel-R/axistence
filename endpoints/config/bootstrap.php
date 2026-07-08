<?php
declare(strict_types=1);

// =====================================================================
//  Bootstrap comun a todos los endpoints.
//  Incluir al inicio de cada endpoint:  require __DIR__ . '/../config/bootstrap.php';
//  Se encarga de: config, conexion, helpers, CORS, sesion y manejo de errores.
// =====================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/audit.php';
require_once __DIR__ . '/../helpers/passwords.php';

// --- Errores ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');

// --- CORS ------------------------------------------------------------
// El front y los endpoints suelen compartir origen; si el front corre en
// otro host/puerto, se refleja el Origin para permitir cookies de sesion.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Vary: Origin');

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Sesion ----------------------------------------------------------
iniciar_sesion_axistence();

// --- Excepciones no capturadas -> JSON -------------------------------
set_exception_handler(function (Throwable $e): void {
    error_log('[AXISTENCE] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor';
    json_error($msg, 500);
});
