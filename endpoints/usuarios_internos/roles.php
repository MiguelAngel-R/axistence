<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios internos - Roles disponibles (para el select del formulario).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Usuarios', 'ver');

$pdo  = Database::get();
$rows = $pdo->query('SELECT id, nombre FROM public.roles ORDER BY nombre ASC')->fetchAll();

json_ok($rows);
