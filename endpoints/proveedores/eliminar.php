<?php
declare(strict_types=1);

// =====================================================================
//  Proveedores - Eliminar un proveedor (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE. Caen en cascada sus
//    contactos, tipos de producto, notas, cuentas de acceso y referencias.
//    Pero un proveedor con productos (VPS, dominios, certificados u otros)
//    esta protegido por FK RESTRICT: en ese caso se responde 409.
//    -> audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Proveedores', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT nombre_proveedor FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $id]);
$nombre = $stmt->fetchColumn();
if ($nombre === false) {
    json_error('Proveedor no encontrado', 404);
}

try {
    $pdo->prepare('DELETE FROM public.proveedores WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error(
            'No se puede eliminar el proveedor porque tiene productos asociados ' .
            '(VPS, dominios, certificados u otros). Reasignalos o eliminalos primero.',
            409
        );
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Proveedores',
    'Elimino el proveedor ' . $nombre,
    $id,
    ['nombre_proveedor' => $nombre],
    null
);

json_ok(['id' => $id], 'Proveedor eliminado correctamente');
