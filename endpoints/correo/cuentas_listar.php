<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Cuentas (buzones) de una LICENCIA.
//  GET params: licencia_id (uuid de correo_licencias)
//  Devuelve la licencia (con su dominio y cupo), la lista de cuentas ya
//  creadas y cuantas quedan disponibles. Alimenta el drill-down del tab
//  "Licencias" en viewProducto.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$licenciaId = trim((string)($_GET['licencia_id'] ?? ''));
if (!preg_match(UUID_RE, $licenciaId)) {
    json_error('Identificador de licencia no valido', 422);
}

$pdo = Database::get();

// Licencia + dominio de la relacion (el correo se arma con este dominio).
$stmt = $pdo->prepare(
    'SELECT l.id, l.tipo_licencia, l.cantidad_cuentas, l.cuenta_correo_id,
            cc.dominio_id, d.nombre_dominio AS dominio
       FROM public.correo_licencias l
       JOIN public.cuentas_correo cc ON cc.id = l.cuenta_correo_id
       JOIN public.dominios d        ON d.id  = cc.dominio_id
      WHERE l.id = :id'
);
$stmt->execute([':id' => $licenciaId]);
$lic = $stmt->fetch();
if (!$lic) {
    json_error('Licencia no encontrada', 404);
}

// Cuentas ya creadas de esta licencia.
$stmt = $pdo->prepare(
    'SELECT id, nombre, apellidos, usuario_correo, correo, clave, tipo_cuenta, created_at
       FROM public.correo_licencia_cuentas
      WHERE licencia_id = :id
      ORDER BY created_at ASC'
);
$stmt->execute([':id' => $licenciaId]);
$cuentas = $stmt->fetchAll();

$usadas = count($cuentas);
$total  = (int)$lic['cantidad_cuentas'];

json_ok([
    'licencia'    => $lic,
    'cuentas'     => $cuentas,
    'usadas'      => $usadas,
    'disponibles' => max(0, $total - $usadas),
]);
