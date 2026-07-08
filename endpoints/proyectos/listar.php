<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Listado paginado y filtrable.
//  Cada item incluye el cliente (opcional) y los recursos N:N (equipo,
//  dominios, vps, ssl, hosting) via subconsultas json_agg para poder
//  reeditar desde la propia fila sin volver a consultar la BD.
//  Espejo de endpoints/hosting/listar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Proyectos', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE p.nombre_proyecto ILIKE :b OR c.nombre_razon_social ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.proyectos p
       LEFT JOIN public.clientes c ON c.id = p.cliente_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

$stmt = $pdo->prepare(
    "SELECT p.id, p.nombre_proyecto, p.descripcion, p.estado,
            p.fecha_inicio, p.fecha_entrega_estimada, p.created_at,
            p.cliente_id, c.nombre_razon_social AS cliente,
            COALESCE((
                SELECT json_agg(json_build_object('id', u.id, 'nombre', u.nombre_completo)
                                ORDER BY u.nombre_completo)
                  FROM public.proyecto_equipo pe
                  JOIN public.usuarios_internos u ON u.id = pe.usuario_id
                 WHERE pe.proyecto_id = p.id
            ), '[]') AS equipo,
            COALESCE((
                SELECT json_agg(json_build_object('id', d.id, 'nombre', d.nombre_dominio)
                                ORDER BY d.nombre_dominio)
                  FROM public.proyecto_dominios pd
                  JOIN public.dominios d ON d.id = pd.dominio_id
                 WHERE pd.proyecto_id = p.id
            ), '[]') AS dominios,
            COALESCE((
                SELECT json_agg(json_build_object('id', v.id, 'nombre', v.referencia_vps)
                                ORDER BY v.referencia_vps)
                  FROM public.proyecto_vps pv
                  JOIN public.vps_servidores v ON v.id = pv.vps_id
                 WHERE pv.proyecto_id = p.id
            ), '[]') AS vps,
            COALESCE((
                SELECT json_agg(json_build_object('id', h.id, 'nombre', v.referencia_vps)
                                ORDER BY v.referencia_vps)
                  FROM public.proyecto_hostings ph
                  JOIN public.hosting h ON h.id = ph.hosting_id
                  JOIN public.vps_servidores v ON v.id = h.vps_id
                 WHERE ph.proyecto_id = p.id
            ), '[]') AS hosting
       FROM public.proyectos p
       LEFT JOIN public.clientes c ON c.id = p.cliente_id
     $where
     ORDER BY p.fecha_inicio DESC, p.nombre_proyecto ASC
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
    foreach (['equipo', 'dominios', 'vps', 'hosting'] as $rel) {
        $row[$rel] = json_decode($row[$rel] ?? '[]', true) ?: [];
    }
}
unset($row);

json_ok([
    'items'         => $items,
    'total'         => $total,
    'pagina'        => $pagina,
    'por_pagina'    => $porPagina,
    'total_paginas' => $totalPaginas,
]);
