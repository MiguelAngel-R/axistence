<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Historial (frontend, con sesion).
//
//  Alimenta el panel lateral de la consola.
//    GET ?vps_id=...                 -> lista de sesiones del VPS
//                                       (quien, cuando, estado, nº comandos)
//    GET ?vps_id=...&sesion_id=...    -> comandos de esa sesion, en orden
//
//  Permiso VPS.ver (solo lectura).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$vpsId    = trim((string)($_GET['vps_id'] ?? ''));
$sesionId = trim((string)($_GET['sesion_id'] ?? ''));

if (!preg_match(UUID_RE, $vpsId)) {
    json_error('vps_id no valido', 422);
}

$pdo = Database::get();

// --- Comandos de una sesion concreta --------------------------------
if ($sesionId !== '') {
    if (!preg_match(UUID_RE, $sesionId)) {
        json_error('sesion_id no valido', 422);
    }
    // La sesion debe pertenecer al VPS (evita fugas entre VPS).
    $chk = $pdo->prepare('SELECT 1 FROM public.vps_consola_sesiones WHERE id = :sid AND vps_id = :vps');
    $chk->execute([':sid' => $sesionId, ':vps' => $vpsId]);
    if (!$chk->fetchColumn()) {
        json_error('Sesion no encontrada para este VPS', 404);
    }

    $stmt = $pdo->prepare(
        'SELECT orden, comando, ts
           FROM public.vps_consola_comandos
          WHERE sesion_id = :sid
          ORDER BY orden ASC'
    );
    $stmt->execute([':sid' => $sesionId]);
    json_ok(['comandos' => $stmt->fetchAll()]);
}

// --- Lista de sesiones del VPS --------------------------------------
$stmt = $pdo->prepare(
    'SELECT s.id, s.estado, s.direccion_ip, s.inicio, s.fin,
            u.nombre_completo AS usuario,
            (SELECT COUNT(*) FROM public.vps_consola_comandos c WHERE c.sesion_id = s.id) AS total_comandos
       FROM public.vps_consola_sesiones s
       LEFT JOIN public.usuarios_internos u ON u.id = s.usuario_id
      WHERE s.vps_id = :vps
      ORDER BY s.inicio DESC'
);
$stmt->execute([':vps' => $vpsId]);
json_ok(['sesiones' => $stmt->fetchAll()]);
