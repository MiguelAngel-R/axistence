<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas de correo - Listado paginado y filtrable.
//  No tiene relaciones N:N (dominio y cliente son 1:1), por lo que basta
//  con JOINs. Espejo de endpoints/dominios/listar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE d.nombre_dominio ILIKE :b OR c.nombre_razon_social ILIKE :b OR cc.servidor_correo_externo ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.cuentas_correo cc
       JOIN public.dominios d ON d.id = cc.dominio_id
       JOIN public.clientes c ON c.id = cc.cliente_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT cc.id, cc.cantidad_cuentas, cc.tipo_licencia,
            cc.precio_costo_cuenta, cc.precio_venta_cuenta,
            cc.fecha_registro, cc.fecha_vencimiento, cc.created_at,
            cc.dominio_id, d.nombre_dominio AS dominio,
            cc.cliente_id, c.nombre_razon_social AS cliente,
            cc.servidor_correo_vps_id, v.referencia_vps AS servidor_vps,
            cc.mx_registro_id, cc.servidor_correo_externo
       FROM public.cuentas_correo cc
       JOIN public.dominios d ON d.id = cc.dominio_id
       JOIN public.clientes c ON c.id = cc.cliente_id
       LEFT JOIN public.vps_servidores v ON v.id = cc.servidor_correo_vps_id
     $where
     ORDER BY d.nombre_dominio ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
