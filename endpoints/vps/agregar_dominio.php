<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Agregar (crear) un Dominio apuntado a este VPS.
//  POST JSON: { vps_id, nombre_dominio, proveedor_id, fecha_registro,
//               fecha_vencimiento, precio_compra?, precio_venta? }
//  Crea el dominio con vps_id = este VPS (relacion 1:N ya enlazada) y audita.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';
require_once __DIR__ . '/../dominios/_fila_socket.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

$in         = body_json();
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$nombre     = trim((string)($in['nombre_dominio'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;

$pdo = Database::get();
$ref = exigir_vps($pdo, $vpsId);

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['nombre_dominio' => $nombre, 'proveedor_id' => $proveedor, 'fecha_registro' => $fReg, 'fecha_vencimiento' => $fVen],
    ['nombre_dominio', 'proveedor_id', 'fecha_registro', 'fecha_vencimiento']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if (mb_strlen($nombre) > 255) {
    json_error('El nombre de dominio es demasiado largo', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fReg) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fVen)) {
    json_error('Las fechas no son validas', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0 || !is_numeric($precioV) || (float)$precioV < 0) {
    json_error('Los precios no son validos', 422);
}

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// --- Insercion (dominio ya vinculado al VPS por vps_id) -------------
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.dominios
             (proveedor_id, vps_id, nombre_dominio, fecha_registro, fecha_vencimiento,
              precio_compra, precio_venta)
         VALUES (:prov, :vps, :nom, :freg, :fven, :pc, :pv)
         RETURNING id'
    );
    $stmt->execute([
        ':prov' => $proveedor,
        ':vps'  => $vpsId,
        ':nom'  => $nombre,
        ':freg' => $fReg,
        ':fven' => $fVen,
        ':pc'   => number_format((float)$precioC, 2, '.', ''),
        ':pv'   => number_format((float)$precioV, 2, '.', ''),
    ]);
    $id = $stmt->fetchColumn();
} catch (PDOException $e) {
    // 23505 = dominio duplicado (nombre_dominio es unico).
    if ($e->getCode() === '23505') {
        json_error('Ese dominio ya existe en el sistema', 409);
    }
    throw $e;
}

auditar_en_vps($vpsId, 'Agrego el dominio ' . $nombre . ' al servidor ' . $ref,
    ['nombre_dominio' => $nombre, 'proveedor_id' => $proveedor, 'fecha_vencimiento' => $fVen]);

// --- Tiempo real ----------------------------------------------------
// Un dominio creado desde el detalle del VPS impacta DOS vistas; se reconsulta
// UNA vez con la forma exacta del listado de dominios (helper compartido) y se
// reparten dos eventos (fire-and-forget, nunca rompen la operacion):
//   * 'dominios' / 'dominio:creado'      -> lo pinta el LISTADO del modulo
//     Dominios (mismo evento/forma que dominios/crear.php; sin tocar su JS).
//   * 'vps' / 'dominio_vps:creado'       -> lo agrega a la tabla de Dominios del
//     DETALLE (viewProducto) de quien tenga abierto ESTE VPS (trae vps_id).
$filaDom = fila_dominio_socket($pdo, (string)$id);
if ($filaDom) {
    notificar_socket('dominios', 'dominio:creado', $filaDom);
    notificar_socket('vps', 'dominio_vps:creado', $filaDom);
}

json_ok(['id' => $id], 'Dominio agregado correctamente');
