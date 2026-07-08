<?php
declare(strict_types=1);

// =====================================================================
//  Kanban - Utilidades compartidas por los endpoints del tablero
//  (tablero.php, crear_tarea.php, mover_tarea.php).
//  Validacion de UUID, verificacion del proyecto y siembra de las
//  columnas por defecto cuando el proyecto aun no tiene tablero.
// =====================================================================

const KANBAN_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// Prioridades validas (enum public.prioridad_tarea).
const KANBAN_PRIORIDADES = ['Baja', 'Media', 'Alta', 'Crítica'];

// Columnas iniciales que se crean la primera vez que se abre el tablero de
// un proyecto que aun no tiene ninguna. El orden define su posicion fisica.
const KANBAN_COLUMNAS_DEFECTO = [
    'Por hacer',
    'En progreso',
    'En revisión',
    'Finalizado',
];

function kanban_uuid_valido(string $v): bool
{
    return (bool) preg_match(KANBAN_UUID_RE, $v);
}

// Corta con 422 si el id no es un UUID valido; devuelve el id saneado.
function kanban_uuid_o_error($valor, string $mensaje = 'Identificador no valido'): string
{
    $id = trim((string)$valor);
    if (!kanban_uuid_valido($id)) {
        json_error($mensaje, 422);
    }
    return $id;
}

// Verifica que el proyecto exista. Devuelve su fila o corta con 404.
function kanban_proyecto_o_error(PDO $pdo, string $proyectoId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, nombre_proyecto FROM public.proyectos WHERE id = :id'
    );
    $stmt->execute([':id' => $proyectoId]);
    $proyecto = $stmt->fetch();
    if (!$proyecto) {
        json_error('Proyecto no encontrado', 404);
    }
    return $proyecto;
}

// Devuelve las columnas del tablero del proyecto (ordenadas). Si el proyecto
// aun no tiene ninguna, siembra las columnas por defecto y las devuelve.
// Asi el tablero es utilizable en cuanto se abre el detalle, sin config previa.
function kanban_columnas_del_proyecto(PDO $pdo, string $proyectoId): array
{
    $sql = 'SELECT id, nombre, orden
              FROM public.proyecto_columnas
             WHERE proyecto_id = :p
             ORDER BY orden ASC, nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':p' => $proyectoId]);
    $columnas = $stmt->fetchAll();

    if ($columnas) {
        return $columnas;
    }

    // Siembra las columnas por defecto (idempotente frente a carreras gracias
    // al UNIQUE (proyecto_id, nombre): ON CONFLICT DO NOTHING).
    $ins = $pdo->prepare(
        'INSERT INTO public.proyecto_columnas (proyecto_id, nombre, orden)
         VALUES (:p, :n, :o)
         ON CONFLICT (proyecto_id, nombre) DO NOTHING'
    );
    foreach (KANBAN_COLUMNAS_DEFECTO as $i => $nombre) {
        $ins->execute([':p' => $proyectoId, ':n' => $nombre, ':o' => $i + 1]);
    }

    $stmt->execute([':p' => $proyectoId]);
    return $stmt->fetchAll();
}
