<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas de correo - Opciones para los <select> del formulario.
//    - dominios: dominio al que pertenece (FK obligatoria)
//    - clientes: cliente dueño (FK obligatoria)
//    - vps:      servidor de correo (FK opcional)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

$pdo = Database::get();

$dominios = $pdo->query(
    'SELECT id, nombre_dominio FROM public.dominios ORDER BY nombre_dominio ASC'
)->fetchAll();

$clientes = $pdo->query(
    'SELECT id, nombre_razon_social FROM public.clientes ORDER BY nombre_razon_social ASC'
)->fetchAll();

$vps = $pdo->query(
    'SELECT id, referencia_vps FROM public.vps_servidores ORDER BY referencia_vps ASC'
)->fetchAll();

json_ok([
    'dominios' => $dominios,
    'clientes' => $clientes,
    'vps'      => $vps,
]);
