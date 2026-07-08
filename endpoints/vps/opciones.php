<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Servidores - Opciones para los <select> del formulario.
//  Devuelve las listas necesarias para crear/editar un VPS:
//    - proveedores: para el proveedor asociado (FK obligatoria)
//    - clientes:    para la asociacion N:N (dedicado/compartido)
//  Espejo funcional de endpoints/usuarios_internos/roles.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

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
