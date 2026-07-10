<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Actualizar.
//  POST JSON: { id, ...mismos campos que crear (natural / juridica) }
//    valida -> comprueba existencia -> actualiza -> audita MODIFICAR
//    guardando datos anteriores y nuevos -> JSON.
//  El nombre visible 'nombre_razon_social' se recalcula (ver _validacion.php).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_validacion.php';

solo_metodo('POST');
requiere_permiso('Clientes', 'editar');

$in = body_json();
$id = trim((string)($in['id'] ?? ''));

$UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($UUID, $id)) {
    json_error('Identificador no valido', 422);
}

$datos = validar_cliente($in);   // normaliza + valida (natural/juridica)

$pdo = Database::get();

// El cliente debe existir (guardamos su estado previo para la auditoria).
$stmt = $pdo->prepare(
    'SELECT tipo_cliente, razon_social, nombres, apellidos, nombre_razon_social,
            tipo_identificacion, numero_identificacion, email_general,
            telefono_principal, direccion, estado, created_at
       FROM public.clientes
      WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Cliente no encontrado', 404);
}

// --- Actualizacion --------------------------------------------------
$stmt = $pdo->prepare(
    'UPDATE public.clientes
        SET tipo_cliente          = :tipo_cliente,
            razon_social          = :razon_social,
            nombres               = :nombres,
            apellidos             = :apellidos,
            nombre_razon_social   = :nombre_razon_social,
            tipo_identificacion   = :tipo_ident,
            numero_identificacion = :num_ident,
            email_general         = :email,
            telefono_principal    = :telefono,
            direccion             = :direccion,
            estado                = :estado
      WHERE id = :id'
);
$stmt->execute([
    ':tipo_cliente'        => $datos['tipo_cliente'],
    ':razon_social'        => $datos['razon_social'],
    ':nombres'             => $datos['nombres'],
    ':apellidos'           => $datos['apellidos'],
    ':nombre_razon_social' => $datos['nombre_razon_social'],
    ':tipo_ident'          => $datos['tipo_identificacion'],
    ':num_ident'           => $datos['numero_identificacion'],
    ':email'               => $datos['email_general'],
    ':telefono'            => $datos['telefono_principal'],
    ':direccion'           => $datos['direccion'],
    ':estado'              => $datos['estado'],
    ':id'                  => $id,
]);

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'MODIFICAR',
    'Clientes',
    'Actualizo el cliente ' . $datos['nombre_razon_social'],
    $id,
    $actual,
    $datos
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores para que reemplacen la fila sin recargar. El
// created_at se conserva (viene de la fila existente) para pintar la columna
// "Creado" sin volver a la BD.
notificar_socket('clientes', 'cliente:actualizado', array_merge($datos, [
    'id'         => $id,
    'created_at' => $actual['created_at'],
]));

json_ok(['id' => $id], 'Cliente actualizado correctamente');
