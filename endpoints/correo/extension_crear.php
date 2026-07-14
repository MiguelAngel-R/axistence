<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Asignar un COMPLEMENTO a una LICENCIA.
//  POST JSON: { licencia_id, nombre, cantidad_cuentas, valor, fecha_inicio }
//  El complemento cuelga de una licencia (correo_licencias). La relacion
//  (cuenta_correo_id) se deriva de la licencia. Sustituye al antiguo modelo
//  de "extension de espacio" ligada a una cuenta.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

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
$licenciaId = trim((string)($in['licencia_id'] ?? ''));
$nombre     = trim((string)($in['nombre'] ?? ''));
$cantRaw    = $in['cantidad_cuentas'] ?? null;
$valorRaw   = $in['valor'] ?? null;
$fechaIni   = trim((string)($in['fecha_inicio'] ?? ''));

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $licenciaId)) {
    json_error('La licencia seleccionada no es valida', 422);
}
if ($nombre === '') {
    json_error('El tipo de complemento (nombre) es obligatorio', 422);
}
if (mb_strlen($nombre) > 150) {
    json_error('El tipo de complemento es demasiado largo (maximo 150 caracteres)', 422);
}
if (!is_numeric($cantRaw) || (int)$cantRaw < 1) {
    json_error('La cantidad de cuentas debe ser un numero mayor o igual a 1', 422);
}
$cantidad = (int)$cantRaw;
if ($cantidad > 100000) {
    json_error('La cantidad de cuentas es demasiado grande', 422);
}
if (!is_numeric($valorRaw) || (float)$valorRaw < 0) {
    json_error('El valor debe ser un numero mayor o igual a 0', 422);
}
$valor = round((float)$valorRaw, 2);
if (!fecha_valida($fechaIni)) {
    json_error('La fecha de inicio no es valida', 422);
}

$pdo = Database::get();

// La licencia debe existir; de ahi sale la relacion (cuenta_correo_id).
$stmt = $pdo->prepare(
    'SELECT l.id, l.tipo_licencia, l.cuenta_correo_id
       FROM public.correo_licencias l
      WHERE l.id = :lic'
);
$stmt->execute([':lic' => $licenciaId]);
$licencia = $stmt->fetch();
if (!$licencia) {
    json_error('La licencia no existe', 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO public.correo_extensiones_espacio
        (cuenta_correo_id, licencia_id, nombre, cantidad_cuentas, valor, fecha_inicio)
     VALUES (:rel, :lic, :nombre, :cant, :valor, :fecha)
     RETURNING id, nombre, cantidad_cuentas, valor, fecha_inicio'
);
$stmt->execute([
    ':rel'    => $licencia['cuenta_correo_id'],
    ':lic'    => $licenciaId,
    ':nombre' => $nombre,
    ':cant'   => $cantidad,
    ':valor'  => $valor,
    ':fecha'  => $fechaIni,
]);
$complemento = $stmt->fetch();

registrar_auditoria(
    'CREAR',
    'Correo',
    'Asigno el complemento "' . $nombre . '" (' . $cantidad . ' cuentas) a la licencia ' .
        ($licencia['tipo_licencia'] ?: 'sin tipo'),
    (string)$complemento['id'],
    null,
    [
        'licencia_id'      => $licenciaId,
        'nombre'           => $nombre,
        'cantidad_cuentas' => $cantidad,
        'valor'            => $valor,
        'fecha_inicio'     => $fechaIni,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de esta relacion de
// correo para que agreguen la fila al tab "Complementos" sin recargar. El
// evento viaja con cuenta_correo_id (la relacion) para que cada navegador sepa
// si le corresponde, e incluye el tipo de licencia ya resuelto para pintar la
// fila igual que ver.php. Se emite SOLO tras la insercion y la auditoria.
$complemento['tipo_licencia']    = $licencia['tipo_licencia'];
$complemento['cuenta_correo_id'] = $licencia['cuenta_correo_id'];
notificar_socket('correo', 'complemento:creado', $complemento);

json_ok(['complemento' => $complemento], 'Complemento asignado correctamente');
