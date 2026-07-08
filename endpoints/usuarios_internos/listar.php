<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios internos - Listado paginado y filtrable.
//  GET params: pagina, por_pagina, buscar
//  Respuesta data: { items, total, pagina, por_pagina, total_paginas }
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Usuarios', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);          // rango sensato 5..100
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

// Filtro de busqueda opcional (nombre completo, usuario, correo o rol).
$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE u.nombre_completo ILIKE :b OR u.username ILIKE :b
                 OR u.correo ILIKE :b OR r.nombre ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

// Total de registros (para el paginador).
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.usuarios_internos u
       JOIN public.roles r ON r.id = u.rol_id
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

// Pagina de resultados.
$stmt = $pdo->prepare(
    "SELECT u.id, u.nombres, u.apellidos, u.nombre_completo, u.username,
            u.correo, u.telefono, u.estado, u.last_login, u.created_at,
            u.rol_id, r.nombre AS rol
       FROM public.usuarios_internos u
       JOIN public.roles r ON r.id = u.rol_id
     $where
     ORDER BY u.nombre_completo ASC
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
