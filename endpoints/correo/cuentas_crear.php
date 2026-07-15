<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Crear una cuenta (buzon) para una LICENCIA.
//  POST JSON: { licencia_id, nombre, apellidos, usuario_correo, clave }
//  El correo completo se arma como usuario_correo@<dominio de la relacion>
//  (el dominio NO se recibe: se toma de cuentas_correo -> dominios).
//  No se puede exceder la cantidad_cuentas de la licencia. La clave se guarda
//  en TEXTO PLANO por requerimiento del proyecto.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Correo', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

const TIPOS_CUENTA = ['Usuario', 'Administrador'];

$in         = body_json();
$licenciaId = trim((string)($in['licencia_id'] ?? ''));
$nombre     = trim((string)($in['nombre'] ?? ''));
$apellidos  = trim((string)($in['apellidos'] ?? ''));
$usuario    = strtolower(trim((string)($in['usuario_correo'] ?? '')));
$clave      = (string)($in['clave'] ?? '');
$tipoCuenta = trim((string)($in['tipo_cuenta'] ?? 'Usuario'));

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $licenciaId)) {
    json_error('La licencia seleccionada no es valida', 422);
}
$faltan = campos_faltantes(
    ['nombre' => $nombre, 'apellidos' => $apellidos, 'usuario_correo' => $usuario, 'clave' => $clave],
    ['nombre', 'apellidos', 'usuario_correo', 'clave']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 100) {
    json_error('El nombre es demasiado largo (maximo 100 caracteres)', 422);
}
if (mb_strlen($apellidos) > 100) {
    json_error('Los apellidos son demasiado largos (maximo 100 caracteres)', 422);
}
if (mb_strlen($usuario) > 100) {
    json_error('El usuario del correo es demasiado largo (maximo 100 caracteres)', 422);
}
if (!preg_match('/^[a-z0-9._%+\-]+$/', $usuario)) {
    json_error('El usuario del correo solo admite letras, numeros y los signos . _ % + -', 422);
}
if (mb_strlen($clave) < 4 || mb_strlen($clave) > 255) {
    json_error('La contrasena debe tener entre 4 y 255 caracteres', 422);
}
if (!in_array($tipoCuenta, TIPOS_CUENTA, true)) {
    json_error('El tipo de cuenta no es valido', 422);
}

$pdo = Database::get();

// Licencia + dominio (el correo se arma con este dominio).
$stmt = $pdo->prepare(
    'SELECT l.id, l.cantidad_cuentas, l.cuenta_correo_id, d.nombre_dominio AS dominio
       FROM public.correo_licencias l
       JOIN public.cuentas_correo cc ON cc.id = l.cuenta_correo_id
       JOIN public.dominios d        ON d.id  = cc.dominio_id
      WHERE l.id = :id'
);
$stmt->execute([':id' => $licenciaId]);
$lic = $stmt->fetch();
if (!$lic) {
    json_error('La licencia no existe', 404);
}

$correo = $usuario . '@' . strtolower((string)$lic['dominio']);

$pdo->beginTransaction();
try {
    // Cupo: no exceder la cantidad de la licencia (recuento dentro de la tx).
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM public.correo_licencia_cuentas WHERE licencia_id = :id');
    $stmt->execute([':id' => $licenciaId]);
    $usadas = (int)$stmt->fetchColumn();
    if ($usadas >= (int)$lic['cantidad_cuentas']) {
        $pdo->rollBack();
        json_error('La licencia ya alcanzo su limite de ' . (int)$lic['cantidad_cuentas'] . ' cuenta(s)', 409);
    }

    // Solo una cuenta administradora por licencia: si se pide crear otra admin y
    // ya existe una, se rechaza (recuento dentro de la tx para evitar carreras).
    if ($tipoCuenta === 'Administrador') {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM public.correo_licencia_cuentas
              WHERE licencia_id = :id AND tipo_cuenta = 'Administrador'"
        );
        $stmt->execute([':id' => $licenciaId]);
        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            json_error('La licencia ya tiene una cuenta administradora', 409);
        }
    }

    // Correo unico (case-insensitive).
    $stmt = $pdo->prepare('SELECT 1 FROM public.correo_licencia_cuentas WHERE lower(correo) = :c');
    $stmt->execute([':c' => $correo]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        json_error('Ya existe una cuenta con el correo ' . $correo, 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO public.correo_licencia_cuentas
            (licencia_id, nombre, apellidos, usuario_correo, correo, clave, tipo_cuenta)
         VALUES (:lic, :nom, :ape, :usr, :cor, :cla, :tip)
         RETURNING id, nombre, apellidos, usuario_correo, correo, clave, tipo_cuenta, created_at'
    );
    $stmt->execute([
        ':lic' => $licenciaId, ':nom' => $nombre, ':ape' => $apellidos,
        ':usr' => $usuario, ':cor' => $correo, ':cla' => $clave, ':tip' => $tipoCuenta,
    ]);
    $cuenta = $stmt->fetch();

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    // Choque con el indice unico (carrera entre dos altas del mismo correo).
    if ($e->getCode() === '23505') {
        json_error('Ya existe una cuenta con el correo ' . $correo, 409);
    }
    throw $e;
}

// La clave NO se registra en la auditoria.
registrar_auditoria(
    'CREAR',
    'Correo',
    'Creo la cuenta de correo ' . $correo,
    (string)$cuenta['id'],
    null,
    [
        'licencia_id' => $licenciaId,
        'correo'      => $correo,
        'nombre'      => $nombre,
        'apellidos'   => $apellidos,
        'tipo_cuenta' => $tipoCuenta,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de esta relacion de
// correo para que agreguen la fila de la cuenta al drill-down de la licencia y
// actualicen el contador (usadas/total) sin recargar ni volver a consultar la
// BD. El evento viaja con cuenta_correo_id (la relacion, para filtrar por
// detalle) y licencia_id (la licencia del drill-down), ademas de la fila de la
// cuenta. Se emite SOLO tras el commit y la auditoria (fire-and-forget).
notificar_socket('correo', 'cuenta:creada', array_merge($cuenta, [
    'licencia_id'      => $licenciaId,
    'cuenta_correo_id' => $lic['cuenta_correo_id'],
]));

json_ok(['cuenta' => $cuenta], 'Cuenta de correo creada correctamente');
