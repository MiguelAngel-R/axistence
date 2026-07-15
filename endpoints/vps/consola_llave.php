<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Llave maestra (frontend, con sesion).
//
//  GET  -> requiere_permiso('VPS','ver')   -> { configurada: bool }
//          Para que la UI sepa si mostrar "configurar" o "cambiar".
//  POST -> requiere_permiso('VPS','editar') -> configura o rota la llave.
//          body { clave_maestra_nueva, clave_maestra_actual? }
//            - sin configurar: solo clave_maestra_nueva -> se crea.
//            - ya configurada: exige clave_maestra_actual -> se rota.
//
//  La palabra maestra NUNCA se guarda ni se audita: solo se persiste la DEK
//  envuelta y el salt del KDF (ver consola_llave_maestra.php).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_cifrado.php';
require_once __DIR__ . '/../helpers/consola_llave_maestra.php';

const CLAVE_MAESTRA_MIN = 8;

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = Database::get();

// ---------------------------------------------------------------------
//  GET: estado (¿configurada?)
// ---------------------------------------------------------------------
if ($metodo === 'GET') {
    requiere_permiso('VPS', 'ver');
    json_ok(['configurada' => consola_km_estado($pdo)]);
}

// ---------------------------------------------------------------------
//  POST: configurar (primera vez) o rotar (cambiar la palabra)
// ---------------------------------------------------------------------
solo_metodo('POST');
requiere_permiso('VPS', 'editar');

$in     = body_json();
$nueva  = (string)($in['clave_maestra_nueva'] ?? '');
$actual = (string)($in['clave_maestra_actual'] ?? '');

if (strlen($nueva) < CLAVE_MAESTRA_MIN) {
    json_error('La palabra maestra debe tener al menos ' . CLAVE_MAESTRA_MIN . ' caracteres', 422);
}

$yaConfigurada = consola_km_estado($pdo);

if (!$yaConfigurada) {
    // Primera configuracion.
    consola_km_configurar($pdo, $nueva);
    registrar_auditoria('CREAR', 'VPS', 'Configuro la llave maestra de la consola SSH');
    json_ok(['configurada' => true], 'Llave maestra configurada');
}

// Rotacion: exige la palabra actual.
if ($actual === '') {
    json_error('Debes indicar la palabra maestra actual para cambiarla', 422);
}
if (!consola_km_rotar($pdo, $actual, $nueva)) {
    json_error('La palabra maestra actual es incorrecta', 401);
}
registrar_auditoria('MODIFICAR', 'VPS', 'Roto la llave maestra de la consola SSH');
json_ok(['configurada' => true], 'Llave maestra actualizada');
