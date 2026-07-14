<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Crear.
//  POST JSON (comun):
//    { tipo_cliente, numero_identificacion, email_general?,
//      telefono_principal?, direccion?, estado }
//  Segun tipo_cliente:
//    Persona Natural  -> { nombres, apellidos, tipo_identificacion }
//    Persona Juridica -> { razon_social }  (tipo_identificacion se fija a NIT)
//
//  El nombre visible 'nombre_razon_social' se DERIVA aqui (razon social, o
//  "nombres apellidos") y se conserva porque el resto de modulos referencia
//  al cliente por ese campo.
//    valida -> inserta con consulta preparada -> audita CREAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_validacion.php';

solo_metodo('POST');
requiere_permiso('Clientes', 'crear');

$in = body_json();

$datos = validar_cliente($in);   // normaliza + valida (natural/juridica)

$pdo = Database::get();

// --- Insercion ------------------------------------------------------
$stmt = $pdo->prepare(
    'INSERT INTO public.clientes
        (tipo_cliente, razon_social, nombres, apellidos, nombre_razon_social,
         tipo_identificacion, numero_identificacion, email_general,
         telefono_principal, direccion, estado)
     VALUES
        (:tipo_cliente, :razon_social, :nombres, :apellidos, :nombre_razon_social,
         :tipo_ident, :num_ident, :email, :telefono, :direccion, :estado)
     RETURNING id, created_at'
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
]);
$fila = $stmt->fetch(PDO::FETCH_ASSOC);
$id   = $fila['id'];

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'CREAR',
    'Clientes',
    'Creo el cliente ' . $datos['nombre_razon_social'],
    $id,
    null,
    $datos
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que inserten
// la fila sin recargar. Se emite SOLO tras el commit y la auditoria: el evento
// viaja con los datos de la fila para no volver a consultar la BD en el cliente.
notificar_socket('clientes', 'cliente:creado', array_merge($datos, [
    'id'         => $id,
    'created_at' => $fila['created_at'],
]));

json_ok(['id' => $id], 'Cliente creado correctamente');
