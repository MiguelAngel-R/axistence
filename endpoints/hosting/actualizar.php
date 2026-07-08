<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Actualizar.
//  POST JSON: { id, vps_id, espacio_asignado, fecha_compra,
//               fecha_renovacion, precio_compra?, precio_venta?,
//               clientes?: uuid[], dominios?: uuid[] }
//  Actualiza y resincroniza clientes (N:N) y dominios (N:N) en transaccion.
//  Espejo de endpoints/hosting/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Hosting', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

function uuids_o_error($lista, string $mensaje): array
{
    if (!is_array($lista)) { return []; }
    $lista = array_values(array_unique(array_filter(array_map(
        static fn($x) => trim((string)$x), $lista
    ), static fn($x) => $x !== '')));
    foreach ($lista as $x) {
        if (!preg_match(UUID_RE, $x)) { json_error($mensaje, 422); }
    }
    sort($lista);
    return $lista;
}

$in       = body_json();
$id       = trim((string)($in['id'] ?? ''));
$vpsId    = trim((string)($in['vps_id'] ?? ''));
$espacio  = trim((string)($in['espacio_asignado'] ?? ''));
$fCompra  = trim((string)($in['fecha_compra'] ?? ''));
$fRenov   = trim((string)($in['fecha_renovacion'] ?? ''));
$precioC  = $in['precio_compra'] ?? 0;
$precioV  = $in['precio_venta'] ?? 0;
$clientes = uuids_o_error($in['clientes'] ?? [], 'Alguno de los clientes seleccionados no es valido');
$dominios = uuids_o_error($in['dominios'] ?? [], 'Alguno de los dominios seleccionados no es valido');

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
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

$stmt = $pdo->prepare(
    'SELECT vps_id, espacio_asignado, fecha_compra, fecha_renovacion,
            precio_compra, precio_venta
       FROM public.hosting WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Hosting no encontrado', 404);
}

$stmt = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
$stmt->execute([':id' => $vpsId]);
if (!$stmt->fetchColumn()) {
    json_error('El servidor (VPS) seleccionado no existe', 422);
}

$stmt = $pdo->prepare('SELECT cliente_id FROM public.cliente_hostings WHERE hosting_id = :id ORDER BY cliente_id');
$stmt->execute([':id' => $id]);
$clientesActuales = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt = $pdo->prepare('SELECT dominio_id FROM public.hosting_dominios WHERE hosting_id = :id ORDER BY dominio_id');
$stmt->execute([':id' => $id]);
$dominiosActuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Actualizacion (hosting + resync clientes + dominios) -----------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.hosting
            SET vps_id = :vps, espacio_asignado = :esp, fecha_compra = :fc,
                fecha_renovacion = :fr, precio_compra = :pc, precio_venta = :pv
          WHERE id = :id'
    );
    $stmt->execute([
        ':vps' => $vpsId, ':esp' => $espacio, ':fc' => $fCompra,
        ':fr' => $fRenov, ':pc' => $precioCbd, ':pv' => $precioVbd, ':id' => $id,
    ]);

    $pdo->prepare('DELETE FROM public.cliente_hostings WHERE hosting_id = :id')->execute([':id' => $id]);
    if ($clientes) {
        $insCli = $pdo->prepare('INSERT INTO public.cliente_hostings (cliente_id, hosting_id) VALUES (:c, :h)');
        foreach ($clientes as $cid) { $insCli->execute([':c' => $cid, ':h' => $id]); }
    }
    $pdo->prepare('DELETE FROM public.hosting_dominios WHERE hosting_id = :id')->execute([':id' => $id]);
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
    'MODIFICAR',
    'Hosting',
    'Actualizo el hosting de ' . $espacio,
    $id,
    [
        'vps_id'           => $actual['vps_id'],
        'espacio_asignado' => $actual['espacio_asignado'],
        'fecha_compra'     => $actual['fecha_compra'],
        'fecha_renovacion' => $actual['fecha_renovacion'],
        'precio_compra'    => $actual['precio_compra'],
        'precio_venta'     => $actual['precio_venta'],
        'clientes'         => $clientesActuales,
        'dominios'         => $dominiosActuales,
    ],
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

json_ok(['id' => $id], 'Hosting actualizado correctamente');
