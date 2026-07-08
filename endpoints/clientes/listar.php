<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Listado paginado y filtrable.
//  GET params: pagina, por_pagina, buscar
//  Respuesta data: { items, total, pagina, por_pagina, total_paginas }
//  Espejo de endpoints/usuarios_internos/listar.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Clientes', 'ver');

$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = (int)($_GET['por_pagina'] ?? 20);
$porPagina = min(max($porPagina, 5), 100);          // rango sensato 5..100
$buscar    = trim((string)($_GET['buscar'] ?? ''));
$offset    = ($pagina - 1) * $porPagina;

$pdo = Database::get();

// Filtro de busqueda opcional (nombre visible, identificacion o correo).
$where  = '';
$params = [];
if ($buscar !== '') {
    $where = 'WHERE c.nombre_razon_social ILIKE :b
                 OR c.numero_identificacion ILIKE :b
                 OR c.email_general ILIKE :b';
    $params[':b'] = '%' . $buscar . '%';
}

// Total de registros (para el paginador).
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM public.clientes c
     $where"
);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPaginas = $total > 0 ? (int)ceil($total / $porPagina) : 0;

// Pagina de resultados. Se traen todos los campos editables para que la
// edicion se resuelva desde la propia fila (data-registro), sin volver a la BD.
$stmt = $pdo->prepare(
    "SELECT c.id, c.tipo_cliente, c.razon_social, c.nombres, c.apellidos,
            c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion,
            c.email_general, c.telefono_principal, c.direccion,
            c.estado, c.created_at
       FROM public.clientes c
     $where
     ORDER BY c.nombre_razon_social ASC
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
