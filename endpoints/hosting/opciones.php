<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Opciones para los <select> del formulario.
//    - vps:      servidor donde se aloja (FK obligatoria)
//    - clientes: clientes a los que se vendio (N:N)
//    - dominios: dominios alojados (N:N)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Hosting', 'ver');

$pdo = Database::get();

$vps = $pdo->query(
    'SELECT id, referencia_vps FROM public.vps_servidores ORDER BY referencia_vps ASC'
)->fetchAll();

$clientes = $pdo->query(
    'SELECT id, nombre_razon_social FROM public.clientes ORDER BY nombre_razon_social ASC'
)->fetchAll();

$dominios = $pdo->query(
    'SELECT id, nombre_dominio FROM public.dominios ORDER BY nombre_dominio ASC'
)->fetchAll();

json_ok([
    'vps'      => $vps,
    'clientes' => $clientes,
    'dominios' => $dominios,
]);
