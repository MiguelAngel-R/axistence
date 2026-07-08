<?php
declare(strict_types=1);

// =====================================================================
//  Arranque de sesion unificado para AXISTENCE.
//  Usado tanto por los endpoints (bootstrap.php) como por el enrutador
//  del frontend (index.php), para que ambos compartan la MISMA sesion
//  (misma cookie AXISTENCE_SID) y el front pueda proteger las vistas.
// =====================================================================

function iniciar_sesion_axistence(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('AXISTENCE_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
