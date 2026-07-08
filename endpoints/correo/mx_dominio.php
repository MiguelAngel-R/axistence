<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Registros MX de un dominio (para el select del formulario).
//  GET params: dominio_id (uuid)
//  Respuesta data: [ { id, valor, prioridad }, ... ]  (solo tipo MX)
//  Los MX se registran en el modulo de Dominios (dominio_registros_dns);
//  aqui solo se listan para asociar la relacion de correo a uno de ellos.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$dominioId = trim((string)($_GET['dominio_id'] ?? ''));
if (!preg_match(UUID_RE, $dominioId)) {
    json_error('El dominio seleccionado no es valido', 422);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    "SELECT id, valor, prioridad
       FROM public.dominio_registros_dns
      WHERE dominio_id = :d AND tipo_registro = 'MX'
      ORDER BY prioridad NULLS LAST, valor ASC"
);
$stmt->execute([':d' => $dominioId]);

json_ok($stmt->fetchAll());
