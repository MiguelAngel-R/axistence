<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Crear.
//  POST JSON: { nombre_dominio, proveedor_id, vps_id?, vps_externa?,
//               fecha_registro, precio_compra?, precio_venta?, clientes?: uuid[] }
//  - El tipo de administracion se deriva del servidor (VPS del sistema =>
//    PROPIA con cuenta; servidor externo => TERCEROS).
//  - fecha_vencimiento NO llega del cliente: se calcula (registro + 1 año - 1 dia).
//  Inserta el dominio y sus clientes (N:N) en una transaccion.
//  Espejo de endpoints/vps/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_administracion.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// Valida una fecha en formato aaaa-mm-dd (real).
function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

$in         = body_json();
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

$vpsIdBd      = $vpsId !== '' ? $vpsId : null;
$vpsExternaBd = $vpsExterna !== '' ? $vpsExterna : null;
$clienteIdBd  = $clienteId !== '' ? $clienteId : null;
$precioCbd    = number_format((float)$precioC, 2, '.', '');
$precioVbd    = number_format((float)$precioV, 2, '.', '');
// Vencimiento automatico: fecha_registro + 1 año - 1 dia.
$fVenBd       = calcular_vencimiento_anual($fReg);

$pdo = Database::get();

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
// El nombre de dominio debe ser unico.
$stmt = $pdo->prepare('SELECT 1 FROM public.dominios WHERE nombre_dominio = :n');
$stmt->execute([':n' => $nombre]);
if ($stmt->fetchColumn()) {
    json_error('Ya existe un dominio con ese nombre', 409);
}

// Tipo de administracion derivado del servidor (VPS => PROPIA + cuenta;
// externo => TERCEROS).
$adm = resolver_administracion_dominio($pdo, $in, $proveedor, $vpsIdBd);

// --- Insercion (dominio + clientes) en transaccion ------------------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.dominios
            (nombre_dominio, proveedor_id, vps_id, vps_externa,
             tipo_administracion, cuenta_id,
             fecha_registro, fecha_vencimiento, precio_compra, precio_venta)
         VALUES (:n, :prov, :vps, :vpsext, :tadm, :cuenta, :freg, :fven, :pc, :pv)
         RETURNING id'
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
    ]);
    $id = $stmt->fetchColumn();

    // Cliente unico (opcional): una sola fila en dominio_clientes.
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
    'CREAR',
    'Dominios',
    'Creo el dominio ' . $nombre,
    $id,
    null,
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

json_ok(['id' => $id], 'Dominio creado correctamente');
