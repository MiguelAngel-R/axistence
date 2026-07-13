<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Mover tarjeta (persistencia reactiva del drag & drop).
//  POST JSON: {
//      proyecto_id,           // uuid del proyecto (tablero)
//      tarea_id,              // uuid de la tarjeta arrastrada
//      columna_id,            // uuid de la columna DESTINO
//      orden: [uuid, ...]     // ids de la columna destino en su nuevo orden
//  }
//
//  Sincronizacion BIDIMENSIONAL en una sola transaccion:
//    1) Reasigna columna_id de la tarjeta arrastrada (columna destino).
//    2) Renumera orden_posicion de TODAS las tarjetas de la columna destino
//       segun el arreglo 'orden', evitando colisiones al recargar.
//  Si cambio de columna, registra el movimiento en tarea_historial_estados.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'editar');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$tareaId    = kanban_uuid_o_error($in['tarea_id']    ?? '', 'Tarea no valida');
$columnaId  = kanban_uuid_o_error($in['columna_id']  ?? '', 'Columna no valida');

// Arreglo con el nuevo orden de la columna destino (ids de tarea, unicos).
$ordenBruto = is_array($in['orden'] ?? null) ? $in['orden'] : [];
$orden      = [];
foreach ($ordenBruto as $x) {
    $x = trim((string)$x);
    if ($x !== '' && kanban_uuid_valido($x) && !in_array($x, $orden, true)) {
        $orden[] = $x;
    }
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

// La tarjeta debe existir y pertenecer al proyecto; guardamos su columna
// anterior (con su nombre) para el historial.
$stmt = $pdo->prepare(
    'SELECT t.columna_id AS columna_anterior_id, c.nombre AS columna_anterior
       FROM public.tareas_kanban t
       JOIN public.proyecto_columnas c ON c.id = t.columna_id
      WHERE t.id = :t AND t.proyecto_id = :p'
);
$stmt->execute([':t' => $tareaId, ':p' => $proyectoId]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('La tarea no pertenece al proyecto', 404);
}

// La columna destino debe pertenecer al mismo proyecto.
$stmt = $pdo->prepare(
    'SELECT nombre FROM public.proyecto_columnas
      WHERE id = :c AND proyecto_id = :p'
);
$stmt->execute([':c' => $columnaId, ':p' => $proyectoId]);
$columnaNueva = $stmt->fetchColumn();
if ($columnaNueva === false) {
    json_error('La columna destino no pertenece al proyecto', 422);
}

$cambioColumna = ($actual['columna_anterior_id'] !== $columnaId);

$pdo->beginTransaction();
try {
    // 1) Reasignar la columna de la tarjeta arrastrada. Debe hacerse antes de
    //    renumerar, para que quede incluida en la columna destino.
    $pdo->prepare(
        'UPDATE public.tareas_kanban
            SET columna_id = :c
          WHERE id = :t AND proyecto_id = :p'
    )->execute([':c' => $columnaId, ':t' => $tareaId, ':p' => $proyectoId]);

    // 2) Renumerar orden_posicion de la columna destino segun 'orden'. El
    //    filtro por columna_id/proyecto_id garantiza que solo se toquen las
    //    tarjetas realmente pertenecientes a esa columna del tablero.
    $upd = $pdo->prepare(
        'UPDATE public.tareas_kanban
            SET orden_posicion = :o
          WHERE id = :id AND proyecto_id = :p AND columna_id = :c'
    );
    foreach ($orden as $i => $idTarea) {
        $upd->execute([':o' => $i, ':id' => $idTarea, ':p' => $proyectoId, ':c' => $columnaId]);
    }

    // 3) Historial de movimiento entre columnas (snapshot de nombres).
    if ($cambioColumna) {
        $u = usuario_actual();
        $pdo->prepare(
            'INSERT INTO public.tarea_historial_estados
                (tarea_id, usuario_id, columna_anterior, columna_nueva)
             VALUES (:t, :u, :ant, :nue)'
        )->execute([
            ':t'   => $tareaId,
            ':u'   => $u['id'] ?? null,
            ':ant' => $actual['columna_anterior'],
            ':nue' => $columnaNueva,
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

if ($cambioColumna) {
    registrar_auditoria(
        'CAMBIO_ESTADO',
        'Kanban',
        'Movio una tarea de "' . $actual['columna_anterior'] . '" a "' . $columnaNueva . '"',
        $tareaId,
        ['columna' => $actual['columna_anterior']],
        ['columna' => $columnaNueva]
    );
}

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que reflejen el movimiento sin recargar: mueven la tarjeta a la columna
// destino y reordenan esa columna segun 'orden'. El evento viaja con proyecto_id
// (para filtrar por detalle), la tarjeta movida, la columna destino y el nuevo
// orden de esa columna. Es idempotente: aplicar el mismo orden deja el mismo
// estado (el propio actor, que ya movio en su DOM, lo reaplica sin efecto). Se
// emite SOLO tras el commit y la auditoria (fire-and-forget).
notificar_socket('proyectos', 'tarea:movida', [
    'proyecto_id' => $proyectoId,
    'tarea_id'    => $tareaId,
    'columna_id'  => $columnaId,
    'orden'       => $orden,
]);

json_ok([
    'tarea_id'   => $tareaId,
    'columna_id' => $columnaId,
], 'Tablero actualizado');
