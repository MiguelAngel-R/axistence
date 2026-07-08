<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Opciones para los modales "Agregar ..." del detalle.
//  GET params: vps_id (uuid)
//  Devuelve las listas que necesitan los formularios relacionales:
//    - proveedores      : para crear dominios / certificados
//    - dominios         : todos (para el certificado SSL nuevo)
//    - dominios_vps     : dominios apuntados a ESTE VPS (para el virtual host)
//    - certificados_vps : certificados de ESTE VPS (para el virtual host)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$vpsId = trim((string)($_GET['vps_id'] ?? ''));
if (!preg_match(UUID_RE, $vpsId)) {
    json_error('Identificador de VPS no valido', 422);
}

$pdo = Database::get();

$proveedores = $pdo->query(
    'SELECT id, nombre_proveedor FROM public.proveedores ORDER BY nombre_proveedor ASC'
)->fetchAll();

$dominios = $pdo->query(
    'SELECT id, nombre_dominio FROM public.dominios ORDER BY nombre_dominio ASC'
)->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, nombre_dominio FROM public.dominios WHERE vps_id = :id ORDER BY nombre_dominio ASC'
);
$stmt->execute([':id' => $vpsId]);
$dominiosVps = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT s.id, d.nombre_dominio
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
      WHERE s.vps_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $vpsId]);
$certificadosVps = $stmt->fetchAll();

json_ok([
    'proveedores'      => $proveedores,
    'dominios'         => $dominios,
    'dominios_vps'     => $dominiosVps,
    'certificados_vps' => $certificadosVps,
]);
