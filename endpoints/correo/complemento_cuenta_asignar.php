<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Asignar un COMPLEMENTO a una CUENTA (buzon) de su licencia.
//  POST JSON: { complemento_id, cuenta_id }
//  La cuenta debe pertenecer a la licencia del complemento. No se puede
//  exceder el cupo (complemento.cantidad_cuentas) ni asignar dos veces la
//  misma cuenta al mismo complemento.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Correo', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in            = body_json();
$complementoId = trim((string)($in['complemento_id'] ?? ''));
$cuentaId      = trim((string)($in['cuenta_id'] ?? ''));

if (!preg_match(UUID_RE, $complementoId)) {
    json_error('El complemento no es valido', 422);
}
if (!preg_match(UUID_RE, $cuentaId)) {
    json_error('La cuenta seleccionada no es valida', 422);
}

$pdo = Database::get();

// Complemento (con su licencia y cupo).
$stmt = $pdo->prepare(
    'SELECT id, nombre, cantidad_cuentas, cuenta_correo_id, licencia_id
       FROM public.correo_extensiones_espacio
      WHERE id = :id'
);
$stmt->execute([':id' => $complementoId]);
$comp = $stmt->fetch();
if (!$comp) {
    json_error('El complemento no existe', 404);
}
if (empty($comp['licencia_id'])) {
    json_error('Este complemento no esta asociado a una licencia', 409);
}

// La cuenta debe existir y pertenecer a la licencia del complemento.
$stmt = $pdo->prepare(
    'SELECT id, nombre, apellidos, correo, tipo_cuenta
       FROM public.correo_licencia_cuentas
      WHERE id = :cu AND licencia_id = :lic'
);
$stmt->execute([':cu' => $cuentaId, ':lic' => $comp['licencia_id']]);
$cuenta = $stmt->fetch();
if (!$cuenta) {
    json_error('La cuenta no existe o no pertenece a la licencia del complemento', 422);
}

$pdo->beginTransaction();
try {
    // Cupo: no exceder la cantidad de cuentas del complemento (dentro de la tx).
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM public.correo_complemento_cuentas WHERE complemento_id = :id');
    $stmt->execute([':id' => $complementoId]);
    $usadas = (int)$stmt->fetchColumn();
    if ($usadas >= (int)$comp['cantidad_cuentas']) {
        $pdo->rollBack();
        json_error('El complemento ya alcanzo su limite de ' . (int)$comp['cantidad_cuentas'] . ' cuenta(s)', 409);
    }

    // No repetir la misma cuenta.
    $stmt = $pdo->prepare(
        'SELECT 1 FROM public.correo_complemento_cuentas WHERE complemento_id = :id AND cuenta_id = :cu'
    );
    $stmt->execute([':id' => $complementoId, ':cu' => $cuentaId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        json_error('Esa cuenta ya tiene este complemento asignado', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO public.correo_complemento_cuentas (complemento_id, cuenta_id)
         VALUES (:id, :cu)
         RETURNING id, cuenta_id, created_at'
    );
    $stmt->execute([':id' => $complementoId, ':cu' => $cuentaId]);
    $asignacion = $stmt->fetch();

    $pdo->commit();
    // Cupo tras la insercion (para actualizar "Disponibles" en el listado).
    $usadas = $usadas + 1;
} catch (PDOException $e) {
    $pdo->rollBack();
    // Choque con el indice unico (carrera entre dos asignaciones iguales).
    if ($e->getCode() === '23505') {
        json_error('Esa cuenta ya tiene este complemento asignado', 409);
    }
    throw $e;
}

// La respuesta trae los datos de la cuenta para pintar la fila sin re-consultar.
$asignacion['nombre']      = $cuenta['nombre'];
$asignacion['apellidos']   = $cuenta['apellidos'];
$asignacion['correo']      = $cuenta['correo'];
$asignacion['tipo_cuenta'] = $cuenta['tipo_cuenta'];

registrar_auditoria(
    'CREAR',
    'Correo',
    'Asigno el complemento "' . ($comp['nombre'] ?: 'sin nombre') . '" a la cuenta ' . $cuenta['correo'],
    (string)$asignacion['id'],
    null,
    [
        'complemento_id' => $complementoId,
        'cuenta_id'      => $cuentaId,
        'correo'         => $cuenta['correo'],
    ]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de esta relacion para
// que actualicen la columna "Disponibles" del complemento en el tab
// "Complementos" sin recargar. El evento viaja con cuenta_correo_id (la
// relacion, para filtrar por detalle) y el cupo ya recalculado (usadas /
// cantidad_cuentas). Se emite SOLO tras el commit y la auditoria.
notificar_socket('correo', 'complemento_cuenta:asignada', [
    'complemento_id'   => $complementoId,
    'cuenta_correo_id' => $comp['cuenta_correo_id'],
    'usadas'           => $usadas,
    'cantidad_cuentas' => (int)$comp['cantidad_cuentas'],
]);

json_ok(['asignacion' => $asignacion], 'Complemento asignado a la cuenta');
