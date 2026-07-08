<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Crear.
//  POST JSON: { nombre_proyecto, cliente_id?, estado?, fecha_inicio,
//               fecha_entrega_estimada?, descripcion?,
//               equipo?: uuid[], dominios?: uuid[], vps?: uuid[],
//               ssl?: uuid[], hosting?: uuid[] }
//  Inserta el proyecto + relaciones N:N en transaccion.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';

solo_metodo('POST');
requiere_permiso('Proyectos', 'crear');

$in       = body_json();
$nombre   = trim((string)($in['nombre_proyecto'] ?? ''));
$clienteId = trim((string)($in['cliente_id'] ?? ''));
$estado   = trim((string)($in['estado'] ?? 'Planificación'));
$fInicio  = trim((string)($in['fecha_inicio'] ?? ''));
$fEntrega = trim((string)($in['fecha_entrega_estimada'] ?? ''));
$descrip  = trim((string)($in['descripcion'] ?? ''));
$vpsId    = trim((string)($in['vps_id'] ?? ''));   // servidor unico (opcional)

if ($vpsId !== '' && !preg_match(UUID_RE, $vpsId)) {
    json_error('El servidor seleccionado no es valido', 422);
}

$rels = [
    'equipo'   => uuids_o_error($in['equipo']   ?? [], 'Alguno de los integrantes del equipo no es valido'),
    'dominios' => uuids_o_error($in['dominios'] ?? [], 'Alguno de los dominios seleccionados no es valido'),
    'vps'      => $vpsId !== '' ? [$vpsId] : [],
    'hosting'  => uuids_o_error($in['hosting']  ?? [], 'Alguno de los hostings seleccionados no es valido'),
];

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(
    ['nombre_proyecto' => $nombre, 'fecha_inicio' => $fInicio],
    ['nombre_proyecto', 'fecha_inicio']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 200) {
    json_error('El nombre del proyecto es demasiado largo (maximo 200 caracteres)', 422);
}
if (!in_array($estado, ESTADOS_PROYECTO, true)) {
    json_error('El estado del proyecto no es valido', 422);
}
if (!fecha_valida($fInicio)) {
    json_error('La fecha de inicio no es valida', 422);
}
if ($fEntrega !== '' && !fecha_valida($fEntrega)) {
    json_error('La fecha de entrega estimada no es valida', 422);
}
if ($clienteId !== '' && !preg_match(UUID_RE, $clienteId)) {
    json_error('El cliente seleccionado no es valido', 422);
}

$clienteBd = $clienteId !== '' ? $clienteId : null;
$entregaBd = $fEntrega !== '' ? $fEntrega : null;
$descripBd = $descrip !== '' ? $descrip : null;

$pdo = Database::get();

if ($clienteBd !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM public.clientes WHERE id = :id');
    $stmt->execute([':id' => $clienteBd]);
    if (!$stmt->fetchColumn()) {
        json_error('El cliente seleccionado no existe', 422);
    }
}

// --- Insercion (proyecto + relaciones) en transaccion ---------------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.proyectos
            (nombre_proyecto, cliente_id, estado, fecha_inicio,
             fecha_entrega_estimada, descripcion)
         VALUES (:n, :cli, :est, :fi, :fe, :desc)
         RETURNING id'
    );
    $stmt->execute([
        ':n' => $nombre, ':cli' => $clienteBd, ':est' => $estado,
        ':fi' => $fInicio, ':fe' => $entregaBd, ':desc' => $descripBd,
    ]);
    $id = $stmt->fetchColumn();

    proyecto_sincronizar_relaciones($pdo, $id, $rels, false);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('Alguno de los recursos o integrantes seleccionados no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'CREAR',
    'Proyectos',
    'Creo el proyecto ' . $nombre,
    $id,
    null,
    [
        'nombre_proyecto'        => $nombre,
        'cliente_id'             => $clienteBd,
        'estado'                 => $estado,
        'fecha_inicio'           => $fInicio,
        'fecha_entrega_estimada' => $entregaBd,
        'equipo'                 => $rels['equipo'],
        'dominios'               => $rels['dominios'],
        'vps'                    => $vpsId !== '' ? $vpsId : null,
        'hosting'                => $rels['hosting'],
    ]
);

json_ok(['id' => $id], 'Proyecto creado correctamente');
