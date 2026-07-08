<?php
declare(strict_types=1);

// =====================================================================
//  Proveedores - Listado paginado y filtrable.
//  GET params: pagina, por_pagina, buscar
//  Respuesta data: { items, total, pagina, por_pagina, total_paginas }
//  Cada item incluye 'tipos' (arreglo de tipos de producto que ofrece,
//  relacion M:N proveedor_tipos_producto) para poder editar desde la
//  propia fila sin volver a la BD.
//  Espejo de endpoints/clientes/listar.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Proveedores', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);          // rango sensato 5..100
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

// Filtro de busqueda opcional (nombre del proveedor o sitio web).
$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE p.nombre_proveedor ILIKE :b OR p.sitio_web ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

// Total de registros (para el paginador). Solo filtra por columnas de
// la tabla base, por lo que no necesita el JOIN con los tipos.
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.proveedores p
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

// Pagina de resultados. Los tipos de producto se agregan como JSON para
// devolverlos ya como arreglo (relacion M:N).
$stmt = $pdo->prepare(
    "SELECT p.id, p.nombre_proveedor, p.sitio_web, p.created_at,
            COALESCE(
                json_agg(pt.tipo_producto ORDER BY pt.tipo_producto)
                    FILTER (WHERE pt.tipo_producto IS NOT NULL),
                '[]'
            ) AS tipos
       FROM public.proveedores p
       LEFT JOIN public.proveedor_tipos_producto pt ON pt.proveedor_id = p.id
     $where
     GROUP BY p.id
     ORDER BY p.nombre_proveedor ASC
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
    $row['tipos'] = json_decode($row['tipos'] ?? '[]', true) ?: [];
}
unset($row);

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
