<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos - Detalle consolidado (para viewProducto).
//  GET params: id (uuid del producto)
//  Devuelve el producto con: proveedor (en generales), clientes (N:N),
//  atributos (clave-valor) y notas (con autor = usuario interno).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Otros', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT o.id, o.tipo_producto, o.nombre_referencia, o.fecha_registro,
            o.fecha_vencimiento, o.precio_compra, o.precio_venta,
            o.created_at, o.updated_at,
            o.proveedor_id, pr.nombre_proveedor AS proveedor
       FROM public.otros_productos o
       JOIN public.proveedores pr ON pr.id = o.proveedor_id
      WHERE o.id = :id'
);
$stmt->execute([':id' => $id]);
$producto = $stmt->fetch();
if (!$producto) {
    json_error('Producto no encontrado', 404);
}

$stmt = $pdo->prepare(
    'SELECT c.id, c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion, c.estado
       FROM public.otros_productos_clientes oc
       JOIN public.clientes c ON c.id = oc.cliente_id
      WHERE oc.producto_id = :id
      ORDER BY c.nombre_razon_social ASC'
);
$stmt->execute([':id' => $id]);
$clientes = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, campo_clave, valor
       FROM public.otros_productos_atributos
      WHERE producto_id = :id
      ORDER BY campo_clave ASC'
);
$stmt->execute([':id' => $id]);
$atributos = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.fecha, u.nombre_completo AS autor
       FROM public.otros_productos_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.producto_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

json_ok([
    'producto'  => $producto,
    'clientes'  => $clientes,
    'atributos' => $atributos,
    'notas'     => $notas,
]);
