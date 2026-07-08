<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Tarjetas archivadas de un proyecto (lectura).
//  GET params: proyecto_id (uuid)
//  Devuelve las tareas con fecha_archivado NOT NULL, con su columna
//  original y responsables, ordenadas de la mas reciente a la mas antigua.
//  Estructura: { tareas: [{ id, titulo, prioridad, columna, responsables,
//                           fecha_archivado }] }
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('GET');
requiere_permiso('Kanban', 'ver');

$proyectoId = kanban_uuid_o_error($_GET['proyecto_id'] ?? '', 'Proyecto no valido');

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

$stmt = $pdo->prepare(
    "SELECT t.id, t.titulo, t.prioridad, t.fecha_archivado,
            c.nombre AS columna,
            COALESCE(
                string_agg(u.nombre_completo, ', ' ORDER BY u.nombre_completo),
                ''
            ) AS responsables
       FROM public.tareas_kanban t
       JOIN public.proyecto_columnas c ON c.id = t.columna_id
       LEFT JOIN public.tarea_responsables tr ON tr.tarea_id = t.id
       LEFT JOIN public.usuarios_internos  u  ON u.id = tr.usuario_id
      WHERE t.proyecto_id = :p AND t.fecha_archivado IS NOT NULL
      GROUP BY t.id, c.nombre
      ORDER BY t.fecha_archivado DESC"
);
$stmt->execute([':p' => $proyectoId]);

json_ok(['tareas' => $stmt->fetchAll()]);
