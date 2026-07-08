<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Opciones para los <select> / multiselect del formulario.
//    - clientes: cliente asociado (opcional, 1)
//    - usuarios: equipo asignado (N:N)
//    - vps:      servidor del proyecto (seleccion unica: 1 servidor)
//    - dominios / hosting: recursos del proyecto (N:N)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Proyectos', 'ver');

$pdo = Database::get();

$clientes = $pdo->query(
    'SELECT id, nombre_razon_social FROM public.clientes ORDER BY nombre_razon_social ASC'
)->fetchAll();

$usuarios = $pdo->query(
    'SELECT id, nombre_completo FROM public.usuarios_internos
      WHERE estado = \'Activo\'
      ORDER BY nombre_completo ASC'
)->fetchAll();

$dominios = $pdo->query(
    'SELECT id, nombre_dominio FROM public.dominios ORDER BY nombre_dominio ASC'
)->fetchAll();

$vps = $pdo->query(
    'SELECT id, referencia_vps FROM public.vps_servidores ORDER BY referencia_vps ASC'
)->fetchAll();

// El hosting no tiene nombre: se identifica por su servidor + espacio.
$hosting = $pdo->query(
    'SELECT h.id, v.referencia_vps, h.espacio_asignado
       FROM public.hosting h
       JOIN public.vps_servidores v ON v.id = h.vps_id
      ORDER BY v.referencia_vps ASC'
)->fetchAll();

json_ok([
    'clientes' => $clientes,
    'usuarios' => $usuarios,
    'dominios' => $dominios,
    'vps'      => $vps,
    'hosting'  => $hosting,
]);
