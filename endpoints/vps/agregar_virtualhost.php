<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Agregar un Virtual Host al servidor.
//  POST JSON: {
//    vps_id, aplicacion, server_name, servidor_web, puerto,
//    ssl_habilitado?, dominio_id?, certificado_id?, document_root?,
//    proxy_pass?, puerto_aplicacion?, ruta_configuracion?, estado?, notas?
//  }
//  Inserta en virtual_hosts (vinculado al VPS, y opcionalmente a un dominio
//  y a un certificado SSL) y audita el evento en el VPS.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';
require_once __DIR__ . '/_fila_virtualhost.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

const SERVIDORES_WEB = ['Apache', 'Nginx', 'Otro'];
const ESTADOS_VH     = ['Activo', 'Inactivo'];

$in           = body_json();
$vpsId        = trim((string)($in['vps_id'] ?? ''));
$aplicacion   = trim((string)($in['aplicacion'] ?? ''));
$serverName   = trim((string)($in['server_name'] ?? ''));
$serverAlias  = trim((string)($in['server_alias'] ?? ''));
$servidorWeb  = (string)($in['servidor_web'] ?? 'Nginx');
$puerto       = $in['puerto'] ?? 80;
$sslHab       = !empty($in['ssl_habilitado']);
$dominioId    = trim((string)($in['dominio_id'] ?? ''));
$certificadoId = trim((string)($in['certificado_id'] ?? ''));
$documentRoot = trim((string)($in['document_root'] ?? ''));
$proxyPass    = trim((string)($in['proxy_pass'] ?? ''));
$puertoApp    = $in['puerto_aplicacion'] ?? '';
$rutaConfig   = trim((string)($in['ruta_configuracion'] ?? ''));
$estado       = (string)($in['estado'] ?? 'Activo');
$notas        = trim((string)($in['notas'] ?? ''));

$pdo = Database::get();
$ref = exigir_vps($pdo, $vpsId);

// --- Validaciones ---------------------------------------------------
if ($aplicacion === '') {
    json_error('La aplicacion es obligatoria', 422);
}
if ($serverName === '') {
    json_error('El ServerName es obligatorio', 422);
}
if (!in_array($servidorWeb, SERVIDORES_WEB, true)) {
    json_error('El servidor web no es valido', 422);
}
if (!in_array($estado, ESTADOS_VH, true)) {
    json_error('El estado no es valido', 422);
}
if (!is_numeric($puerto) || (int)$puerto < 1 || (int)$puerto > 65535) {
    json_error('El puerto debe estar entre 1 y 65535', 422);
}
if ($puertoApp !== '' && (!is_numeric($puertoApp) || (int)$puertoApp < 1 || (int)$puertoApp > 65535)) {
    json_error('El puerto de la aplicacion no es valido', 422);
}
if ($dominioId !== '' && !preg_match(UUID_RE, $dominioId)) {
    json_error('El dominio seleccionado no es valido', 422);
}
if ($certificadoId !== '' && !preg_match(UUID_RE, $certificadoId)) {
    json_error('El certificado seleccionado no es valido', 422);
}

// --- Insercion ------------------------------------------------------
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.virtual_hosts
             (vps_id, dominio_id, certificado_id, aplicacion, server_name, server_alias,
              servidor_web, document_root, puerto, ssl_habilitado, proxy_pass,
              puerto_aplicacion, ruta_configuracion, estado, notas)
         VALUES (:v, :dom, :cert, :app, :sn, :sa, :sw, :dr, :port, :ssl, :pp,
                 :pa, :rc, :estado, :notas)
         RETURNING id'
    );
    $stmt->execute([
        ':v'      => $vpsId,
        ':dom'    => $dominioId !== '' ? $dominioId : null,
        ':cert'   => $certificadoId !== '' ? $certificadoId : null,
        ':app'    => $aplicacion,
        ':sn'     => $serverName,
        ':sa'     => $serverAlias !== '' ? $serverAlias : null,
        ':sw'     => $servidorWeb,
        ':dr'     => $documentRoot !== '' ? $documentRoot : null,
        ':port'   => (int)$puerto,
        ':ssl'    => $sslHab ? 't' : 'f',
        ':pp'     => $proxyPass !== '' ? $proxyPass : null,
        ':pa'     => $puertoApp !== '' ? (int)$puertoApp : null,
        ':rc'     => $rutaConfig !== '' ? $rutaConfig : null,
        ':estado' => $estado,
        ':notas'  => $notas !== '' ? $notas : null,
    ]);
    $id = $stmt->fetchColumn();
} catch (PDOException $e) {
    // 23503 = FK invalida (dominio o certificado inexistente).
    if ($e->getCode() === '23503') {
        json_error('El dominio o el certificado seleccionado no existe', 422);
    }
    throw $e;
}

auditar_en_vps($vpsId, 'Agrego el virtual host ' . $aplicacion . ' (' . $serverName . ') en ' . $ref,
    ['aplicacion' => $aplicacion, 'server_name' => $serverName, 'servidor_web' => $servidorWeb, 'puerto' => (int)$puerto]);

// --- Tiempo real ----------------------------------------------------
// Como el Inventario Logico, el Virtual Host NO tiene modulo propio: solo
// impacta la pestaña Virtual Hosts del detalle del VPS. Se reconsulta la fila
// con la forma exacta que pinta ese tab (helper compartido: resuelve el nombre
// del dominio) y se reparte UN evento a la sala 'vps' (fire-and-forget, nunca
// rompe la operacion). Cada navegador decide si le corresponde por el vps_id.
$filaVh = fila_virtualhost_socket($pdo, (string)$id);
if ($filaVh) {
    notificar_socket('vps', 'virtualhost_vps:creado', $filaVh);
}

json_ok(['id' => $id], 'Virtual Host agregado correctamente');
