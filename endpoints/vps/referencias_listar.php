<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Referencias de un proveedor (para el combobox del formulario).
//  GET params: proveedor_id (uuid)
//  Respuesta data: [ { id, nombre, disco_valor, disco_unidad, ram_valor,
//                      ram_unidad, ancho_banda_valor, ancho_banda_unidad,
//                      precio_compra, moneda_compra, precio_venta,
//                      moneda_venta, tipo_servidor }, ... ]
//  Se devuelven las specs completas para que, al elegir una referencia, la VPS
//  herede su configuracion y el frontend muestre su resumen.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$proveedor = trim((string)($_GET['proveedor_id'] ?? ''));
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT id, nombre,
            disco_valor, disco_unidad, ram_valor, ram_unidad,
            ancho_banda_valor, ancho_banda_unidad,
            precio_compra, moneda_compra, precio_venta, moneda_venta,
            tipo_servidor
       FROM public.referencias_vps
      WHERE proveedor_id = :p
      ORDER BY nombre ASC'
);
$stmt->execute([':p' => $proveedor]);

json_ok($stmt->fetchAll());
