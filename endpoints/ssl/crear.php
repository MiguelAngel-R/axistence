<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Crear.
//  POST JSON: { dominio_id, proveedor_id, vps_id?, vps_externa?,
//               ruta_almacenamiento, archivo_paquete_path,
//               fecha_registro, fecha_vencimiento,
//               precio_compra?, precio_venta? }
//  'archivo_paquete_path' debe provenir de endpoints/ssl/subir.php (se
//  valida el formato del nombre aleatorio y que el archivo exista).
//  Espejo de endpoints/correo/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_fila_socket.php';

solo_metodo('POST');
requiere_permiso('SSL', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

// Valida que la ruta corresponda a un archivo subido por subir.php y que exista.
function ruta_ssl_valida(string $ruta): bool
{
    if (!preg_match('~^uploads/ssl/[a-f0-9]{32}\.(zip|rar)$~', $ruta)) { return false; }
    return is_file(UPLOADS_DIR . '/ssl/' . basename($ruta));
}

$in         = body_json();
$dominioId  = trim((string)($in['dominio_id'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$vpsExterna = trim((string)($in['vps_externa'] ?? ''));
$ruta       = trim((string)($in['ruta_almacenamiento'] ?? ''));
$paquete    = trim((string)($in['archivo_paquete_path'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['dominio_id' => $dominioId, 'proveedor_id' => $proveedor,
     'ruta_almacenamiento' => $ruta, 'archivo_paquete_path' => $paquete,
     'fecha_registro' => $fReg, 'fecha_vencimiento' => $fVen],
    ['dominio_id', 'proveedor_id', 'ruta_almacenamiento', 'archivo_paquete_path', 'fecha_registro', 'fecha_vencimiento']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $dominioId)) {
    json_error('El dominio seleccionado no es valido', 422);
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
if (!ruta_ssl_valida($paquete)) {
    json_error('El paquete de certificados no es valido o no se subio correctamente', 422);
}
if (!fecha_valida($fReg)) {
    json_error('La fecha de registro no es valida', 422);
}
if (!fecha_valida($fVen)) {
    json_error('La fecha de vencimiento no es valida', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0) {
    json_error('El precio de compra no es valido', 422);
}
if (!is_numeric($precioV) || (float)$precioV < 0) {
    json_error('El precio de venta no es valido', 422);
}

$vpsIdBd      = $vpsId !== '' ? $vpsId : null;
$vpsExternaBd = $vpsExterna !== '' ? $vpsExterna : null;
$precioCbd    = number_format((float)$precioC, 2, '.', '');
$precioVbd    = number_format((float)$precioV, 2, '.', '');

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT 1 FROM public.dominios WHERE id = :id');
$stmt->execute([':id' => $dominioId]);
if (!$stmt->fetchColumn()) {
    json_error('El dominio seleccionado no existe', 422);
}
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}
if ($vpsIdBd !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
    $stmt->execute([':id' => $vpsIdBd]);
    if (!$stmt->fetchColumn()) {
        json_error('El VPS seleccionado no existe', 422);
    }
}

$stmt = $pdo->prepare(
    'INSERT INTO public.certificados_ssl
        (dominio_id, proveedor_id, vps_id, vps_externa, ruta_almacenamiento,
         archivo_paquete_path, fecha_registro, fecha_vencimiento,
         precio_compra, precio_venta)
     VALUES (:dom, :prov, :vps, :vpsext, :ruta, :paq, :freg, :fven, :pc, :pv)
     RETURNING id'
);
$stmt->execute([
    ':dom' => $dominioId, ':prov' => $proveedor, ':vps' => $vpsIdBd,
    ':vpsext' => $vpsExternaBd, ':ruta' => $ruta, ':paq' => $paquete,
    ':freg' => $fReg, ':fven' => $fVen, ':pc' => $precioCbd, ':pv' => $precioVbd,
]);
$id = $stmt->fetchColumn();

registrar_auditoria(
    'CREAR',
    'SSL',
    'Registro el certificado SSL del dominio seleccionado',
    $id,
    null,
    [
        'dominio_id'          => $dominioId,
        'proveedor_id'        => $proveedor,
        'vps_id'              => $vpsIdBd,
        'vps_externa'         => $vpsExternaBd,
        'ruta_almacenamiento' => $ruta,
        'archivo_paquete_path' => $paquete,
        'fecha_registro'      => $fReg,
        'fecha_vencimiento'   => $fVen,
        'precio_compra'       => $precioCbd,
        'precio_venta'        => $precioVbd,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que inserten la
// fila sin recargar. La fila se reconsulta con la MISMA forma que el listado
// (helper compartido: resuelve nombres de dominio/proveedor/VPS), asi el
// navegador no reconsulta la BD. Se emite SOLO tras el commit y la auditoria
// (fire-and-forget: nunca rompe la operacion).
$filaSsl = fila_ssl_socket($pdo, $id);
if ($filaSsl) {
    notificar_socket('ssl', 'ssl:creado', $filaSsl);
}

json_ok(['id' => $id], 'Certificado SSL creado correctamente');
