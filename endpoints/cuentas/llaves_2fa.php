<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas / 2FA - Consultar las llaves de recuperacion de un metodo 2FA.
//  GET params: id (uuid del cuenta_2fa)
//  Respuesta data: { id, aplicacion, llaves: string[] }
//  Lee el archivo .txt asociado (ver _llaves.php) y registra la consulta en
//  el log de auditoria como DESCARGA_SEGURA (las llaves son sensibles).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_llaves.php';

solo_metodo('GET');
requiere_permiso('Dominios', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador de 2FA no valido', 422);
}

$pdo  = Database::get();
$stmt = $pdo->prepare(
    'SELECT id, aplicacion, llaves_recuperacion_path
       FROM public.cuenta_2fa WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$fa = $stmt->fetch();
if (!$fa) {
    json_error('Metodo 2FA no encontrado', 404);
}

$llaves = leer_llaves_2fa($fa['llaves_recuperacion_path'] ?? null);

// Auditoria: consulta de datos sensibles.
registrar_auditoria(
    'DESCARGA_SEGURA',
    'Proveedores',
    'Consulto las llaves de recuperacion del 2FA ' . $fa['aplicacion'],
    $id,
    null,
    ['cuenta_2fa_id' => $id, 'total_llaves' => count($llaves)]
);

json_ok([
    'id'         => $fa['id'],
    'aplicacion' => $fa['aplicacion'],
    'llaves'     => $llaves,
]);
