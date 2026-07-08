<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos - Listado paginado y filtrable.
//  Cada item incluye 'clientes' (N:N) y 'atributos' (clave-valor) para
//  poder editar desde la propia fila sin reconsultar. Se usan subconsultas
//  con json_agg para no multiplicar filas (evita producto cartesiano).
//  Espejo de endpoints/dominios/listar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Otros', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE o.tipo_producto ILIKE :b OR o.nombre_referencia ILIKE :b OR pr.nombre_proveedor ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.otros_productos o
       JOIN public.proveedores pr ON pr.id = o.proveedor_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT o.id, o.tipo_producto, o.nombre_referencia, o.fecha_registro,
            o.fecha_vencimiento, o.precio_compra, o.precio_venta, o.created_at,
            o.proveedor_id, pr.nombre_proveedor AS proveedor,
            COALESCE((
                SELECT json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                                ORDER BY c.nombre_razon_social)
                  FROM public.otros_productos_clientes oc
                  JOIN public.clientes c ON c.id = oc.cliente_id
                 WHERE oc.producto_id = o.id
            ), '[]') AS clientes,
            COALESCE((
                SELECT json_agg(json_build_object('campo_clave', a.campo_clave, 'valor', a.valor)
                                ORDER BY a.campo_clave)
                  FROM public.otros_productos_atributos a
                 WHERE a.producto_id = o.id
            ), '[]') AS atributos
       FROM public.otros_productos o
       JOIN public.proveedores pr ON pr.id = o.proveedor_id
     $where
     ORDER BY o.nombre_referencia ASC
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
    $row['clientes']  = json_decode($row['clientes'] ?? '[]', true) ?: [];
    $row['atributos'] = json_decode($row['atributos'] ?? '[]', true) ?: [];
}
unset($row);

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
