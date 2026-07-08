<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Listado paginado y filtrable.
//  Sin relaciones N:N: basta con JOINs. Espejo de endpoints/correo/listar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('SSL', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE d.nombre_dominio ILIKE :b OR pr.nombre_proveedor ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
       JOIN public.proveedores pr ON pr.id = s.proveedor_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT s.id, s.ruta_almacenamiento, s.archivo_paquete_path,
            s.fecha_registro, s.fecha_vencimiento, s.precio_compra, s.precio_venta,
            s.created_at,
            s.dominio_id, d.nombre_dominio AS dominio,
            s.proveedor_id, pr.nombre_proveedor AS proveedor,
            s.vps_id, v.referencia_vps AS vps, s.vps_externa
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
       JOIN public.proveedores pr ON pr.id = s.proveedor_id
       LEFT JOIN public.vps_servidores v ON v.id = s.vps_id
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
