<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Reordenar las columnas del tablero (drag & drop horizontal).
//  POST JSON: { proyecto_id, orden: [uuid, ...] }
//  Renumera proyecto_columnas.orden segun la posicion en el arreglo
//  'orden' (base 1). Solo toca columnas del proyecto indicado.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');

// Arreglo con el nuevo orden de columnas (ids unicos y validos).
$ordenBruto = is_array($in['orden'] ?? null) ? $in['orden'] : [];
$orden      = [];
foreach ($ordenBruto as $x) {
    $x = trim((string)$x);
    if ($x !== '' && kanban_uuid_valido($x) && !in_array($x, $orden, true)) {
        $orden[] = $x;
    }
}
if (!$orden) {
    json_error('No se recibio un orden de columnas valido', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        'UPDATE public.proyecto_columnas
            SET orden = :o
          WHERE id = :c AND proyecto_id = :p'
    );
    foreach ($orden as $i => $columnaId) {
        $upd->execute([':o' => $i + 1, ':c' => $columnaId, ':p' => $proyectoId]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Kanban',
    'Reordeno las columnas del tablero',
    $proyectoId,
    null,
    ['orden' => $orden]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que reordenen las columnas sin recargar, reinsertando cada columna segun
// 'orden'. El evento viaja con proyecto_id (para filtrar por detalle) y el nuevo
// orden de ids de columna. Es idempotente: aplicar el mismo orden deja el mismo
// estado (el propio actor, que ya reordeno en su DOM, lo reaplica sin efecto).
// Se emite SOLO tras el commit y la auditoria (fire-and-forget).
notificar_socket('proyectos', 'columnas:reordenadas', [
    'proyecto_id' => $proyectoId,
    'orden'       => $orden,
]);

json_ok(['orden' => $orden], 'Columnas reordenadas');
