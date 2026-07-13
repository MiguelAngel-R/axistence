<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Renombrar una columna del tablero.
//  POST JSON: { proyecto_id, columna_id, nombre }
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$columnaId  = kanban_uuid_o_error($in['columna_id']  ?? '', 'Columna no valida');
$nombre     = trim((string)($in['nombre'] ?? ''));

if ($nombre === '') {
    json_error('El nombre de la columna es obligatorio', 422, ['faltantes' => ['nombre']]);
}
if (mb_strlen($nombre) > 100) {
    json_error('El nombre es demasiado largo (maximo 100 caracteres)', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La columna debe pertenecer al proyecto; guardamos su nombre anterior.
$stmt = $pdo->prepare(
    'SELECT nombre FROM public.proyecto_columnas
      WHERE id = :c AND proyecto_id = :p'
);
$stmt->execute([':c' => $columnaId, ':p' => $proyectoId]);
$nombreAnterior = $stmt->fetchColumn();
if ($nombreAnterior === false) {
    json_error('La columna no pertenece al proyecto', 404);
}

if ($nombreAnterior === $nombre) {
    json_ok(['id' => $columnaId, 'nombre' => $nombre], 'Sin cambios');
}

try {
    $pdo->prepare(
        'UPDATE public.proyecto_columnas
            SET nombre = :n
          WHERE id = :c AND proyecto_id = :p'
    )->execute([':n' => $nombre, ':c' => $columnaId, ':p' => $proyectoId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {                 // UNIQUE (proyecto_id, nombre)
        json_error('Ya existe una columna con ese nombre en el tablero', 409);
    }
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Kanban',
    'Renombro la columna "' . $nombreAnterior . '" a "' . $nombre . '"',
    $columnaId,
    ['nombre' => $nombreAnterior],
    ['nombre' => $nombre]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que actualicen el nombre de la columna sin recargar. Viajan proyecto_id (para
// filtrar por detalle), columna_id y el nuevo nombre. Se emite SOLO tras el
// cambio real y la auditoria (no en el atajo "Sin cambios"). Fire-and-forget.
notificar_socket('proyectos', 'columna:renombrada', [
    'proyecto_id' => $proyectoId,
    'columna_id'  => $columnaId,
    'nombre'      => $nombre,
]);

json_ok(['id' => $columnaId, 'nombre' => $nombre], 'Columna renombrada');
