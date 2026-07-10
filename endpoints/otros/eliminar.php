<?php
declare(strict_types=1);

// =====================================================================
//  Otros productos - Eliminar un producto (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE (caen en cascada sus
//    atributos, relaciones con clientes y notas) -> audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Otros', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare('SELECT tipo_producto, nombre_referencia FROM public.otros_productos WHERE id = :id');
$stmt->execute([':id' => $id]);
$prod = $stmt->fetch();
if (!$prod) {
    json_error('Producto no encontrado', 404);
}
$nombre = trim(($prod['tipo_producto'] ?? '') . ' ' . ($prod['nombre_referencia'] ?? ''));

try {
    $pdo->prepare('DELETE FROM public.otros_productos WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el producto porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

registrar_auditoria(
    'ELIMINAR',
    'Otros',
    'Elimino el producto ' . $nombre,
    $id,
    ['tipo_producto' => $prod['tipo_producto'], 'nombre_referencia' => $prod['nombre_referencia']],
    null
);

json_ok(['id' => $id], 'Producto eliminado correctamente');
