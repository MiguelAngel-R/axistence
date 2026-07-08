<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

// Devuelve el estado de la sesion actual. Usado por el front al cargar
// para saber si redirige al login o muestra la app.
$u = usuario_actual();

if ($u === null) {
    json_response(['ok' => true, 'autenticado' => false, 'data' => null]);
}

json_response([
    'ok'          => true,
    'autenticado' => true,
    'data'        => [
        'id'              => $u['id'],
        'nombre_completo' => $u['nombre_completo'],
        'correo'          => $u['correo'],
        'rol'             => $u['rol'],
        'permisos'        => $u['permisos'],
    ],
]);
