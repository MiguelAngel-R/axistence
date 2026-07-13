<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Registrar comando (server-to-server).
//
//  Lo llama Node cada vez que el operador presiona Enter (Fase 4): la
//  linea acumulada es el comando. Por decision del proyecto NO se guarda
//  la salida, solo el comando. El `orden` lo calcula PHP (max+1).
//
//  Autenticacion: clave compartida Node<->PHP (X-Consola-Node-Key), NO el
//  token. El token de consola dura poco (handshake); una sesion puede durar
//  horas, asi que la persistencia se autoriza con la clave de Node + el
//  sesion_id (UUID generado por PHP al abrir la sesion). Esto evita que un
//  token expirado corte el registro de comandos en sesiones largas.
//
//  POST { sesion_id, comando }   (cabecera X-Consola-Node-Key)
//  200 -> { ok, data: { orden } }
//  403 sin/errada node key ; 404 sesion inexistente ; 409 no activa ; 422 datos
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
consola_requiere_node_key();

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in       = body_json();
$sesionId = trim((string)($in['sesion_id'] ?? ''));
$comando  = (string)($in['comando'] ?? '');

if (!preg_match(UUID_RE, $sesionId)) {
    json_error('sesion_id no valido', 422);
}
if (trim($comando) === '') {
    json_error('Comando vacio', 422);
}

$pdo = Database::get();

// La sesion debe existir y estar activa.
$stmt = $pdo->prepare('SELECT estado FROM public.vps_consola_sesiones WHERE id = :id');
$stmt->execute([':id' => $sesionId]);
$sesion = $stmt->fetch();

if (!$sesion) {
    json_error('Sesion de consola no encontrada', 404);
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
