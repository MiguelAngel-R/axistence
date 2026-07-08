<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos (generico/extensible) - Crear.
//  POST JSON: { tipo_producto, nombre_referencia, proveedor_id,
//               fecha_registro, fecha_vencimiento?,
//               precio_compra?, precio_venta?,
//               clientes?: uuid[], atributos?: [{campo_clave, valor}] }
//  'tipo_producto' es texto libre: permite registrar un nuevo tipo de
//  producto sin cambiar el codigo (criterio de aceptacion #22).
//  Inserta producto + clientes (N:N) + atributos (clave-valor) en una
//  transaccion. Espejo de endpoints/dominios/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Otros', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

// Normaliza los atributos clave-valor: descarta claves vacias y recorta.
// Devuelve [ [campo_clave, valor], ... ] o lanza json_error si algo no valida.
function normalizar_atributos($in): array
{
    if (!is_array($in)) { return []; }
    $salida = [];
    foreach ($in as $a) {
        if (!is_array($a)) { continue; }
        $clave = trim((string)($a['campo_clave'] ?? ''));
        $valor = trim((string)($a['valor'] ?? ''));
        if ($clave === '') { continue; } // se ignoran filas sin clave
        if (mb_strlen($clave) > 100) {
            json_error('Un nombre de campo adicional es demasiado largo (maximo 100 caracteres)', 422);
        }
        $salida[] = ['campo_clave' => $clave, 'valor' => $valor];
    }
    return $salida;
}

$in         = body_json();
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

// --- Validaciones ---------------------------------------------------
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

$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// --- Insercion (producto + clientes + atributos) en transaccion -----
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.otros_productos
            (tipo_producto, nombre_referencia, proveedor_id,
             fecha_registro, fecha_vencimiento, precio_compra, precio_venta)
         VALUES (:t, :r, :prov, :freg, :fven, :pc, :pv)
         RETURNING id'
    );
    $stmt->execute([
        ':t'   => $tipo,
        ':r'   => $referencia,
        ':prov' => $proveedor,
        ':freg' => $fReg,
        ':fven' => $fVenBd,
        ':pc'  => $precioCbd,
        ':pv'  => $precioVbd,
    ]);
    $id = $stmt->fetchColumn();

    if ($clientes) {
        $insCli = $pdo->prepare(
            'INSERT INTO public.otros_productos_clientes (producto_id, cliente_id) VALUES (:p, :c)'
        );
        foreach ($clientes as $cid) {
            $insCli->execute([':p' => $id, ':c' => $cid]);
        }
    }

    if ($atributos) {
        $insAtr = $pdo->prepare(
            'INSERT INTO public.otros_productos_atributos (producto_id, campo_clave, valor)
             VALUES (:p, :k, :v)'
        );
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
    'CREAR',
    'Otros',
    'Creo el producto ' . $referencia . ' (' . $tipo . ')',
    $id,
    null,
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

json_ok(['id' => $id], 'Producto creado correctamente');
