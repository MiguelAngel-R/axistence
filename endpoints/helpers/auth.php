<?php
declare(strict_types=1);

// =====================================================================
//  Helpers de autenticacion y control de acceso por rol/permiso.
//  El usuario y sus permisos se cargan en $_SESSION al iniciar sesion.
//  Estructura de permisos en sesion:
//    $_SESSION['usuario']['permisos'][<modulo>] = [
//        'ver' => bool, 'crear' => bool, 'editar' => bool, 'eliminar' => bool
//    ]
// =====================================================================

function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

// Corta la ejecucion con 401 si no hay sesion. Devuelve el usuario si la hay.
function requiere_login(): array
{
    $u = usuario_actual();
    if ($u === null) {
        json_error('No autenticado', 401);
    }
    return $u;
}

// $accion: 'ver' | 'crear' | 'editar' | 'eliminar'
function tiene_permiso(string $modulo, string $accion): bool
{
    $u = usuario_actual();
    if ($u === null) {
        return false;
    }
    // El rol Administrador tiene acceso total.
    if (($u['rol'] ?? '') === 'Administrador') {
        return true;
    }
    return !empty($u['permisos'][$modulo][$accion]);
}

// Corta con 401 si no hay sesion o 403 si no tiene el permiso.
function requiere_permiso(string $modulo, string $accion): array
{
    $u = requiere_login();
    if (!tiene_permiso($modulo, $accion)) {
        json_error('No tienes permiso para realizar esta accion', 403);
    }
    return $u;
}

// Restringe un endpoint a un metodo HTTP concreto.
function solo_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($metodo)) {
        json_error('Metodo no permitido', 405);
    }
}
