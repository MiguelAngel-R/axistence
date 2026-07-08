<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Servidores - Actualizar.
//  POST JSON: {
//    id, proveedor_id, referencia_vps, referencia_vps_id?, label?, fecha_creacion
//  }
//  Misma logica de integridad que crear.php:
//   - La referencia (hardware/costos/tipo) se hereda; no se edita aqui.
//   - fecha_vencimiento se recalcula (fecha_creacion + 1 mes - 1 dia).
//   - Las asociaciones de clientes (N:N) NO se tocan desde este formulario:
//     se conservan intactas (se gestionan en otro punto).
//  Actualiza el VPS y audita MODIFICAR.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_referencia.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in         = body_json();
$id         = trim((string)($in['id'] ?? ''));
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$referencia = trim((string)($in['referencia_vps'] ?? ''));
$label      = trim((string)($in['label'] ?? ''));
$creacion   = trim((string)($in['fecha_creacion'] ?? ''));

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $creacion)
    || !DateTime::createFromFormat('Y-m-d', $creacion)) {
    json_error('La fecha de creacion no es valida', 422);
}

$labelBd     = $label !== '' ? $label : null;
$vencimiento = calcular_vencimiento_vps($creacion);

$pdo = Database::get();

// El VPS debe existir (guardamos su estado previo para la auditoria).
$stmt = $pdo->prepare(
    'SELECT referencia_vps, referencia_vps_id, proveedor_id, label,
            fecha_creacion, fecha_vencimiento
       FROM public.vps_servidores WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('VPS no encontrado', 404);
}

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// La referencia debe existir en el catalogo para ese proveedor.
$referenciaId = resolver_referencia_vps($pdo, $proveedor, $referencia);
if ($referenciaId === null) {
    json_error('La referencia no existe para el proveedor. Créala con el botón +.', 422);
}

// --- Actualizacion del VPS ------------------------------------------
$stmt = $pdo->prepare(
    'UPDATE public.vps_servidores
        SET proveedor_id = :prov, referencia_vps_id = :refid, referencia_vps = :ref,
            label = :label, fecha_creacion = :fcrea, fecha_vencimiento = :fvenc
      WHERE id = :id'
);
$stmt->execute([
    ':prov'  => $proveedor,
    ':refid' => $referenciaId,
    ':ref'   => $referencia,
    ':label' => $labelBd,
    ':fcrea' => $creacion,
    ':fvenc' => $vencimiento,
    ':id'    => $id,
]);

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'MODIFICAR',
    'VPS',
    'Actualizo el VPS ' . $referencia . ($label !== '' ? ' (' . $label . ')' : ''),
    $id,
    [
        'referencia_vps'    => $actual['referencia_vps'],
        'referencia_vps_id' => $actual['referencia_vps_id'],
        'proveedor_id'      => $actual['proveedor_id'],
        'label'             => $actual['label'],
        'fecha_creacion'    => $actual['fecha_creacion'],
        'fecha_vencimiento' => $actual['fecha_vencimiento'],
    ],
    [
        'referencia_vps'    => $referencia,
        'referencia_vps_id' => $referenciaId,
        'proveedor_id'      => $proveedor,
        'label'             => $labelBd,
        'fecha_creacion'    => $creacion,
        'fecha_vencimiento' => $vencimiento,
    ]
);

json_ok(['id' => $id], 'VPS actualizado correctamente');
