<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Sugerencias de autocompletado (frontend, con sesion).
//
//  Devuelve los comandos ya ejecutados en ESTE VPS (de todas sus sesiones),
//  sin repetir, ordenados por frecuencia y luego por recencia. El navegador
//  los carga al conectar y los filtra localmente conforme se escribe para
//  ofrecer autocompletar con Tab (panel bajo la terminal).
//
//    GET ?vps_id=...  -> { ok, data: { comandos: [{comando, veces, ultimo}] } }
//
//  Permiso VPS.ver (solo lectura). No expone salida, solo el texto tecleado
//  (que es lo unico que se guarda, por decision del proyecto).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$vpsId = trim((string)($_GET['vps_id'] ?? ''));
if (!preg_match(UUID_RE, $vpsId)) {
    json_error('vps_id no valido', 422);
}

$pdo = Database::get();

// Comandos distintos del VPS: los mas usados primero, luego los mas recientes.
$stmt = $pdo->prepare(
    'SELECT c.comando,
            COUNT(*)      AS veces,
            MAX(c.ts)     AS ultimo
       FROM public.vps_consola_comandos c
       JOIN public.vps_consola_sesiones s ON s.id = c.sesion_id
      WHERE s.vps_id = :vps
      GROUP BY c.comando
      ORDER BY veces DESC, ultimo DESC
      LIMIT 500'
);
$stmt->execute([':vps' => $vpsId]);

$comandos = [];
foreach ($stmt->fetchAll() as $fila) {
    $comandos[] = [
        'comando' => $fila['comando'],
        'veces'   => (int)$fila['veces'],
        'ultimo'  => $fila['ultimo'],
    ];
}

json_ok(['comandos' => $comandos]);
