<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Opciones para los <select> del formulario.
//    - proveedores: proveedor donde esta registrado (FK obligatoria)
//    - vps:         servidor al que apunta (FK opcional)
//    - clientes:    dueños del dominio (relacion N:N)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Dominios', 'ver');

$pdo = Database::get();

$proveedores = $pdo->query(
    'SELECT id, nombre_proveedor FROM public.proveedores ORDER BY nombre_proveedor ASC'
)->fetchAll();

// Se incluye proveedor_id y label para poder filtrar los servidores por el
// proveedor elegido en el formulario (y mostrar la etiqueta si la tiene).
$vps = $pdo->query(
    'SELECT id, referencia_vps, label, proveedor_id
       FROM public.vps_servidores ORDER BY referencia_vps ASC'
)->fetchAll();

$clientes = $pdo->query(
    'SELECT id, nombre_razon_social FROM public.clientes ORDER BY nombre_razon_social ASC'
)->fetchAll();

// Usuarios internos activos: para "quien tiene el 2FA" de una cuenta.
$usuarios = $pdo->query(
    "SELECT id, nombre_completo FROM public.usuarios_internos
      WHERE estado = 'Activo' ORDER BY nombre_completo ASC"
)->fetchAll();

json_ok([
    'proveedores' => $proveedores,
    'vps'         => $vps,
    'clientes'    => $clientes,
    'usuarios'    => $usuarios,
]);
