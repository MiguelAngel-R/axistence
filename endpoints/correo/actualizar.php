<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Actualizar una RELACION de correo.
//  POST JSON: { id, dominio_id, cliente_id, mx_registro_id,
//               servidor_correo_vps_id?,
//               licencias: [{ tipo_licencia?, cantidad_cuentas }],
//               fecha_registro, fecha_vencimiento }
//  El MX se elige del DNS del dominio (mx_registro_id). Los precios NO se tocan
//  aqui: se configuran desde el detalle (viewProducto). Audita MODIFICAR.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_mx.php';
require_once __DIR__ . '/_licencias.php';
require_once __DIR__ . '/_fila_socket.php';

solo_metodo('POST');
requiere_permiso('Correo', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

$in         = body_json();
$id         = trim((string)($in['id'] ?? ''));
$dominioId  = trim((string)($in['dominio_id'] ?? ''));
$clienteId  = trim((string)($in['cliente_id'] ?? ''));
$vpsId      = trim((string)($in['servidor_correo_vps_id'] ?? ''));
$mxId       = trim((string)($in['mx_registro_id'] ?? ''));
$fReg       = trim((string)($in['fecha_registro'] ?? ''));
$fVen       = trim((string)($in['fecha_vencimiento'] ?? ''));

// Licencias (una o varias). Corta con 422 si no hay ninguna valida.
$licencias  = correo_licencias_desde_input($in);
$cantidadBd = correo_licencias_total($licencias);
$licenciaBd = correo_licencias_resumen($licencias);

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
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

$stmt = $pdo->prepare(
    'SELECT dominio_id, cliente_id, servidor_correo_vps_id, mx_registro_id,
            servidor_correo_externo, cantidad_cuentas, tipo_licencia,
            fecha_registro, fecha_vencimiento
       FROM public.cuentas_correo WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Relacion de correo no encontrada', 404);
}

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

// El MX debe existir y pertenecer al dominio. Su destino se denormaliza.
$mx = resolver_mx_correo($pdo, $dominioId, $mxId);
if ($mx === null) {
    json_error('El registro MX no pertenece al dominio seleccionado', 422);
}
$srvExterno = (string)$mx['valor'];

// Licencias previas (para el snapshot "antes" de la auditoria).
$stmt = $pdo->prepare(
    'SELECT tipo_licencia, cantidad_cuentas
       FROM public.correo_licencias
      WHERE cuenta_correo_id = :c
      ORDER BY orden ASC, created_at ASC'
);
$stmt->execute([':c' => $id]);
$licenciasPrevias = $stmt->fetchAll();

// Los precios se conservan (se editan desde el detalle, no aqui). La relacion y
// sus licencias se actualizan en una transaccion.
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.cuentas_correo
            SET dominio_id = :dom, cliente_id = :cli,
                servidor_correo_vps_id = :vps, mx_registro_id = :mx,
                servidor_correo_externo = :srv, cantidad_cuentas = :cant,
                tipo_licencia = :lic, fecha_registro = :freg, fecha_vencimiento = :fven
          WHERE id = :id'
    );
    $stmt->execute([
        ':dom' => $dominioId, ':cli' => $clienteId, ':vps' => $vpsIdBd, ':mx' => $mxId,
        ':srv' => $srvExterno, ':cant' => $cantidadBd, ':lic' => $licenciaBd,
        ':freg' => $fReg, ':fven' => $fVen, ':id' => $id,
    ]);

    correo_guardar_licencias($pdo, $id, $licencias);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Correo',
    'Actualizo la relacion de correo del dominio',
    $id,
    [
        'dominio_id'              => $actual['dominio_id'],
        'cliente_id'             => $actual['cliente_id'],
        'servidor_correo_vps_id' => $actual['servidor_correo_vps_id'],
        'mx_registro_id'         => $actual['mx_registro_id'],
        'servidor_correo_externo' => $actual['servidor_correo_externo'],
        'cantidad_cuentas'       => $actual['cantidad_cuentas'],
        'tipo_licencia'          => $actual['tipo_licencia'],
        'licencias'              => $licenciasPrevias,
        'fecha_registro'         => $actual['fecha_registro'],
        'fecha_vencimiento'      => $actual['fecha_vencimiento'],
    ],
    [
        'dominio_id'              => $dominioId,
        'cliente_id'             => $clienteId,
        'servidor_correo_vps_id' => $vpsIdBd,
        'mx_registro_id'         => $mxId,
        'servidor_correo_externo' => $srvExterno,
        'cantidad_cuentas'       => $cantidadBd,
        'tipo_licencia'          => $licenciaBd,
        'licencias'              => $licencias,
        'fecha_registro'         => $fReg,
        'fecha_vencimiento'      => $fVen,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que reemplacen
// la fila sin recargar. Se reconsulta con la MISMA forma que el listado (helper
// compartido: nombres de dominio/cliente/servidor ya resueltos), asi el
// navegador no reconsulta la BD. Se emite SOLO tras el commit y la auditoria.
$filaCorreo = fila_correo_socket($pdo, $id);
if ($filaCorreo) {
    notificar_socket('correo', 'correo:actualizado', $filaCorreo);
}

json_ok(['id' => $id], 'Relacion de correo actualizada correctamente');
