<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Cuentas de un COMPLEMENTO.
//  GET params: complemento_id (uuid de correo_extensiones_espacio)
//  Devuelve el complemento (con su licencia), TODAS las cuentas de esa
//  licencia (para poder asignarlas), las cuentas que YA tienen el complemento
//  asignado, y el cupo (usadas/disponibles = complemento.cantidad_cuentas).
//  Alimenta el modal de asignacion del tab "Complementos" en viewProducto.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$complementoId = trim((string)($_GET['complemento_id'] ?? ''));
if (!preg_match(UUID_RE, $complementoId)) {
    json_error('Identificador de complemento no valido', 422);
}

$pdo = Database::get();

// Complemento + su licencia (el tipo se muestra en el modal).
$stmt = $pdo->prepare(
    'SELECT e.id, e.nombre, e.cantidad_cuentas, e.valor, e.fecha_inicio,
            e.cuenta_correo_id, e.licencia_id, l.tipo_licencia
       FROM public.correo_extensiones_espacio e
       LEFT JOIN public.correo_licencias l ON l.id = e.licencia_id
      WHERE e.id = :id'
);
$stmt->execute([':id' => $complementoId]);
$complemento = $stmt->fetch();
if (!$complemento) {
    json_error('Complemento no encontrado', 404);
}
if (empty($complemento['licencia_id'])) {
    json_error('Este complemento no esta asociado a una licencia', 409);
}

// Todas las cuentas (buzones) de la licencia del complemento.
$stmt = $pdo->prepare(
    'SELECT id, nombre, apellidos, correo, tipo_cuenta
       FROM public.correo_licencia_cuentas
      WHERE licencia_id = :lic
      ORDER BY created_at ASC'
);
$stmt->execute([':lic' => $complemento['licencia_id']]);
$cuentas = $stmt->fetchAll();

// Cuentas que YA tienen este complemento asignado.
$stmt = $pdo->prepare(
    'SELECT ac.id, ac.cuenta_id, ac.created_at,
            cu.nombre, cu.apellidos, cu.correo, cu.tipo_cuenta
       FROM public.correo_complemento_cuentas ac
       JOIN public.correo_licencia_cuentas cu ON cu.id = ac.cuenta_id
      WHERE ac.complemento_id = :id
      ORDER BY ac.created_at ASC'
);
$stmt->execute([':id' => $complementoId]);
$asignadas = $stmt->fetchAll();

$usadas = count($asignadas);
$total  = (int)$complemento['cantidad_cuentas'];

json_ok([
    'complemento' => $complemento,
    'cuentas'     => $cuentas,
    'asignadas'   => $asignadas,
    'usadas'      => $usadas,
    'disponibles' => max(0, $total - $usadas),
]);
