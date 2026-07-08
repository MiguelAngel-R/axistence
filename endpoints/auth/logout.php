<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');

$u = usuario_actual();
if ($u !== null) {
    registrar_auditoria('AUTENTICACION', 'Autenticacion', 'Cierre de sesion: ' . ($u['correo'] ?? ''), $u['id'] ?? null);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

json_ok(null, 'Sesion cerrada');
