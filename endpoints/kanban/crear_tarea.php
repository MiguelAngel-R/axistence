<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Crear tarjeta (tarea) en una columna del tablero.
//  POST JSON: { proyecto_id, columna_id, titulo, descripcion?, prioridad? }
//  La tarjeta se agrega al FINAL de la columna destino (orden_posicion =
//  max(columna) + 1) para no colisionar con las existentes.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'crear');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$columnaId  = kanban_uuid_o_error($in['columna_id']  ?? '', 'Columna no valida');
$titulo     = trim((string)($in['titulo'] ?? ''));
$descrip    = trim((string)($in['descripcion'] ?? ''));
$prioridad  = trim((string)($in['prioridad'] ?? 'Media'));

if ($titulo === '') {
    json_error('El titulo de la tarea es obligatorio', 422, ['faltantes' => ['titulo']]);
}
if (mb_strlen($titulo) > 255) {
    json_error('El titulo es demasiado largo (maximo 255 caracteres)', 422);
}
if (!in_array($prioridad, KANBAN_PRIORIDADES, true)) {
    json_error('La prioridad indicada no es valida', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La columna debe pertenecer al MISMO proyecto (evita colar tareas en
// tableros ajenos manipulando el id de columna).
$stmt = $pdo->prepare(
    'SELECT nombre FROM public.proyecto_columnas
      WHERE id = :c AND proyecto_id = :p'
);
$stmt->execute([':c' => $columnaId, ':p' => $proyectoId]);
$nombreColumna = $stmt->fetchColumn();
if ($nombreColumna === false) {
    json_error('La columna seleccionada no pertenece al proyecto', 422);
}

$descripBd = $descrip !== '' ? $descrip : null;

$pdo->beginTransaction();
try {
    // Posicion al final de la columna destino.
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(orden_posicion) + 1, 0)
           FROM public.tareas_kanban
          WHERE columna_id = :c'
    );
    $stmt->execute([':c' => $columnaId]);
    $pos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO public.tareas_kanban
            (proyecto_id, columna_id, titulo, descripcion, prioridad, orden_posicion)
         VALUES (:p, :c, :t, :d, :pr, :o)
         RETURNING id'
    );
    $stmt->execute([
        ':p'  => $proyectoId,
        ':c'  => $columnaId,
        ':t'  => $titulo,
        ':d'  => $descripBd,
        ':pr' => $prioridad,
        ':o'  => $pos,
    ]);
    $id = $stmt->fetchColumn();

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('El proyecto o la columna seleccionada no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Kanban',
    'Creo la tarea "' . $titulo . '" en la columna ' . $nombreColumna,
    (string) $id,
    null,
    [
        'proyecto_id' => $proyectoId,
        'columna_id'  => $columnaId,
        'titulo'      => $titulo,
        'prioridad'   => $prioridad,
    ]
);

json_ok([
    'id'             => $id,
    'columna_id'     => $columnaId,
    'titulo'         => $titulo,
    'descripcion'    => $descripBd,
    'prioridad'      => $prioridad,
    'orden_posicion' => $pos,
    'responsables'   => '',
], 'Tarea creada correctamente');
