<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Eliminar un servidor VPS (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE. Caen en cascada sus
//    relaciones con clientes, historial de configuracion, notas,
//    credenciales/sesiones SSH y virtual hosts. Un VPS con HOSTING esta
//    protegido por FK RESTRICT: en ese caso se responde 409.
//    -> audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('VPS', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// Nombre visible: la etiqueta libre si existe, si no la referencia del VPS.
$stmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(label, ''), referencia_vps) AS nombre
       FROM public.vps_servidores WHERE id = :id"
);
$stmt->execute([':id' => $id]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('VPS no encontrado', 404);
}

try {
    $pdo->prepare('DELETE FROM public.vps_servidores WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error(
            'No se puede eliminar el VPS porque tiene hosting asociado. Elimina el hosting primero.',
            409
        );
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'VPS',
    'Elimino el VPS ' . $nombre,
    $id,
    ['nombre' => $nombre],
    null
);

json_ok(['id' => $id], 'VPS eliminado correctamente');
