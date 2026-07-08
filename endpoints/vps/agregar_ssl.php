<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Agregar (crear) un Certificado SSL en este VPS.
//  POST JSON: { vps_id, dominio_id, proveedor_id, ruta_almacenamiento,
//               archivo_paquete_path, fecha_registro, fecha_vencimiento,
//               precio_compra?, precio_venta? }
//  El archivo se sube antes por endpoints/ssl/subir.php (multipart) y aqui
//  llega su ruta. Crea el certificado con vps_id = este VPS y audita.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

$in         = body_json();
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$dominioId  = trim((string)($in['dominio_id'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$ruta       = trim((string)($in['ruta_almacenamiento'] ?? ''));
$paquete    = trim((string)($in['archivo_paquete_path'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;

$pdo = Database::get();
$ref = exigir_vps($pdo, $vpsId);

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['dominio_id' => $dominioId, 'proveedor_id' => $proveedor, 'ruta_almacenamiento' => $ruta,
     'fecha_registro' => $fReg, 'fecha_vencimiento' => $fVen],
    ['dominio_id', 'proveedor_id', 'ruta_almacenamiento', 'fecha_registro', 'fecha_vencimiento']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $dominioId) || !preg_match(UUID_RE, $proveedor)) {
    json_error('El dominio o el proveedor seleccionado no es valido', 422);
}
if ($paquete === '') {
    json_error('Debes subir el paquete de certificados (.zip o .rar)', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fReg) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fVen)) {
    json_error('Las fechas no son validas', 422);
}
if (!is_numeric($precioC) || (float)$precioC < 0 || !is_numeric($precioV) || (float)$precioV < 0) {
    json_error('Los precios no son validos', 422);
}

// El dominio y el proveedor deben existir.
$stmt = $pdo->prepare('SELECT nombre_dominio FROM public.dominios WHERE id = :id');
$stmt->execute([':id' => $dominioId]);
$nombreDominio = $stmt->fetchColumn();
if ($nombreDominio === false) {
    json_error('El dominio seleccionado no existe', 422);
}
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// --- Insercion (certificado ya vinculado al VPS por vps_id) ---------
$stmt = $pdo->prepare(
    'INSERT INTO public.certificados_ssl
         (dominio_id, proveedor_id, vps_id, ruta_almacenamiento, archivo_paquete_path,
          fecha_registro, fecha_vencimiento, precio_compra, precio_venta)
     VALUES (:dom, :prov, :vps, :ruta, :paq, :freg, :fven, :pc, :pv)
     RETURNING id'
);
$stmt->execute([
    ':dom'  => $dominioId,
    ':prov' => $proveedor,
    ':vps'  => $vpsId,
    ':ruta' => $ruta,
    ':paq'  => $paquete,
    ':freg' => $fReg,
    ':fven' => $fVen,
    ':pc'   => number_format((float)$precioC, 2, '.', ''),
    ':pv'   => number_format((float)$precioV, 2, '.', ''),
]);
$id = $stmt->fetchColumn();

auditar_en_vps($vpsId, 'Agrego el certificado SSL de ' . $nombreDominio . ' al servidor ' . $ref,
    ['dominio' => $nombreDominio, 'proveedor_id' => $proveedor, 'fecha_vencimiento' => $fVen]);

json_ok(['id' => $id], 'Certificado SSL agregado correctamente');
