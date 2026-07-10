<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Asignar una extension de espacio a una CUENTA (buzon).
//  POST JSON: { licencia_id, cuenta_id, gigas_adicionales }
//  La cuenta debe pertenecer a la licencia. La relacion (cuenta_correo_id)
//  se deriva de la licencia. La fecha de adquisicion es la de hoy y los
//  precios quedan en su default (0); se configuran despues si hace falta.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Correo', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in         = body_json();
$licenciaId = trim((string)($in['licencia_id'] ?? ''));
$cuentaId   = trim((string)($in['cuenta_id'] ?? ''));
$gigasRaw   = $in['gigas_adicionales'] ?? null;

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $licenciaId)) {
    json_error('La licencia seleccionada no es valida', 422);
}
if (!preg_match(UUID_RE, $cuentaId)) {
    json_error('La cuenta seleccionada no es valida', 422);
}
if (!is_numeric($gigasRaw) || (int)$gigasRaw < 1) {
    json_error('La cantidad de extension de espacio debe ser un numero mayor o igual a 1', 422);
}
$gigas = (int)$gigasRaw;
if ($gigas > 100000) {
    json_error('La cantidad de extension de espacio es demasiado grande', 422);
}

$pdo = Database::get();

// La cuenta debe existir y pertenecer a la licencia; de ahi sale la relacion.
$stmt = $pdo->prepare(
    'SELECT cu.id, cu.correo, l.cuenta_correo_id
       FROM public.correo_licencia_cuentas cu
       JOIN public.correo_licencias l ON l.id = cu.licencia_id
      WHERE cu.id = :cu AND l.id = :lic'
);
$stmt->execute([':cu' => $cuentaId, ':lic' => $licenciaId]);
$cuenta = $stmt->fetch();
if (!$cuenta) {
    json_error('La cuenta no existe o no pertenece a la licencia seleccionada', 422);
}

$stmt = $pdo->prepare(
    'INSERT INTO public.correo_extensiones_espacio
        (cuenta_correo_id, cuenta_id, gigas_adicionales, fecha_adquisicion)
     VALUES (:rel, :cu, :gigas, CURRENT_DATE)
     RETURNING id, gigas_adicionales, fecha_adquisicion'
);
$stmt->execute([
    ':rel'   => $cuenta['cuenta_correo_id'],
    ':cu'    => $cuentaId,
    ':gigas' => $gigas,
]);
$ext = $stmt->fetch();

registrar_auditoria(
    'CREAR',
    'Correo',
    'Asigno una extension de espacio de ' . $gigas . ' GB a ' . $cuenta['correo'],
    (string)$ext['id'],
    null,
    [
        'licencia_id'       => $licenciaId,
        'cuenta_id'         => $cuentaId,
        'correo'            => $cuenta['correo'],
        'gigas_adicionales' => $gigas,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de esta relacion de
// correo para que agreguen la fila al tab "Extensiones" sin recargar. Se
// reconsulta la extension recien creada con los MISMOS JOINs/campos que
// endpoints/correo/ver.php (nombre/correo de la cuenta y tipo de licencia ya
// resueltos), que no estan en las variables del endpoint. El evento viaja con
// cuenta_correo_id (la relacion) para que cada navegador sepa si le corresponde.
// Se emite SOLO tras la insercion y la auditoria (fire-and-forget).
$stmt = $pdo->prepare(
    'SELECT e.id, e.gigas_adicionales, e.fecha_adquisicion,
            cu.nombre AS cuenta_nombre, cu.apellidos AS cuenta_apellidos,
            cu.correo AS cuenta_correo, l.tipo_licencia
       FROM public.correo_extensiones_espacio e
       LEFT JOIN public.correo_licencia_cuentas cu ON cu.id = e.cuenta_id
       LEFT JOIN public.correo_licencias l         ON l.id = cu.licencia_id
      WHERE e.id = :id'
);
$stmt->execute([':id' => $ext['id']]);
$extFila = $stmt->fetch();
if ($extFila) {
    $extFila['cuenta_correo_id'] = $cuenta['cuenta_correo_id'];
    notificar_socket('correo', 'extension:creada', $extFila);
}

json_ok(['extension' => $ext], 'Extension de espacio asignada correctamente');
