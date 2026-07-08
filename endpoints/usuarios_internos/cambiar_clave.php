<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios de Sistema - Cambiar contrasena (modal independiente).
//  POST JSON: { id, contrasena }
//  La contrasena se almacena con SHA1 (hash_clave, ver helpers/passwords.php).
//    valida -> existencia -> actualiza hash -> audita MODIFICAR.
//  La contrasena NUNCA se guarda ni se registra en texto plano.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Usuarios', 'editar');

$in    = body_json();
$id    = trim((string)($in['id'] ?? ''));
$clave = (string)($in['contrasena'] ?? '');

$UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// --- Validaciones ---------------------------------------------------
if (!preg_match($UUID, $id)) {
    json_error('Identificador no valido', 422);
}
if (mb_strlen($clave) < 6) {
    json_error('La contrasena debe tener al menos 6 caracteres', 422);
}

$pdo = Database::get();

// El usuario debe existir (obtenemos el correo para la descripcion del log).
$stmt = $pdo->prepare('SELECT correo FROM public.usuarios_internos WHERE id = :id');
$stmt->execute([':id' => $id]);
$correo = $stmt->fetchColumn();
if ($correo === false) {
    json_error('Usuario no encontrado', 404);
}

// --- Actualizacion del hash -----------------------------------------
$stmt = $pdo->prepare('UPDATE public.usuarios_internos SET contrasena = :p WHERE id = :id');
$stmt->execute([':p' => hash_clave($clave), ':id' => $id]);

// --- Auditoria (sin exponer la contrasena) --------------------------
registrar_auditoria(
    'MODIFICAR',
    'Usuarios',
    'Cambio la contrasena del usuario de sistema ' . $correo,
    $id,
    null,
    ['contrasena' => '********']   // marcador: nunca el valor real
);

json_ok(['id' => $id], 'Contrasena actualizada correctamente');
