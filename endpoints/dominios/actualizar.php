<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Actualizar.
//  POST JSON: { id, nombre_dominio, proveedor_id, vps_id?, vps_externa?,
//               fecha_registro, precio_compra?, precio_venta?, clientes?: uuid[] }
//  - El tipo de administracion se deriva del servidor (VPS => PROPIA + cuenta;
//    externo => TERCEROS).
//  - fecha_vencimiento se recalcula (registro + 1 año - 1 dia).
//  Actualiza el dominio y resincroniza sus clientes (N:N) en transaccion.
//  Espejo de endpoints/vps/actualizar.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_administracion.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

$in         = body_json();
$id         = trim((string)($in['id'] ?? ''));
$nombre     = trim((string)($in['nombre_dominio'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$vpsExterna = trim((string)($in['vps_externa'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;
// Un dominio se asocia a un unico cliente (o a ninguno).
$clienteId  = trim((string)($in['cliente_id'] ?? ''));

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
$faltan = campos_faltantes(
    ['nombre_dominio' => $nombre, 'proveedor_id' => $proveedor,
     'fecha_registro' => $fReg],
    ['nombre_dominio', 'proveedor_id', 'fecha_registro']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 255) {
    json_error('El nombre de dominio es demasiado largo (maximo 255 caracteres)', 422);
}
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if ($vpsId !== '' && !preg_match(UUID_RE, $vpsId)) {
    json_error('El VPS seleccionado no es valido', 422);
}
if ($vpsExterna !== '' && mb_strlen($vpsExterna) > 255) {
    json_error('El VPS externo es demasiado largo (maximo 255 caracteres)', 422);
}
if (!fecha_valida($fReg)) {
    json_error('La fecha de registro no es valida', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0) {
    json_error('El precio de compra no es valido', 422);
}
if (!is_numeric($precioV) || (float)$precioV < 0) {
    json_error('El precio de venta no es valido', 422);
}
if ($clienteId !== '' && !preg_match(UUID_RE, $clienteId)) {
    json_error('El cliente seleccionado no es valido', 422);
}

$clienteIdBd  = $clienteId !== '' ? $clienteId : null;
$vpsIdBd      = $vpsId !== '' ? $vpsId : null;
$vpsExternaBd = $vpsExterna !== '' ? $vpsExterna : null;
$precioCbd    = number_format((float)$precioC, 2, '.', '');
$precioVbd    = number_format((float)$precioV, 2, '.', '');
// Vencimiento automatico: fecha_registro + 1 año - 1 dia.
$fVenBd       = calcular_vencimiento_anual($fReg);

$pdo = Database::get();

// El dominio debe existir (guardamos su estado previo para la auditoria).
$stmt = $pdo->prepare(
    'SELECT nombre_dominio, proveedor_id, vps_id, vps_externa,
            tipo_administracion, cuenta_id,
            fecha_registro, fecha_vencimiento, precio_compra, precio_venta
       FROM public.dominios WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Dominio no encontrado', 404);
}

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}
// El VPS (si se envio) debe existir.
if ($vpsIdBd !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
    $stmt->execute([':id' => $vpsIdBd]);
    if (!$stmt->fetchColumn()) {
        json_error('El VPS seleccionado no existe', 422);
    }
}
// El nombre debe ser unico (excluyendo el propio dominio).
$stmt = $pdo->prepare('SELECT 1 FROM public.dominios WHERE nombre_dominio = :n AND id <> :id');
$stmt->execute([':n' => $nombre, ':id' => $id]);
if ($stmt->fetchColumn()) {
    json_error('Ya existe otro dominio con ese nombre', 409);
}

// Tipo de administracion derivado del servidor (VPS => PROPIA + cuenta;
// externo => TERCEROS).
$adm = resolver_administracion_dominio($pdo, $in, $proveedor, $vpsIdBd);

// Clientes previos (para auditar el cambio).
$stmt = $pdo->prepare('SELECT cliente_id FROM public.dominio_clientes WHERE dominio_id = :id ORDER BY cliente_id');
$stmt->execute([':id' => $id]);
$clientesActuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Actualizacion (dominio + resync clientes) en transaccion -------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.dominios
            SET nombre_dominio = :n, proveedor_id = :prov, vps_id = :vps,
                vps_externa = :vpsext, tipo_administracion = :tadm, cuenta_id = :cuenta,
                fecha_registro = :freg, fecha_vencimiento = :fven,
                precio_compra = :pc, precio_venta = :pv
          WHERE id = :id'
    );
    $stmt->execute([
        ':n'      => $nombre,
        ':prov'   => $proveedor,
        ':vps'    => $vpsIdBd,
        ':vpsext' => $vpsExternaBd,
        ':tadm'   => $adm['tipo_administracion'],
        ':cuenta' => $adm['cuenta_id'],
        ':freg'   => $fReg,
        ':fven'   => $fVenBd,
        ':pc'     => $precioCbd,
        ':pv'     => $precioVbd,
        ':id'     => $id,
    ]);

    // Resync del cliente unico: se borra el anterior y, si hay, se inserta uno.
    $pdo->prepare('DELETE FROM public.dominio_clientes WHERE dominio_id = :id')->execute([':id' => $id]);

    if ($clienteIdBd !== null) {
        $pdo->prepare(
            'INSERT INTO public.dominio_clientes (dominio_id, cliente_id) VALUES (:d, :c)'
        )->execute([':d' => $id, ':c' => $clienteIdBd]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('El cliente seleccionado no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Dominios',
    'Actualizo el dominio ' . $nombre,
    $id,
    [
        'nombre_dominio'    => $actual['nombre_dominio'],
        'proveedor_id'      => $actual['proveedor_id'],
        'vps_id'            => $actual['vps_id'],
        'vps_externa'       => $actual['vps_externa'],
        'tipo_administracion' => $actual['tipo_administracion'],
        'cuenta_id'         => $actual['cuenta_id'],
        'fecha_registro'    => $actual['fecha_registro'],
        'fecha_vencimiento' => $actual['fecha_vencimiento'],
        'precio_compra'     => $actual['precio_compra'],
        'precio_venta'      => $actual['precio_venta'],
        'cliente_id'        => $clientesActuales[0] ?? null,
    ],
    [
        'nombre_dominio'    => $nombre,
        'proveedor_id'      => $proveedor,
        'vps_id'            => $vpsIdBd,
        'vps_externa'       => $vpsExternaBd,
        'tipo_administracion' => $adm['tipo_administracion'],
        'cuenta_id'         => $adm['cuenta_id'],
        'fecha_registro'    => $fReg,
        'fecha_vencimiento' => $fVenBd,
        'precio_compra'     => $precioCbd,
        'precio_venta'      => $precioVbd,
        'cliente_id'        => $clienteIdBd,
    ]
);

json_ok(['id' => $id], 'Dominio actualizado correctamente');
