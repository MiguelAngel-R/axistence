<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Listado paginado y filtrable.
//  GET params: pagina, por_pagina, buscar
//  Cada item incluye 'clientes' (arreglo {id,nombre} de la relacion N:N
//  dominio_clientes) para editar desde la propia fila sin reconsultar.
//  Espejo de endpoints/vps/listar.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Dominios', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

// Filtro de busqueda opcional (nombre del dominio o proveedor).
$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE d.nombre_dominio ILIKE :b OR pr.nombre_proveedor ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.dominios d
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT d.id, d.nombre_dominio, d.fecha_registro, d.fecha_vencimiento,
            d.precio_compra, d.precio_venta, d.created_at,
            d.proveedor_id, pr.nombre_proveedor AS proveedor,
            d.vps_id, v.referencia_vps AS vps, d.vps_externa,
            COALESCE(
                json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                         ORDER BY c.nombre_razon_social)
                    FILTER (WHERE c.id IS NOT NULL),
                '[]'
            ) AS clientes
       FROM public.dominios d
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
       LEFT JOIN public.vps_servidores v ON v.id = d.vps_id
       LEFT JOIN public.dominio_clientes dc ON dc.dominio_id = d.id
       LEFT JOIN public.clientes c ON c.id = dc.cliente_id
     $where
     GROUP BY d.id, pr.nombre_proveedor, v.referencia_vps
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

foreach ($items as &$row) {
    $row['clientes'] = json_decode($row['clientes'] ?? '[]', true) ?: [];
}
unset($row);

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
