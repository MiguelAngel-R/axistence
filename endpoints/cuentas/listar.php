<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas de acceso - Listado por proveedor (para el combobox del dominio).
//  GET params: proveedor_id (uuid)
//  Respuesta data: [ { id, alias, usuario }, ... ]
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Dominios', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$proveedor = trim((string)($_GET['proveedor_id'] ?? ''));
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT id, alias, usuario
       FROM public.cuentas_acceso
      WHERE proveedor_id = :p
      ORDER BY alias ASC'
);
$stmt->execute([':p' => $proveedor]);

json_ok($stmt->fetchAll());
