<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Eliminar una relacion de correo (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE. Caen en cascada sus
//    licencias (y las cuentas/buzones de cada una), extensiones de espacio,
//    registros DNS de correo y notas. -> audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Correo', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// Nombre visible para la auditoria: el dominio de la relacion de correo.
$stmt = $pdo->prepare(
    'SELECT d.nombre_dominio
       FROM public.cuentas_correo cc
       JOIN public.dominios d ON d.id = cc.dominio_id
      WHERE cc.id = :id'
);
$stmt->execute([':id' => $id]);
$dominio = $stmt->fetchColumn();
if ($dominio === false) {
    json_error('Relacion de correo no encontrada', 404);
}

try {
    $pdo->prepare('DELETE FROM public.cuentas_correo WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el correo porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Correo',
    'Elimino la relacion de correo del dominio ' . $dominio,
    $id,
    ['dominio' => $dominio],
    null
);

json_ok(['id' => $id], 'Correo eliminado correctamente');
