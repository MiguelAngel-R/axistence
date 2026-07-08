<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios de Sistema - Actualizar (edicion de alcance restringido).
//  POST JSON: { id, nombres, apellidos, correo, rol_id }
//  Por diseno, este endpoint SOLO modifica nombres, apellidos, correo y rol.
//  La contrasena se cambia en cambiar_clave.php; el estado en cambiar_estado.php;
//  el username NO es editable. nombre_completo se recalcula (derivado).
//    valida -> existencia -> unicidad de correo -> actualiza -> audita.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Usuarios', 'editar');

$in        = body_json();
$id        = trim((string)($in['id'] ?? ''));
$nombres   = trim((string)($in['nombres'] ?? ''));
$apellidos = trim((string)($in['apellidos'] ?? ''));
$correo    = trim((string)($in['correo'] ?? ''));
$rolId     = trim((string)($in['rol_id'] ?? ''));

$UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// --- Validaciones ---------------------------------------------------
if (!preg_match($UUID, $id)) {
    json_error('Identificador no valido', 422);
}
$faltan = campos_faltantes(
    ['nombres' => $nombres, 'apellidos' => $apellidos, 'correo' => $correo, 'rol_id' => $rolId],
    ['nombres', 'apellidos', 'correo', 'rol_id']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombres) > 100 || mb_strlen($apellidos) > 100) {
    json_error('Nombres y apellidos admiten maximo 100 caracteres', 422);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    json_error('El correo no es valido', 422);
}
if (!preg_match($UUID, $rolId)) {
    json_error('El rol seleccionado no es valido', 422);
}

$pdo = Database::get();

// El usuario debe existir (guardamos su estado previo para la auditoria).
$stmt = $pdo->prepare(
    'SELECT nombres, apellidos, correo, rol_id
       FROM public.usuarios_internos
      WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Usuario no encontrado', 404);
}

// El rol debe existir.
$stmt = $pdo->prepare('SELECT nombre FROM public.roles WHERE id = :id');
$stmt->execute([':id' => $rolId]);
$rolNombre = $stmt->fetchColumn();
if ($rolNombre === false) {
    json_error('El rol seleccionado no existe', 422);
}

// El correo debe ser unico (excluyendo el propio usuario).
$stmt = $pdo->prepare('SELECT 1 FROM public.usuarios_internos WHERE correo = :c AND id <> :id');
$stmt->execute([':c' => $correo, ':id' => $id]);
if ($stmt->fetchColumn()) {
    json_error('Ya existe otro usuario con ese correo', 409);
}

// --- Actualizacion (nombre_completo se recalcula) -------------------
$nombreCompleto = $nombres . ' ' . $apellidos;
$stmt = $pdo->prepare(
    'UPDATE public.usuarios_internos
        SET nombres = :nom, apellidos = :ape, nombre_completo = :nc,
            correo = :c, rol_id = :r
      WHERE id = :id'
);
$stmt->execute([
    ':nom' => $nombres,
    ':ape' => $apellidos,
    ':nc'  => $nombreCompleto,
    ':c'   => $correo,
    ':r'   => $rolId,
    ':id'  => $id,
]);

// --- Auditoria ------------------------------------------------------
registrar_auditoria(
    'MODIFICAR',
    'Usuarios',
    'Actualizo el usuario de sistema ' . $correo,
    $id,
    [
        'nombres'   => $actual['nombres'],
        'apellidos' => $actual['apellidos'],
        'correo'    => $actual['correo'],
        'rol_id'    => $actual['rol_id'],
    ],
    [
        'nombres'   => $nombres,
        'apellidos' => $apellidos,
        'correo'    => $correo,
        'rol_id'    => $rolId,
    ]
);

json_ok(['id' => $id], 'Usuario actualizado correctamente');
