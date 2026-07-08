<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios de Sistema - Crear (alta).
//  POST JSON: { nombres, apellidos, username, correo, telefono?, contrasena, rol_id }
//  Reglas:
//    - El estado NO se pide: todo usuario nuevo nace 'Activo'.
//    - La contrasena se almacena con SHA1 (hash_clave, ver helpers/passwords.php).
//    - nombre_completo se DERIVA ("nombres apellidos") para sesion/topbar.
//  Flujo: valida -> unicidad (username/correo) -> inserta -> audita CREAR.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Usuarios', 'crear');

$in       = body_json();
$nombres  = trim((string)($in['nombres'] ?? ''));
$apellidos = trim((string)($in['apellidos'] ?? ''));
$username = trim((string)($in['username'] ?? ''));
$correo   = trim((string)($in['correo'] ?? ''));
$telefono = trim((string)($in['telefono'] ?? ''));
$clave    = (string)($in['contrasena'] ?? '');
$rolId    = trim((string)($in['rol_id'] ?? ''));

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['nombres' => $nombres, 'apellidos' => $apellidos, 'username' => $username,
     'correo' => $correo, 'contrasena' => $clave, 'rol_id' => $rolId],
    ['nombres', 'apellidos', 'username', 'correo', 'contrasena', 'rol_id']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombres) > 100 || mb_strlen($apellidos) > 100) {
    json_error('Nombres y apellidos admiten maximo 100 caracteres', 422);
}
// username: 3..50, letras/numeros/._- (sin espacios).
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    json_error('El nombre de usuario debe tener 3 a 50 caracteres (letras, numeros, punto, guion o guion bajo)', 422);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    json_error('El correo no es valido', 422);
}
if (mb_strlen($clave) < 6) {
    json_error('La contrasena debe tener al menos 6 caracteres', 422);
}
if ($telefono !== '' && mb_strlen($telefono) > 50) {
    json_error('El telefono es demasiado largo (maximo 50 caracteres)', 422);
}
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rolId)) {
    json_error('El rol seleccionado no es valido', 422);
}

$pdo = Database::get();

// El rol debe existir.
$stmt = $pdo->prepare('SELECT nombre FROM public.roles WHERE id = :id');
$stmt->execute([':id' => $rolId]);
$rolNombre = $stmt->fetchColumn();
if ($rolNombre === false) {
    json_error('El rol seleccionado no existe', 422);
}

// El correo debe ser unico.
$stmt = $pdo->prepare('SELECT 1 FROM public.usuarios_internos WHERE correo = :c');
$stmt->execute([':c' => $correo]);
if ($stmt->fetchColumn()) {
    json_error('Ya existe un usuario con ese correo', 409);
}

// El username debe ser unico.
$stmt = $pdo->prepare('SELECT 1 FROM public.usuarios_internos WHERE username = :u');
$stmt->execute([':u' => $username]);
if ($stmt->fetchColumn()) {
    json_error('Ya existe un usuario con ese nombre de usuario', 409);
}

// --- Insercion ------------------------------------------------------
$nombreCompleto = $nombres . ' ' . $apellidos;   // derivado para sesion/topbar
$hash           = hash_clave($clave);            // SHA1
$telefonoBd     = $telefono !== '' ? $telefono : null;

$stmt = $pdo->prepare(
    'INSERT INTO public.usuarios_internos
         (nombres, apellidos, nombre_completo, username, correo, telefono, contrasena, rol_id, estado)
     VALUES (:nom, :ape, :nc, :u, :c, :t, :p, :r, :Activo)
     RETURNING id'
);
$stmt->execute([
    ':nom'    => $nombres,
    ':ape'    => $apellidos,
    ':nc'     => $nombreCompleto,
    ':u'      => $username,
    ':c'      => $correo,
    ':t'      => $telefonoBd,
    ':p'      => $hash,
    ':r'      => $rolId,
    ':Activo' => 'Activo',
]);
$id = $stmt->fetchColumn();

// --- Auditoria (nunca se registra la contrasena) --------------------
registrar_auditoria(
    'CREAR',
    'Usuarios',
    'Creo el usuario de sistema ' . $username . ' (' . $correo . ')',
    $id,
    null,
    [
        'nombres'   => $nombres,
        'apellidos' => $apellidos,
        'username'  => $username,
        'correo'    => $correo,
        'telefono'  => $telefonoBd,
        'rol'       => $rolNombre,
        'estado'    => 'Activo',
    ]
);

json_ok(['id' => $id], 'Usuario creado correctamente');
