<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos - Actualizar.
//  POST JSON: { id, tipo_producto, nombre_referencia, proveedor_id,
//               fecha_registro, fecha_vencimiento?,
//               precio_compra?, precio_venta?,
//               clientes?: uuid[], atributos?: [{campo_clave, valor}] }
//  Actualiza y resincroniza clientes (N:N) y atributos (clave-valor) en
//  una transaccion. Espejo de endpoints/dominios/actualizar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Otros', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

function normalizar_atributos($in): array
{
    if (!is_array($in)) { return []; }
    $salida = [];
    foreach ($in as $a) {
        if (!is_array($a)) { continue; }
        $clave = trim((string)($a['campo_clave'] ?? ''));
        $valor = trim((string)($a['valor'] ?? ''));
        if ($clave === '') { continue; }
        if (mb_strlen($clave) > 100) {
            json_error('Un nombre de campo adicional es demasiado largo (maximo 100 caracteres)', 422);
        }
        $salida[] = ['campo_clave' => $clave, 'valor' => $valor];
    }
    return $salida;
}

$in         = body_json();
$id         = trim((string)($in['id'] ?? ''));
$tipo       = trim((string)($in['tipo_producto'] ?? ''));
$referencia = trim((string)($in['nombre_referencia'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;
$clientes   = $in['clientes'] ?? [];
$atributos  = normalizar_atributos($in['atributos'] ?? []);

if (!is_array($clientes)) { $clientes = []; }
$clientes = array_values(array_unique(array_filter(array_map(
    static fn($c) => trim((string)$c), $clientes
), static fn($c) => $c !== '')));
sort($clientes);

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
$faltan = campos_faltantes(
    ['tipo_producto' => $tipo, 'nombre_referencia' => $referencia,
     'proveedor_id' => $proveedor, 'fecha_registro' => $fReg],
    ['tipo_producto', 'nombre_referencia', 'proveedor_id', 'fecha_registro']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($tipo) > 100) {
    json_error('El tipo de producto es demasiado largo (maximo 100 caracteres)', 422);
}
if (mb_strlen($referencia) > 150) {
    json_error('La referencia es demasiado larga (maximo 150 caracteres)', 422);
}
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if (!fecha_valida($fReg)) {
    json_error('La fecha de registro no es valida', 422);
}
if ($fVen !== '' && !fecha_valida($fVen)) {
    json_error('La fecha de vencimiento no es valida', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0) {
    json_error('El precio de compra no es valido', 422);
}
if (!is_numeric($precioV) || (float)$precioV < 0) {
    json_error('El precio de venta no es valido', 422);
}
foreach ($clientes as $cid) {
    if (!preg_match(UUID_RE, $cid)) {
        json_error('Alguno de los clientes seleccionados no es valido', 422);
    }
}

$fVenBd    = $fVen !== '' ? $fVen : null;
$precioCbd = number_format((float)$precioC, 2, '.', '');
$precioVbd = number_format((float)$precioV, 2, '.', '');

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT tipo_producto, nombre_referencia, proveedor_id, fecha_registro,
            fecha_vencimiento, precio_compra, precio_venta
       FROM public.otros_productos WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Producto no encontrado', 404);
}

$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

$stmt = $pdo->prepare('SELECT cliente_id FROM public.otros_productos_clientes WHERE producto_id = :id ORDER BY cliente_id');
$stmt->execute([':id' => $id]);
$clientesActuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Actualizacion (producto + resync clientes + atributos) ---------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.otros_productos
            SET tipo_producto = :t, nombre_referencia = :r, proveedor_id = :prov,
                fecha_registro = :freg, fecha_vencimiento = :fven,
                precio_compra = :pc, precio_venta = :pv
          WHERE id = :id'
    );
    $stmt->execute([
        ':t'    => $tipo,
        ':r'    => $referencia,
        ':prov' => $proveedor,
        ':freg' => $fReg,
        ':fven' => $fVenBd,
        ':pc'   => $precioCbd,
        ':pv'   => $precioVbd,
        ':id'   => $id,
    ]);

    $pdo->prepare('DELETE FROM public.otros_productos_clientes WHERE producto_id = :id')->execute([':id' => $id]);
    if ($clientes) {
        $insCli = $pdo->prepare('INSERT INTO public.otros_productos_clientes (producto_id, cliente_id) VALUES (:p, :c)');
        foreach ($clientes as $cid) {
            $insCli->execute([':p' => $id, ':c' => $cid]);
        }
    }

    $pdo->prepare('DELETE FROM public.otros_productos_atributos WHERE producto_id = :id')->execute([':id' => $id]);
    if ($atributos) {
        $insAtr = $pdo->prepare('INSERT INTO public.otros_productos_atributos (producto_id, campo_clave, valor) VALUES (:p, :k, :v)');
        foreach ($atributos as $a) {
            $insAtr->execute([':p' => $id, ':k' => $a['campo_clave'], ':v' => $a['valor']]);
        }
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('Alguno de los clientes seleccionados no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Otros',
    'Actualizo el producto ' . $referencia . ' (' . $tipo . ')',
    $id,
    [
        'tipo_producto'     => $actual['tipo_producto'],
        'nombre_referencia' => $actual['nombre_referencia'],
        'proveedor_id'      => $actual['proveedor_id'],
        'fecha_registro'    => $actual['fecha_registro'],
        'fecha_vencimiento' => $actual['fecha_vencimiento'],
        'precio_compra'     => $actual['precio_compra'],
        'precio_venta'      => $actual['precio_venta'],
        'clientes'          => $clientesActuales,
    ],
    [
        'tipo_producto'     => $tipo,
        'nombre_referencia' => $referencia,
        'proveedor_id'      => $proveedor,
        'fecha_registro'    => $fReg,
        'fecha_vencimiento' => $fVenBd,
        'precio_compra'     => $precioCbd,
        'precio_venta'      => $precioVbd,
        'clientes'          => $clientes,
        'atributos'         => $atributos,
    ]
);

json_ok(['id' => $id], 'Producto actualizado correctamente');
