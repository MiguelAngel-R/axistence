<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Descarga segura de un adjunto de tarjeta.
//  GET params: proyecto_id (uuid), adjunto_id (uuid)
//  Solo con permiso 'Kanban/ver'. Verifica que el adjunto pertenezca a una
//  tarea del proyecto, registra la descarga (DESCARGA_SEGURA) y entrega el
//  archivo por streaming con su nombre y mime originales (el archivo NO es
//  accesible directamente por web).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('GET');
requiere_permiso('Kanban', 'ver');

$proyectoId = kanban_uuid_o_error($_GET['proyecto_id'] ?? '', 'Proyecto no valido');
$adjuntoId  = kanban_uuid_o_error($_GET['adjunto_id']  ?? '', 'Adjunto no valido');

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT a.nombre_archivo, a.ruta_almacenamiento, a.tipo_mime, t.titulo
       FROM public.tarea_adjuntos a
       JOIN public.tareas_kanban t ON t.id = a.tarea_id
      WHERE a.id = :a AND t.proyecto_id = :p'
);
$stmt->execute([':a' => $adjuntoId, ':p' => $proyectoId]);
$adj = $stmt->fetch();
if (!$adj) {
    json_error('Adjunto no encontrado', 404);
}

// La ruta almacenada debe seguir el patron esperado (bloquea path traversal).
$ruta = (string)$adj['ruta_almacenamiento'];
if (!preg_match('~^uploads/tareas/[0-9a-f-]{36}/[a-f0-9]{32}$~', $ruta)) {
    json_error('El archivo adjunto no esta disponible', 404);
}
$abs = UPLOADS_DIR . '/' . substr($ruta, strlen('uploads/'));
if (!is_file($abs)) {
    json_error('El archivo adjunto no esta disponible', 404);
}

registrar_auditoria(
    'DESCARGA_SEGURA',
    'Kanban',
    'Descargo el adjunto "' . $adj['nombre_archivo'] . '" de la tarea "' . $adj['titulo'] . '"',
    $adjuntoId
);

$mime         = (string)($adj['tipo_mime'] ?? '') ?: 'application/octet-stream';
$nombreSalida = (string)$adj['nombre_archivo'];
// Se limpia el nombre para la cabecera (sin comillas ni saltos de linea).
$nombreSalida = str_replace(['"', "\r", "\n"], '', $nombreSalida);

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $nombreSalida . '"');
header('Content-Length: ' . (string)filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit;
