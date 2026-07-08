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
