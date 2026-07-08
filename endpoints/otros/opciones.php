<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos - Opciones para los <select> del formulario.
//    - proveedores: proveedor del producto (FK obligatoria)
//    - clientes:    dueños del producto (relacion N:N)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Otros', 'ver');

$pdo = Database::get();

$proveedores = $pdo->query(
    'SELECT id, nombre_proveedor FROM public.proveedores ORDER BY nombre_proveedor ASC'
)->fetchAll();

$clientes = $pdo->query(
    'SELECT id, nombre_razon_social FROM public.clientes ORDER BY nombre_razon_social ASC'
)->fetchAll();

json_ok([
    'proveedores' => $proveedores,
    'clientes'    => $clientes,
]);
