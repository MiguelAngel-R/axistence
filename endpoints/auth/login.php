<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');

$in     = body_json();
$correo = trim((string)($in['correo'] ?? ''));
$clave  = (string)($in['contrasena'] ?? '');

$faltan = campos_faltantes(['correo' => $correo, 'contrasena' => $clave], ['correo', 'contrasena']);
if ($faltan) {
    json_error('Correo y contrasena son obligatorios', 422, ['faltantes' => $faltan]);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT u.id, u.nombre_completo, u.correo, u.contrasena, u.estado,
            r.id AS rol_id, r.nombre AS rol
       FROM public.usuarios_internos u
       JOIN public.roles r ON r.id = u.rol_id
      WHERE u.correo = :correo'
);
$stmt->execute([':correo' => $correo]);
$user = $stmt->fetch();

// Mensaje generico para no revelar si el correo existe.
// verificar_clave soporta el hash SHA1 actual y los bcrypt heredados.
if (!$user || !verificar_clave($clave, $user['contrasena'])) {
    json_error('Credenciales invalidas', 401);
}

if ($user['estado'] !== 'Activo') {
    json_error('Usuario inactivo. Contacta al administrador.', 403);
}

// Registrar la ultima sesion iniciada (se muestra en el listado de usuarios).
$pdo->prepare('UPDATE public.usuarios_internos SET last_login = CURRENT_TIMESTAMP WHERE id = :id')
    ->execute([':id' => $user['id']]);

// Cargar permisos del rol en la sesion.
$permStmt = $pdo->prepare(
    'SELECT modulo, puede_ver, puede_crear, puede_editar, puede_eliminar
       FROM public.rol_permisos
      WHERE rol_id = :rol_id'
);
$permStmt->execute([':rol_id' => $user['rol_id']]);

$permisos = [];
foreach ($permStmt->fetchAll() as $p) {
    $permisos[$p['modulo']] = [
        'ver'      => (bool)$p['puede_ver'],
        'crear'    => (bool)$p['puede_crear'],
        'editar'   => (bool)$p['puede_editar'],
        'eliminar' => (bool)$p['puede_eliminar'],
    ];
}

// Prevenir fijacion de sesion.
session_regenerate_id(true);

$_SESSION['usuario'] = [
    'id'              => $user['id'],
    'nombre_completo' => $user['nombre_completo'],
    'correo'          => $user['correo'],
    'rol_id'          => $user['rol_id'],
    'rol'             => $user['rol'],
    'permisos'        => $permisos,
];

registrar_auditoria('AUTENTICACION', 'Autenticacion', 'Inicio de sesion: ' . $user['correo'], $user['id']);

json_ok([
    'id'              => $user['id'],
    'nombre_completo' => $user['nombre_completo'],
    'correo'          => $user['correo'],
    'rol'             => $user['rol'],
    'permisos'        => $permisos,
], 'Sesion iniciada');
