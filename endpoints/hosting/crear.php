<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Crear.
//  POST JSON: { vps_id, espacio_asignado, fecha_compra, fecha_renovacion,
//               precio_compra?, precio_venta?,
//               clientes?: uuid[], dominios?: uuid[] }
//  Inserta el hosting + clientes (N:N) + dominios (N:N) en transaccion.
//  Espejo de endpoints/otros/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Hosting', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

// Normaliza un arreglo de UUID (unicos, no vacios) o corta con 422.
function uuids_o_error($lista, string $mensaje): array
{
    if (!is_array($lista)) { return []; }
    $lista = array_values(array_unique(array_filter(array_map(
        static fn($x) => trim((string)$x), $lista
    ), static fn($x) => $x !== '')));
    foreach ($lista as $x) {
        if (!preg_match(UUID_RE, $x)) { json_error($mensaje, 422); }
    }
    return $lista;
}

$in       = body_json();
$vpsId    = trim((string)($in['vps_id'] ?? ''));
$espacio  = trim((string)($in['espacio_asignado'] ?? ''));
$fCompra  = trim((string)($in['fecha_compra'] ?? ''));
$fRenov   = trim((string)($in['fecha_renovacion'] ?? ''));
$precioC  = $in['precio_compra'] ?? 0;
$precioV  = $in['precio_venta'] ?? 0;
$clientes = uuids_o_error($in['clientes'] ?? [], 'Alguno de los clientes seleccionados no es valido');
$dominios = uuids_o_error($in['dominios'] ?? [], 'Alguno de los dominios seleccionados no es valido');

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['vps_id' => $vpsId, 'espacio_asignado' => $espacio,
     'fecha_compra' => $fCompra, 'fecha_renovacion' => $fRenov],
    ['vps_id', 'espacio_asignado', 'fecha_compra', 'fecha_renovacion']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $vpsId)) {
    json_error('El servidor (VPS) seleccionado no es valido', 422);
}
if (mb_strlen($espacio) > 50) {
    json_error('El espacio asignado es demasiado largo (maximo 50 caracteres)', 422);
}
if (!fecha_valida($fCompra)) {
    json_error('La fecha de compra no es valida', 422);
}
if (!fecha_valida($fRenov)) {
    json_error('La fecha de renovacion no es valida', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0) {
    json_error('El precio de compra no es valido', 422);
}
if (!is_numeric($precioV) || (float)$precioV < 0) {
    json_error('El precio de venta no es valido', 422);
}

$precioCbd = number_format((float)$precioC, 2, '.', '');
$precioVbd = number_format((float)$precioV, 2, '.', '');

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
$stmt->execute([':id' => $vpsId]);
if (!$stmt->fetchColumn()) {
    json_error('El servidor (VPS) seleccionado no existe', 422);
}

// --- Insercion (hosting + clientes + dominios) en transaccion -------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.hosting
            (vps_id, espacio_asignado, fecha_compra, fecha_renovacion,
             precio_compra, precio_venta)
         VALUES (:vps, :esp, :fc, :fr, :pc, :pv)
         RETURNING id'
    );
    $stmt->execute([
        ':vps' => $vpsId, ':esp' => $espacio, ':fc' => $fCompra,
        ':fr' => $fRenov, ':pc' => $precioCbd, ':pv' => $precioVbd,
    ]);
    $id = $stmt->fetchColumn();

    if ($clientes) {
        $insCli = $pdo->prepare('INSERT INTO public.cliente_hostings (cliente_id, hosting_id) VALUES (:c, :h)');
        foreach ($clientes as $cid) { $insCli->execute([':c' => $cid, ':h' => $id]); }
    }
    if ($dominios) {
        $insDom = $pdo->prepare('INSERT INTO public.hosting_dominios (hosting_id, dominio_id) VALUES (:h, :d)');
        foreach ($dominios as $did) { $insDom->execute([':h' => $id, ':d' => $did]); }
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('Alguno de los clientes o dominios seleccionados no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Hosting',
    'Creo el hosting de ' . $espacio,
    $id,
    null,
    [
        'vps_id'           => $vpsId,
        'espacio_asignado' => $espacio,
        'fecha_compra'     => $fCompra,
        'fecha_renovacion' => $fRenov,
        'precio_compra'    => $precioCbd,
        'precio_venta'     => $precioVbd,
        'clientes'         => $clientes,
        'dominios'         => $dominios,
    ]
);

json_ok(['id' => $id], 'Hosting creado correctamente');
