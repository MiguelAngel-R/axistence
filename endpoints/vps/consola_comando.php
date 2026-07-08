<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Registrar comando (server-to-server).
//
//  Lo llama Node cada vez que el operador presiona Enter (Fase 4): la
//  linea acumulada es el comando. Por decision del proyecto NO se guarda
//  la salida, solo el comando. El `orden` lo calcula PHP (max+1) para no
//  depender de un contador en Node.
//
//  POST { token, sesion_id, comando }
//  200 -> { ok, data: { orden } }
//  401 token ; 404 sesion inexistente/ajena ; 409 sesion no activa ; 422 datos
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

[$payload, $in] = consola_token_de_body();

$sesionId = trim((string)($in['sesion_id'] ?? ''));
$comando  = (string)($in['comando'] ?? '');

if (!preg_match(UUID_RE, $sesionId)) {
    json_error('sesion_id no valido', 422);
}
if (trim($comando) === '') {
    json_error('Comando vacio', 422);
}

$pdo = Database::get();

// La sesion debe existir, pertenecer al mismo VPS del token y estar activa.
$stmt = $pdo->prepare(
    'SELECT estado, vps_id FROM public.vps_consola_sesiones WHERE id = :id'
);
$stmt->execute([':id' => $sesionId]);
$sesion = $stmt->fetch();

if (!$sesion) {
    json_error('Sesion de consola no encontrada', 404);
}
if ((string)$sesion['vps_id'] !== (string)($payload['vps_id'] ?? '')) {
    json_error('La sesion no corresponde al VPS autorizado', 403);
}
if ($sesion['estado'] !== 'activa') {
    json_error('La sesion de consola no esta activa', 409);
}

// orden = siguiente dentro de la sesion.
$ord = $pdo->prepare(
    'SELECT COALESCE(MAX(orden), 0) + 1 FROM public.vps_consola_comandos WHERE sesion_id = :sid'
);
$ord->execute([':sid' => $sesionId]);
$orden = (int)$ord->fetchColumn();

$ins = $pdo->prepare(
    'INSERT INTO public.vps_consola_comandos (sesion_id, orden, comando)
     VALUES (:sid, :orden, :comando)'
);
$ins->execute([
    ':sid'     => $sesionId,
    ':orden'   => $orden,
    ':comando' => $comando,
]);

json_ok(['orden' => $orden], 'Comando registrado');
