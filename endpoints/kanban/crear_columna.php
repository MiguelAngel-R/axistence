<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Crear columna en el tablero de un proyecto.
//  POST JSON: { proyecto_id, nombre }
//  La columna se agrega al FINAL del tablero (orden = max(orden) + 1).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Kanban', 'crear');

$in         = body_json();
$proyectoId = kanban_uuid_o_error($in['proyecto_id'] ?? '', 'Proyecto no valido');
$nombre     = trim((string)($in['nombre'] ?? ''));

if ($nombre === '') {
    json_error('El nombre de la columna es obligatorio', 422, ['faltantes' => ['nombre']]);
}
if (mb_strlen($nombre) > 100) {
    json_error('El nombre es demasiado largo (maximo 100 caracteres)', 422);
}

$pdo = Database::get();
kanban_proyecto_o_error($pdo, $proyectoId);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.proyecto_columnas (proyecto_id, nombre, orden)
         VALUES (:p, :n,
                 (SELECT COALESCE(MAX(orden) + 1, 1)
                    FROM public.proyecto_columnas WHERE proyecto_id = :p))
         RETURNING id, orden'
    );
    $stmt->execute([':p' => $proyectoId, ':n' => $nombre]);
    $col = $stmt->fetch();
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {                 // UNIQUE (proyecto_id, nombre)
        json_error('Ya existe una columna con ese nombre en el tablero', 409);
    }
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Kanban',
    'Agrego la columna "' . $nombre . '" al tablero',
    (string) $col['id'],
    null,
    ['proyecto_id' => $proyectoId, 'nombre' => $nombre]
);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el tablero de este proyecto para
// que agreguen la columna al final sin recargar. El evento llega a la sala del
// modulo; viaja con proyecto_id (para filtrar por detalle) y los datos de la
// columna (recien creada: sin tareas). Se emite SOLO tras el commit y la
// auditoria (fire-and-forget: nunca rompe la operacion).
$columna = [
    'id'          => $col['id'],
    'proyecto_id' => $proyectoId,
    'nombre'      => $nombre,
    'orden'       => (int) $col['orden'],
    'tareas'      => [],
];
notificar_socket('proyectos', 'columna:creada', $columna);

json_ok($columna, 'Columna creada correctamente');
