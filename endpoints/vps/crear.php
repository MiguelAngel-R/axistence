<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Servidores - Crear.
//  POST JSON: {
//    proveedor_id, referencia_vps, referencia_vps_id?, label?, fecha_creacion
//  }
//  REINGENIERIA DEL FLUJO:
//   - El formulario principal es minimo. La referencia aporta TODO el hardware,
//     los costos y el tipo de servidor (se hereda; no se digita aqui).
//   - La referencia DEBE existir en el catalogo referencias_vps para ese
//     proveedor (se crea desde el modal anidado "+"). Se guarda su id + nombre.
//   - fecha_vencimiento NO llega del cliente: la calcula el backend como
//     fecha_creacion + 1 mes - 1 dia (fuente autoritativa).
//   - NO se asocian clientes en este punto (regla de negocio).
//  Inserta el VPS y audita la operacion.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_referencia.php';
require_once __DIR__ . '/_fila.php';

solo_metodo('POST');
requiere_permiso('VPS', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in         = body_json();
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$referencia = trim((string)($in['referencia_vps'] ?? ''));
$label      = trim((string)($in['label'] ?? ''));
$creacion   = trim((string)($in['fecha_creacion'] ?? ''));

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    [
        'proveedor_id'   => $proveedor,
        'referencia_vps' => $referencia,
        'fecha_creacion' => $creacion,
    ],
    ['proveedor_id', 'referencia_vps', 'fecha_creacion']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if (mb_strlen($referencia) > 100) {
    json_error('La referencia es demasiado larga (maximo 100 caracteres)', 422);
}
if ($label !== '' && mb_strlen($label) > 120) {
    json_error('La etiqueta es demasiado larga (maximo 120 caracteres)', 422);
}
// Fecha de creacion valida (formato + fecha real).
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $creacion)
    || !DateTime::createFromFormat('Y-m-d', $creacion)) {
    json_error('La fecha de creacion no es valida', 422);
}

$labelBd = $label !== '' ? $label : null;
// Vencimiento automatico: fecha_creacion + 1 mes - 1 dia.
$vencimiento = calcular_vencimiento_vps($creacion);

$pdo = Database::get();

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// La referencia debe existir en el catalogo para ese proveedor (integridad).
$referenciaId = resolver_referencia_vps($pdo, $proveedor, $referencia);
if ($referenciaId === null) {
    json_error('La referencia no existe para el proveedor. Créala con el botón +.', 422);
}

// --- Insercion del VPS ----------------------------------------------
$stmt = $pdo->prepare(
    'INSERT INTO public.vps_servidores
        (proveedor_id, referencia_vps_id, referencia_vps, label,
         fecha_creacion, fecha_vencimiento)
     VALUES (:prov, :refid, :ref, :label, :fcrea, :fvenc)
     RETURNING id'
);
$stmt->execute([
    ':prov'  => $proveedor,
    ':refid' => $referenciaId,
    ':ref'   => $referencia,
    ':label' => $labelBd,
    ':fcrea' => $creacion,
    ':fvenc' => $vencimiento,
]);
$id = (string)$stmt->fetchColumn();

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'CREAR',
    'VPS',
    'Creo el VPS ' . $referencia . ($label !== '' ? ' (' . $label . ')' : ''),
    $id,
    null,
    [
        'referencia_vps'    => $referencia,
        'referencia_vps_id' => $referenciaId,
        'proveedor_id'      => $proveedor,
        'label'             => $labelBd,
        'fecha_creacion'    => $creacion,
        'fecha_vencimiento' => $vencimiento,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que inserten
// la fila sin recargar. Se emite SOLO tras el commit y la auditoria: el evento
// viaja con la fila completa (hardware/precio/tipo heredados de la referencia)
// para no volver a consultar la BD en el cliente.
$fila = vps_fila_por_id($pdo, $id);
if ($fila) {
    notificar_socket('vps', 'vps:creado', $fila);
}

json_ok(['id' => $id], 'VPS creado correctamente');
