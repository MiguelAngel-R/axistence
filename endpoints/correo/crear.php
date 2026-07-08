<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Crear una RELACION de correo para un dominio.
//  POST JSON: { dominio_id, cliente_id, mx_registro_id,
//               servidor_correo_vps_id?,
//               licencias: [{ tipo_licencia?, cantidad_cuentas }],
//               fecha_registro, fecha_vencimiento }
//  La relacion se asocia a un registro MX del DNS del dominio (mx_registro_id).
//  El dominio (ya registrado) tendra N cuentas repartidas en una o varias
//  licencias. Los precios se configuran despues (en el detalle), por eso NO se
//  reciben aqui (quedan en su default 0).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_mx.php';
require_once __DIR__ . '/_licencias.php';

solo_metodo('POST');
requiere_permiso('Correo', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

$in         = body_json();
$dominioId  = trim((string)($in['dominio_id'] ?? ''));
$clienteId  = trim((string)($in['cliente_id'] ?? ''));
$vpsId      = trim((string)($in['servidor_correo_vps_id'] ?? ''));
$mxId       = trim((string)($in['mx_registro_id'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));

// Licencias (una o varias): [{ tipo_licencia?, cantidad_cuentas }]. Corta con
// 422 si no hay ninguna valida. El total y el resumen se denormalizan abajo.
$licencias  = correo_licencias_desde_input($in);
$cantidadBd = correo_licencias_total($licencias);
$licenciaBd = correo_licencias_resumen($licencias);

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['dominio_id' => $dominioId, 'cliente_id' => $clienteId,
     'mx_registro_id' => $mxId,
     'fecha_registro' => $fReg, 'fecha_vencimiento' => $fVen],
    ['dominio_id', 'cliente_id', 'mx_registro_id', 'fecha_registro', 'fecha_vencimiento']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!preg_match(UUID_RE, $dominioId)) {
    json_error('El dominio seleccionado no es valido', 422);
}
if (!preg_match(UUID_RE, $clienteId)) {
    json_error('El cliente seleccionado no es valido', 422);
}
if ($vpsId !== '' && !preg_match(UUID_RE, $vpsId)) {
    json_error('El servidor (VPS) seleccionado no es valido', 422);
}
if (!preg_match(UUID_RE, $mxId)) {
    json_error('El registro MX seleccionado no es valido', 422);
}
if (!fecha_valida($fReg)) {
    json_error('La fecha de registro no es valida', 422);
}
if (!fecha_valida($fVen)) {
    json_error('La fecha de vencimiento no es valida', 422);
}

$vpsIdBd = $vpsId !== '' ? $vpsId : null;

$pdo = Database::get();

// Dominio y cliente deben existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.dominios WHERE id = :id');
$stmt->execute([':id' => $dominioId]);
if (!$stmt->fetchColumn()) {
    json_error('El dominio seleccionado no existe', 422);
}
$stmt = $pdo->prepare('SELECT 1 FROM public.clientes WHERE id = :id');
$stmt->execute([':id' => $clienteId]);
if (!$stmt->fetchColumn()) {
    json_error('El cliente seleccionado no existe', 422);
}
if ($vpsIdBd !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
    $stmt->execute([':id' => $vpsIdBd]);
    if (!$stmt->fetchColumn()) {
        json_error('El servidor (VPS) seleccionado no existe', 422);
    }
}

// El MX debe existir y pertenecer al dominio. Su destino (valor) se guarda
// denormalizado en servidor_correo_externo para mostrarlo sin JOIN.
$mx = resolver_mx_correo($pdo, $dominioId, $mxId);
if ($mx === null) {
    json_error('El registro MX no pertenece al dominio seleccionado', 422);
}
$srvExterno = (string)$mx['valor'];

// Los precios quedan en su default (0.00): se configuran despues en el detalle.
// La relacion + sus licencias se guardan en una transaccion.
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.cuentas_correo
            (dominio_id, cliente_id, servidor_correo_vps_id, mx_registro_id,
             servidor_correo_externo, cantidad_cuentas, tipo_licencia,
             fecha_registro, fecha_vencimiento)
         VALUES (:dom, :cli, :vps, :mx, :srv, :cant, :lic, :freg, :fven)
         RETURNING id'
    );
    $stmt->execute([
        ':dom' => $dominioId, ':cli' => $clienteId, ':vps' => $vpsIdBd, ':mx' => $mxId,
        ':srv' => $srvExterno, ':cant' => $cantidadBd, ':lic' => $licenciaBd,
        ':freg' => $fReg, ':fven' => $fVen,
    ]);
    $id = $stmt->fetchColumn();

    correo_guardar_licencias($pdo, (string)$id, $licencias);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Correo',
    'Creo la relacion de correo del dominio (' . $cantidadBd . ' cuenta(s))',
    $id,
    null,
    [
        'dominio_id'              => $dominioId,
        'cliente_id'              => $clienteId,
        'servidor_correo_vps_id'  => $vpsIdBd,
        'mx_registro_id'          => $mxId,
        'servidor_correo_externo' => $srvExterno,
        'cantidad_cuentas'        => $cantidadBd,
        'tipo_licencia'           => $licenciaBd,
        'licencias'               => $licencias,
        'fecha_registro'          => $fReg,
        'fecha_vencimiento'       => $fVen,
    ]
);

json_ok(['id' => $id], 'Relacion de correo creada correctamente');
