<?php
declare(strict_types=1);

// =====================================================================
//  Hosting - Eliminar un hosting (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE (caen en cascada sus
//    relaciones con clientes y dominios y sus notas) -> audita ELIMINAR
//    -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Hosting', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// Nombre visible para la auditoria: "espacio en <referencia del VPS>".
$stmt = $pdo->prepare(
    'SELECT h.espacio_asignado, v.referencia_vps
       FROM public.hosting h
       JOIN public.vps_servidores v ON v.id = h.vps_id
      WHERE h.id = :id'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetch();
if (!$hosting) {
    json_error('Hosting no encontrado', 404);
}
$nombre = $hosting['espacio_asignado'] . ' en ' . $hosting['referencia_vps'];

try {
    $pdo->prepare('DELETE FROM public.hosting WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el hosting porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Hosting',
    'Elimino el hosting ' . $nombre,
    $id,
    ['espacio_asignado' => $hosting['espacio_asignado'], 'vps' => $hosting['referencia_vps']],
    null
);

json_ok(['id' => $id], 'Hosting eliminado correctamente');
