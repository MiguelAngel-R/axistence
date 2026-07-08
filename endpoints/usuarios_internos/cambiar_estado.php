<?php
declare(strict_types=1);

// =====================================================================
//  Usuarios de Sistema - Habilitar / Deshabilitar (accion tipo toggle).
//  POST JSON: { id }
//  Alterna el estado del usuario (Activo <-> Inactivo) sin recargar la
//  pagina. Devuelve el nuevo estado para que el frontend actualice la fila.
//    valida -> existencia -> alterna -> audita CAMBIO_ESTADO.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Usuarios', 'editar');

$in = body_json();
$id = trim((string)($in['id'] ?? ''));

$UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($UUID, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// Estado actual (y correo para el log).
$stmt = $pdo->prepare('SELECT correo, estado FROM public.usuarios_internos WHERE id = :id');
$stmt->execute([':id' => $id]);
$usuario = $stmt->fetch();
if (!$usuario) {
    json_error('Usuario no encontrado', 404);
}

// Evitar que un administrador se deshabilite a si mismo y pierda el acceso.
$actor = usuario_actual();
if (($actor['id'] ?? null) === $id && $usuario['estado'] === 'Activo') {
    json_error('No puedes deshabilitar tu propio usuario', 409);
}

$nuevoEstado = $usuario['estado'] === 'Activo' ? 'Inactivo' : 'Activo';

// --- Alterna el estado ----------------------------------------------
$stmt = $pdo->prepare('UPDATE public.usuarios_internos SET estado = :e WHERE id = :id');
$stmt->execute([':e' => $nuevoEstado, ':id' => $id]);

// --- Auditoria ------------------------------------------------------
registrar_auditoria(
    'CAMBIO_ESTADO',
    'Usuarios',
    ($nuevoEstado === 'Activo' ? 'Habilito' : 'Deshabilito') . ' el usuario de sistema ' . $usuario['correo'],
    $id,
    ['estado' => $usuario['estado']],
    ['estado' => $nuevoEstado]
);

json_ok(['id' => $id, 'estado' => $nuevoEstado], 'Estado actualizado correctamente');
