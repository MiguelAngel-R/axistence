<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Subir un adjunto a una tarjeta (tarea).
//  POST multipart/form-data: proyecto_id, tarea_id, archivo (campo file).
//  Se admite CUALQUIER tipo de archivo. Cada tarea tiene su propia carpeta
//  uploads/tareas/<tarea_id>/ para mantener el almacenamiento organizado.
//  El archivo se guarda con un nombre ALEATORIO y SIN extension (evita
//  colisiones, adivinacion, path traversal y ejecucion: p. ej. un .php
//  subido nunca se ejecuta). El nombre y el mime reales quedan en la BD y
//  se restauran al descargar (endpoints/kanban/descargar_adjunto.php).
//  uploads/ NO es accesible directamente por web (ver uploads/.htaccess).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'crear');

const KANBAN_ADJ_MAX_BYTES = 26214400; // 25 MB

$proyectoId = kanban_uuid_o_error($_POST['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($_POST['tarea_id']    ?? '', 'Tarea no valida');

if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
    json_error('No se recibio ningun archivo', 422);
}
$archivo = $_FILES['archivo'];

$codigo = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
if ($codigo !== UPLOAD_ERR_OK) {
    if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) {
        json_error('El archivo excede el tamaño permitido', 422);
    }
    if ($codigo === UPLOAD_ERR_NO_FILE) {
        json_error('No se recibio ningun archivo', 422);
    }
    json_error('No se pudo recibir el archivo', 422);
}
if (($archivo['size'] ?? 0) <= 0) {
    json_error('El archivo esta vacio', 422);
}
if ($archivo['size'] > KANBAN_ADJ_MAX_BYTES) {
    json_error('El archivo es demasiado grande (maximo 25 MB)', 422);
}
if (!is_uploaded_file($archivo['tmp_name'])) {
    json_error('Origen de archivo no valido', 422);
}

$nombreOriginal = basename((string)$archivo['name']);
if ($nombreOriginal === '' || mb_strlen($nombreOriginal) > 255) {
    json_error('El nombre del archivo no es valido', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarea debe pertenecer al proyecto.
$stmt = $pdo->prepare(
    'SELECT titulo FROM public.tareas_kanban WHERE id = :t AND proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$titulo = $stmt->fetchColumn();
if ($titulo === false) {
    json_error('La tarea no pertenece al proyecto', 404);
}

// Carpeta propia de la tarea: uploads/tareas/<tarea_id>/
$dir = UPLOADS_DIR . '/tareas/' . $tareaId;
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_error('No se pudo preparar el almacenamiento', 500);
}

// Nombre aleatorio SIN extension (no ejecutable, no adivinable).
$nombreDisco = bin2hex(random_bytes(16));
$destino     = $dir . '/' . $nombreDisco;
if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
    json_error('No se pudo guardar el archivo', 500);
}
@chmod($destino, 0640);

// Mime real (mas fiable que el enviado por el navegador).
$tipoMime = null;
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi !== false) {
        $tipoMime = finfo_file($fi, $destino) ?: null;
        finfo_close($fi);
    }
}
if ($tipoMime === null) {
    $tipoMime = (string)($archivo['type'] ?? '') ?: null;
}

$rutaRel = 'uploads/tareas/' . $tareaId . '/' . $nombreDisco;
$u       = usuario_actual();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.tarea_adjuntos
            (tarea_id, subido_por_id, nombre_archivo, ruta_almacenamiento, tipo_mime, tamano_bytes)
         VALUES (:t, :u, :n, :r, :m, :b)
         RETURNING id, nombre_archivo, tipo_mime, tamano_bytes, fecha_subida'
    );
    $stmt->execute([
        ':t' => $tareaId,
        ':u' => $u['id'] ?? null,
        ':n' => $nombreOriginal,
        ':r' => $rutaRel,
        ':m' => $tipoMime,
        ':b' => (int)$archivo['size'],
    ]);
    $fila = $stmt->fetch();
} catch (PDOException $e) {
    @unlink($destino); // no dejar el archivo huerfano si falla el INSERT
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Kanban',
    'Adjunto "' . $nombreOriginal . '" a la tarea "' . $titulo . '"',
    $tareaId,
    null,
    ['nombre_archivo' => $nombreOriginal, 'tamano_bytes' => (int)$archivo['size']]
);

json_ok([
    'id'             => $fila['id'],
    'nombre_archivo' => $fila['nombre_archivo'],
    'tipo_mime'      => $fila['tipo_mime'],
    'tamano_bytes'   => $fila['tamano_bytes'] !== null ? (int)$fila['tamano_bytes'] : null,
    'fecha_subida'   => $fila['fecha_subida'],
    'subido_por'     => $u['nombre_completo'] ?? null,
], 'Archivo adjuntado correctamente');
