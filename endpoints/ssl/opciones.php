<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Opciones para los <select> del formulario.
//    - dominios:    dominio que protege el certificado (FK obligatoria)
//    - proveedores: entidad certificadora / proveedor (FK obligatoria)
//    - vps:         servidor donde esta configurado (FK opcional)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('SSL', 'ver');

$pdo = Database::get();

$dominios = $pdo->query(
    'SELECT id, nombre_dominio FROM public.dominios ORDER BY nombre_dominio ASC'
)->fetchAll();

$proveedores = $pdo->query(
    'SELECT id, nombre_proveedor FROM public.proveedores ORDER BY nombre_proveedor ASC'
)->fetchAll();

$vps = $pdo->query(
    'SELECT id, referencia_vps FROM public.vps_servidores ORDER BY referencia_vps ASC'
)->fetchAll();

json_ok([
    'dominios'    => $dominios,
    'proveedores' => $proveedores,
    'vps'         => $vps,
]);
