<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Servidores - Listado paginado y filtrable.
//  GET params: pagina, por_pagina, buscar
//  Respuesta data: { items, total, pagina, por_pagina, total_paginas }
//  Cada item incluye 'clientes' (arreglo {id,nombre} de la relacion N:N
//  vps_clientes) para poder editar desde la propia fila sin volver a la BD.
//  Espejo de endpoints/proveedores/listar.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);          // rango sensato 5..100
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

// Filtro de busqueda opcional (referencia del VPS o nombre del proveedor).
$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE v.referencia_vps ILIKE :b OR pr.nombre_proveedor ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

// Total de registros (para el paginador).
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.vps_servidores v
       JOIN public.proveedores pr ON pr.id = v.proveedor_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

// Pagina de resultados. El hardware, los precios y el tipo se heredan de la
// referencia (JOIN a referencias_vps). Los clientes asociados (N:N) se agregan
// como JSON.
$stmt = $pdo->prepare(
    "SELECT v.id, v.referencia_vps, v.referencia_vps_id, v.label,
            v.fecha_creacion, v.fecha_vencimiento, v.created_at,
            v.proveedor_id, pr.nombre_proveedor AS proveedor,
            r.tipo_servidor,
            r.disco_valor, r.disco_unidad, r.ram_valor, r.ram_unidad,
            r.ancho_banda_valor, r.ancho_banda_unidad,
            r.precio_compra, r.moneda_compra, r.precio_venta, r.moneda_venta,
            COALESCE(
                json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                         ORDER BY c.nombre_razon_social)
                    FILTER (WHERE c.id IS NOT NULL),
                '[]'
            ) AS clientes
       FROM public.vps_servidores v
       JOIN public.proveedores pr ON pr.id = v.proveedor_id
       JOIN public.referencias_vps r ON r.id = v.referencia_vps_id
       LEFT JOIN public.vps_clientes vc ON vc.vps_id = v.id
       LEFT JOIN public.clientes c ON c.id = vc.cliente_id
     $where
     GROUP BY v.id, pr.nombre_proveedor, r.id
     ORDER BY v.referencia_vps ASC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

// json_agg llega como cadena JSON; se decodifica a arreglo real.
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
