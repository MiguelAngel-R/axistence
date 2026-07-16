<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Utilidades compartidas por crear.php y actualizar.php.
//  Validacion de UUID/fechas/estado y sincronizacion de las relaciones
//  N:N del proyecto (equipo + recursos: dominios/vps/ssl/hosting).
// =====================================================================

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// Estados validos del proyecto (enum public.estado_proyecto).
const ESTADOS_PROYECTO = [
    'Planificación', 'En Desarrollo', 'En Pruebas',
    'Entregado', 'Pausado', 'Cancelado',
];

function fecha_valida(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) { return false; }
    [$y, $m, $d] = array_map('intval', explode('-', $f));
    return checkdate($m, $d, $y);
}

// Normaliza un arreglo de UUID (unicos, no vacios) o corta con 422.
function uuids_o_error($lista, string $mensaje): array
{
    if (!is_array($lista)) { return []; }
    $lista = array_values(array_unique(array_filter(array_map(
        static fn($x) => trim((string)$x), $lista
    ), static fn($x) => $x !== '')));
    foreach ($lista as $x) {
        if (!preg_match(UUID_RE, $x)) { json_error($mensaje, 422); }
    }
    sort($lista);
    return $lista;
}

// Reinserta las relaciones N:N del proyecto. Debe ejecutarse dentro de una
// transaccion ya iniciada. Si $limpiar es true borra las existentes antes
// (para el flujo de actualizacion).
function proyecto_sincronizar_relaciones(
    PDO $pdo, string $proyectoId, array $rels, bool $limpiar
): void {
    $mapa = [
        'equipo'   => ['tabla' => 'public.proyecto_equipo',    'col' => 'usuario_id'],
        'dominios' => ['tabla' => 'public.proyecto_dominios',  'col' => 'dominio_id'],
        'vps'      => ['tabla' => 'public.proyecto_vps',       'col' => 'vps_id'],
        'hosting'  => ['tabla' => 'public.proyecto_hostings',  'col' => 'hosting_id'],
    ];

    foreach ($mapa as $clave => $def) {
        if ($limpiar) {
            $pdo->prepare("DELETE FROM {$def['tabla']} WHERE proyecto_id = :p")
                ->execute([':p' => $proyectoId]);
        }
        $ids = $rels[$clave] ?? [];
        if (!$ids) { continue; }
        $ins = $pdo->prepare(
            "INSERT INTO {$def['tabla']} (proyecto_id, {$def['col']}) VALUES (:p, :x)"
        );
        foreach ($ids as $x) { $ins->execute([':p' => $proyectoId, ':x' => $x]); }
    }
}

// Notificacion in-app "te agregaron al equipo" (Fase 7). La usan crear.php y
// actualizar.php, que sincronizan el equipo con la misma logica de reemplazo.
// Avisa SOLO a los integrantes RECIEN agregados (conjunto nuevo menos el
// previo; en crear.php el previo es vacio y entran todos), excluyendo a quien
// hace el cambio (no se autonotifica). Guardar el mismo equipo no genera aviso.
// Debe llamarse DESPUES del commit: el helper es fire-and-forget y nunca rompe
// la operacion principal.
function proyecto_notificar_equipo(
    string $proyectoId,
    string $nombreProyecto,
    array $equipoNuevo,
    array $equipoPrevio = []
): void {
    $actor = usuario_actual();
    $recienAgregados = array_values(array_filter(
        array_diff($equipoNuevo, $equipoPrevio),
        static fn ($uid) => $uid !== ($actor['id'] ?? null)
    ));
    if (!$recienAgregados) {
        return;
    }

    notificar(
        $recienAgregados,
        'Proyectos',
        'equipo_agregado',
        'Te agregaron a un proyecto',
        'Ahora formas parte del equipo del proyecto «' . $nombreProyecto . '»',
        'proyecto',
        $proyectoId,
        ['url' => 'index.php?vista=proyectos&detalle=' . $proyectoId]
    );
}

// Notificacion in-app "cambio el estado del proyecto" (Fase 7). Solo la usa
// actualizar.php: en crear.php el estado inicial no es un cambio.
//
// Destinatarios: los integrantes que YA estaban en el equipo y siguen en el
// (nuevo interseccion previo), menos el actor. Se dejan fuera a proposito:
//  - los RECIEN agregados, que en ese mismo guardado ya reciben "te agregaron
//    a un proyecto" (dos avisos por una sola accion serian ruido);
//  - los que salieron del equipo, que ya no lo trabajan.
// Debe llamarse DESPUES del commit y SOLO si el estado cambio de verdad.
function proyecto_notificar_estado(
    string $proyectoId,
    string $nombreProyecto,
    string $estadoPrevio,
    string $estadoNuevo,
    array $equipoNuevo,
    array $equipoPrevio
): void {
    if ($estadoPrevio === $estadoNuevo) {
        return;   // guardar sin tocar el estado no notifica
    }

    $actor = usuario_actual();
    $destinatarios = array_values(array_filter(
        array_intersect($equipoNuevo, $equipoPrevio),
        static fn ($uid) => $uid !== ($actor['id'] ?? null)
    ));
    if (!$destinatarios) {
        return;
    }

    notificar(
        $destinatarios,
        'Proyectos',
        'estado_proyecto',
        'Cambio el estado de un proyecto',
        'El proyecto «' . $nombreProyecto . '» paso de «' . $estadoPrevio . '» a «' . $estadoNuevo . '»',
        'proyecto',
        $proyectoId,
        ['url' => 'index.php?vista=proyectos&detalle=' . $proyectoId]
    );
}
