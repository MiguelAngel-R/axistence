<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Validacion y normalizacion compartida (alta y edicion).
//  Recibe el cuerpo JSON y devuelve un arreglo listo para persistir, con
//  la logica diferenciada de Persona Natural / Persona Juridica. Ante un
//  dato invalido responde con json_error(...) (corta la ejecucion).
//
//  Claves devueltas (todas presentes; las no aplicables van a null):
//    tipo_cliente, razon_social, nombres, apellidos, nombre_razon_social,
//    tipo_identificacion, numero_identificacion, email_general,
//    telefono_principal, direccion, estado
// =====================================================================

function validar_cliente(array $in): array
{
    $tipoCliente = trim((string)($in['tipo_cliente'] ?? ''));
    $numIdent    = trim((string)($in['numero_identificacion'] ?? ''));
    $email       = trim((string)($in['email_general'] ?? ''));
    $telefono    = trim((string)($in['telefono_principal'] ?? ''));
    $direccion   = trim((string)($in['direccion'] ?? ''));
    $estado      = (string)($in['estado'] ?? 'Activo');

    // --- Comunes ----------------------------------------------------
    if (!in_array($tipoCliente, ['Persona Natural', 'Persona Jurídica'], true)) {
        json_error('El tipo de cliente no es valido', 422);
    }
    if ($numIdent === '') {
        json_error('El numero de identificacion es obligatorio', 422);
    }
    if (mb_strlen($numIdent) > 50) {
        json_error('El numero de identificacion es demasiado largo (maximo 50 caracteres)', 422);
    }
    if (!in_array($estado, ['Activo', 'Inactivo'], true)) {
        json_error('El estado no es valido', 422);
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('El correo no es valido', 422);
    }
    if ($email !== '' && mb_strlen($email) > 100) {
        json_error('El correo es demasiado largo (maximo 100 caracteres)', 422);
    }
    if ($telefono !== '' && mb_strlen($telefono) > 50) {
        json_error('El telefono es demasiado largo (maximo 50 caracteres)', 422);
    }

    // --- Condicionales al tipo de cliente ---------------------------
    $razonSocial = null;
    $nombres     = null;
    $apellidos   = null;

    if ($tipoCliente === 'Persona Natural') {
        $nombres   = trim((string)($in['nombres'] ?? ''));
        $apellidos = trim((string)($in['apellidos'] ?? ''));
        $tipoIdent = trim((string)($in['tipo_identificacion'] ?? ''));

        if ($nombres === '' || $apellidos === '') {
            json_error('Los nombres y apellidos son obligatorios', 422);
        }
        if (mb_strlen($nombres) > 100 || mb_strlen($apellidos) > 100) {
            json_error('Los nombres o apellidos son demasiado largos (maximo 100 caracteres)', 422);
        }
        if (!in_array($tipoIdent, ['Cédula', 'NIT'], true)) {
            json_error('El tipo de identificacion no es valido', 422);
        }
        $nombreVisible = $nombres . ' ' . $apellidos;
    } else {
        // Persona Juridica
        $razonSocial = trim((string)($in['razon_social'] ?? ''));
        if ($razonSocial === '') {
            json_error('La razon social es obligatoria', 422);
        }
        if (mb_strlen($razonSocial) > 200) {
            json_error('La razon social es demasiado larga (maximo 200 caracteres)', 422);
        }
        // El tipo de identificacion de una persona juridica es fijo: NIT.
        $tipoIdent     = 'NIT';
        $nombreVisible = $razonSocial;
    }

    return [
        'tipo_cliente'          => $tipoCliente,
        'razon_social'          => $razonSocial,
        'nombres'               => $nombres,
        'apellidos'             => $apellidos,
        'nombre_razon_social'   => $nombreVisible,
        'tipo_identificacion'   => $tipoIdent,
        'numero_identificacion' => $numIdent,
        'email_general'         => $email !== '' ? $email : null,
        'telefono_principal'    => $telefono !== '' ? $telefono : null,
        'direccion'             => $direccion !== '' ? $direccion : null,
        'estado'                => $estado,
    ];
}
