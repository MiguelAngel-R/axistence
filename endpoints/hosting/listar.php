<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Listado paginado y filtrable.
//  Cada item incluye 'clientes' (N:N) y 'dominios' (N:N) via subconsultas
//  json_agg (evita producto cartesiano). Espejo de otros/listar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Hosting', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE v.referencia_vps ILIKE :b OR h.espacio_asignado ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.hosting h
       JOIN public.vps_servidores v ON v.id = h.vps_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT h.id, h.espacio_asignado, h.fecha_compra, h.fecha_renovacion,
            h.precio_compra, h.precio_venta, h.created_at,
            h.vps_id, v.referencia_vps AS vps,
            COALESCE((
                SELECT json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                                ORDER BY c.nombre_razon_social)
                  FROM public.cliente_hostings ch
                  JOIN public.clientes c ON c.id = ch.cliente_id
                 WHERE ch.hosting_id = h.id
            ), '[]') AS clientes,
            COALESCE((
                SELECT json_agg(json_build_object('id', d.id, 'nombre', d.nombre_dominio)
                                ORDER BY d.nombre_dominio)
                  FROM public.hosting_dominios hd
                  JOIN public.dominios d ON d.id = hd.dominio_id
                 WHERE hd.hosting_id = h.id
            ), '[]') AS dominios
       FROM public.hosting h
       JOIN public.vps_servidores v ON v.id = h.vps_id
     $where
     ORDER BY v.referencia_vps ASC, h.fecha_renovacion ASC
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
    $row['dominios'] = json_decode($row['dominios'] ?? '[]', true) ?: [];
}
unset($row);

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
