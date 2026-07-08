<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Tablero de un proyecto (lectura).
//  GET params: proyecto_id (uuid)
//  Devuelve las columnas configuradas del proyecto y, dentro de cada una,
//  sus tarjetas (tareas) ya ordenadas por orden_posicion. Si el proyecto
//  no tiene columnas todavia, se siembran las de por defecto.
//  Estructura: { proyecto, columnas: [{ id, nombre, orden, tareas: [...] }] }
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('GET');
requiere_permiso('Kanban', 'ver');

$proyectoId = kanban_uuid_o_error($_GET['proyecto_id'] ?? '');

$pdo      = Database::get();
$proyecto = kanban_proyecto_o_error($pdo, $proyectoId);
$columnas = kanban_columnas_del_proyecto($pdo, $proyectoId);

// Todas las tareas del proyecto, ya ordenadas por columna y posicion. Se
// agrupan en PHP bajo su columna para no hacer una consulta por columna.
$stmt = $pdo->prepare(
    "SELECT t.id, t.columna_id, t.titulo, t.descripcion, t.prioridad,
            t.orden_posicion, t.fecha_limite, t.fecha_finalizacion_real, t.created_at,
            COALESCE(
                string_agg(u.nombre_completo, ', ' ORDER BY u.nombre_completo),
                ''
            ) AS responsables
       FROM public.tareas_kanban t
       LEFT JOIN public.tarea_responsables tr ON tr.tarea_id = t.id
       LEFT JOIN public.usuarios_internos  u  ON u.id = tr.usuario_id
      WHERE t.proyecto_id = :p AND t.fecha_archivado IS NULL
      GROUP BY t.id
      ORDER BY t.orden_posicion ASC, t.created_at ASC"
);
$stmt->execute([':p' => $proyectoId]);
$tareas = $stmt->fetchAll();

// Agrupacion por columna_id.
$porColumna = [];
foreach ($tareas as $t) {
    $porColumna[$t['columna_id']][] = $t;
}

$salida = [];
foreach ($columnas as $c) {
    $salida[] = [
        'id'     => $c['id'],
        'nombre' => $c['nombre'],
        'orden'  => (int) $c['orden'],
        'tareas' => $porColumna[$c['id']] ?? [],
    ];
}

json_ok([
    'proyecto' => $proyecto,
    'columnas' => $salida,
]);
