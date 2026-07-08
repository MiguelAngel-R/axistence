<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Detalle consolidado (para viewProducto).
//  GET params: id (uuid del hosting)
//  Devuelve: VPS (en generales), clientes (N:N), dominios (N:N),
//  proyectos vinculados y notas (con autor = usuario interno).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Hosting', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT h.id, h.espacio_asignado, h.fecha_compra, h.fecha_renovacion,
            h.precio_compra, h.precio_venta, h.created_at, h.updated_at,
            h.vps_id, v.referencia_vps AS vps
       FROM public.hosting h
       JOIN public.vps_servidores v ON v.id = h.vps_id
      WHERE h.id = :id'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetch();
if (!$hosting) {
    json_error('Hosting no encontrado', 404);
}

$stmt = $pdo->prepare(
    'SELECT c.id, c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion, c.estado
       FROM public.cliente_hostings ch
       JOIN public.clientes c ON c.id = ch.cliente_id
      WHERE ch.hosting_id = :id
      ORDER BY c.nombre_razon_social ASC'
);
$stmt->execute([':id' => $id]);
$clientes = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre_dominio, pr.nombre_proveedor AS proveedor,
            d.fecha_vencimiento
       FROM public.hosting_dominios hd
       JOIN public.dominios d ON d.id = hd.dominio_id
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
      WHERE hd.hosting_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$dominios = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT p.id, p.nombre_proyecto, p.estado, ph.descripcion_uso
       FROM public.proyecto_hostings ph
       JOIN public.proyectos p ON p.id = ph.proyecto_id
      WHERE ph.hosting_id = :id
      ORDER BY p.nombre_proyecto ASC'
);
$stmt->execute([':id' => $id]);
$proyectos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.fecha, u.nombre_completo AS autor
       FROM public.hosting_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.hosting_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

json_ok([
    'hosting'   => $hosting,
    'clientes'  => $clientes,
    'dominios'  => $dominios,
    'proyectos' => $proyectos,
    'notas'     => $notas,
]);
