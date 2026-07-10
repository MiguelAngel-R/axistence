<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Actualizar una persona de contacto de un cliente.
//  POST JSON:
//    { contacto_id, nombres, apellidos, cargo_puesto?, email,
//      telefono_movil?, telefono_fijo?, es_contacto_principal? }
//  El 'nombre_completo' se DERIVA aqui ("nombres apellidos") y se guarda,
//  porque el detalle y el ordenamiento lo leen.
//  El 'cliente_id' NO se toma del cliente: se lee del propio contacto en BD
//  (no se puede reasignar un contacto a otro cliente por esta via).
//  Si el contacto se marca como principal, se desmarca a los demas del mismo
//  cliente (solo uno principal).
//    valida -> comprueba existencia (guarda estado previo) -> actualiza con
//    consulta preparada -> audita MODIFICAR (antes/despues) -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Clientes', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in         = body_json();
$contactoId = trim((string)($in['contacto_id'] ?? ''));
$nombres    = trim((string)($in['nombres'] ?? ''));
$apellidos  = trim((string)($in['apellidos'] ?? ''));
$cargo      = trim((string)($in['cargo_puesto'] ?? ''));
$email      = trim((string)($in['email'] ?? ''));
$movil      = trim((string)($in['telefono_movil'] ?? ''));
$fijo       = trim((string)($in['telefono_fijo'] ?? ''));
$principal  = !empty($in['es_contacto_principal']);

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $contactoId)) {
    json_error('El contacto no es valido', 422);
}
if ($nombres === '' || $apellidos === '') {
    json_error('El nombre y el apellido del contacto son obligatorios', 422);
}
if (mb_strlen($nombres) > 100 || mb_strlen($apellidos) > 100) {
    json_error('El nombre o el apellido son demasiado largos (maximo 100 caracteres)', 422);
}
// Nombre visible derivado (lo lee el detalle y el ordenamiento).
$nombre = $nombres . ' ' . $apellidos;
if ($cargo !== '' && mb_strlen($cargo) > 100) {
    json_error('El cargo es demasiado largo (maximo 100 caracteres)', 422);
}
if ($email === '') {
    json_error('El correo del contacto es obligatorio', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('El correo no es valido', 422);
}
if (mb_strlen($email) > 100) {
    json_error('El correo es demasiado largo (maximo 100 caracteres)', 422);
}
if ($movil !== '' && mb_strlen($movil) > 50) {
    json_error('El telefono movil es demasiado largo (maximo 50 caracteres)', 422);
}
if ($fijo !== '' && mb_strlen($fijo) > 50) {
    json_error('El telefono fijo es demasiado largo (maximo 50 caracteres)', 422);
}

$pdo = Database::get();

// El contacto debe existir. Se trae su estado previo (para la auditoria) y el
// cliente al que pertenece (nombre para la descripcion + id para desmarcar
// principales); el cliente_id viene de BD, no del cliente que llama.
$stmt = $pdo->prepare(
    'SELECT pc.cliente_id, pc.nombres, pc.apellidos, pc.nombre_completo,
            pc.cargo_puesto, pc.email, pc.telefono_movil, pc.telefono_fijo,
            pc.es_contacto_principal, cl.nombre_razon_social
       FROM public.clientes_personas_contacto pc
       JOIN public.clientes cl ON cl.id = pc.cliente_id
      WHERE pc.id = :id'
);
$stmt->execute([':id' => $contactoId]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('El contacto no existe', 404);
}
$clienteId = $actual['cliente_id'];

$pdo->beginTransaction();
try {
    // Solo puede haber un contacto principal por cliente: si este se marca
    // principal, se desmarca a los demas (a si mismo se excluye) en la misma
    // transaccion.
    if ($principal) {
        $stmt = $pdo->prepare(
            'UPDATE public.clientes_personas_contacto
                SET es_contacto_principal = false
              WHERE cliente_id = :cliente
                AND id <> :id
                AND es_contacto_principal = true'
        );
        $stmt->execute([':cliente' => $clienteId, ':id' => $contactoId]);
    }

    $stmt = $pdo->prepare(
        'UPDATE public.clientes_personas_contacto
            SET nombres               = :nombres,
                apellidos             = :apellidos,
                nombre_completo       = :nombre,
                cargo_puesto          = :cargo,
                email                 = :email,
                telefono_movil        = :movil,
                telefono_fijo         = :fijo,
                es_contacto_principal = :principal
          WHERE id = :id
         RETURNING id, nombres, apellidos, nombre_completo, cargo_puesto, email,
                   telefono_movil, telefono_fijo, es_contacto_principal'
    );
    $stmt->execute([
        ':nombres'   => $nombres,
        ':apellidos' => $apellidos,
        ':nombre'    => $nombre,
        ':cargo'     => $cargo !== '' ? $cargo : null,
        ':email'     => $email,
        ':movil'     => $movil !== '' ? $movil : null,
        ':fijo'      => $fijo !== '' ? $fijo : null,
        ':principal' => $principal ? 't' : 'f',
        ':id'        => $contactoId,
    ]);
    $contacto = $stmt->fetch();

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'MODIFICAR',
    'Clientes',
    'Actualizo el contacto ' . $nombre . ' del cliente ' . $actual['nombre_razon_social'],
    $clienteId,
    [
        'nombres'               => $actual['nombres'],
        'apellidos'             => $actual['apellidos'],
        'cargo_puesto'          => $actual['cargo_puesto'],
        'email'                 => $actual['email'],
        'telefono_movil'        => $actual['telefono_movil'],
        'telefono_fijo'         => $actual['telefono_fijo'],
        'es_contacto_principal' => $actual['es_contacto_principal'],
    ],
    [
        'contacto_id'           => $contactoId,
        'nombres'               => $nombres,
        'apellidos'             => $apellidos,
        'cargo_puesto'          => $cargo !== '' ? $cargo : null,
        'email'                 => $email,
        'telefono_movil'        => $movil !== '' ? $movil : null,
        'telefono_fijo'         => $fijo !== '' ? $fijo : null,
        'es_contacto_principal' => $principal,
    ]
);

json_ok(['contacto' => $contacto], 'Contacto actualizado correctamente');
