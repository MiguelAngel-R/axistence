<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Agregar una persona de contacto a un cliente.
//  POST JSON:
//    { cliente_id, nombres, apellidos, cargo_puesto?, email,
//      telefono_movil?, telefono_fijo?, es_contacto_principal? }
//  El 'nombre_completo' se DERIVA aqui ("nombres apellidos") y se guarda,
//  porque el detalle y el ordenamiento lo leen.
//  Inserta en clientes_personas_contacto. Si el contacto se marca como
//  principal, se desmarca a los demas del mismo cliente (solo uno principal).
//    valida -> inserta con consulta preparada -> audita CREAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Clientes', 'editar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in         = body_json();
$clienteId  = trim((string)($in['cliente_id'] ?? ''));
$nombres    = trim((string)($in['nombres'] ?? ''));
$apellidos  = trim((string)($in['apellidos'] ?? ''));
$cargo      = trim((string)($in['cargo_puesto'] ?? ''));
$email      = trim((string)($in['email'] ?? ''));
$movil      = trim((string)($in['telefono_movil'] ?? ''));
$fijo       = trim((string)($in['telefono_fijo'] ?? ''));
$principal  = !empty($in['es_contacto_principal']);

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $clienteId)) {
    json_error('El cliente no es valido', 422);
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

// El cliente debe existir (evita insertar contactos huerfanos).
$stmt = $pdo->prepare('SELECT nombre_razon_social FROM public.clientes WHERE id = :id');
$stmt->execute([':id' => $clienteId]);
$cliente = $stmt->fetch();
if (!$cliente) {
    json_error('El cliente no existe', 404);
}

$pdo->beginTransaction();
try {
    // Solo puede haber un contacto principal por cliente: si el nuevo lo es,
    // se desmarca a los demas dentro de la misma transaccion.
    if ($principal) {
        $stmt = $pdo->prepare(
            'UPDATE public.clientes_personas_contacto
                SET es_contacto_principal = false
              WHERE cliente_id = :id AND es_contacto_principal = true'
        );
        $stmt->execute([':id' => $clienteId]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO public.clientes_personas_contacto
            (cliente_id, nombres, apellidos, nombre_completo, cargo_puesto, email,
             telefono_movil, telefono_fijo, es_contacto_principal)
         VALUES
            (:cliente, :nombres, :apellidos, :nombre, :cargo, :email, :movil, :fijo, :principal)
         RETURNING id, nombres, apellidos, nombre_completo, cargo_puesto, email,
                   telefono_movil, telefono_fijo, es_contacto_principal'
    );
    $stmt->execute([
        ':cliente'   => $clienteId,
        ':nombres'   => $nombres,
        ':apellidos' => $apellidos,
        ':nombre'    => $nombre,
        ':cargo'     => $cargo !== '' ? $cargo : null,
        ':email'     => $email,
        ':movil'     => $movil !== '' ? $movil : null,
        ':fijo'      => $fijo !== '' ? $fijo : null,
        ':principal' => $principal ? 't' : 'f',
    ]);
    $contacto = $stmt->fetch();

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'CREAR',
    'Clientes',
    'Agrego el contacto ' . $nombre . ' al cliente ' . $cliente['nombre_razon_social'],
    $clienteId,
    null,
    [
        'contacto_id'           => $contacto['id'],
        'nombres'               => $nombres,
        'apellidos'             => $apellidos,
        'email'                 => $email,
        'es_contacto_principal' => $principal,
    ]
);

json_ok(['contacto' => $contacto], 'Contacto agregado correctamente');
