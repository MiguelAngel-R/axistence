<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Actualizar.
//  POST JSON: { id, nombre_proyecto, cliente_id?, estado?, fecha_inicio,
//               fecha_entrega_estimada?, descripcion?,
//               equipo?, dominios?, vps?, ssl?, hosting? }
//  Actualiza el proyecto y resincroniza sus relaciones N:N en transaccion.
//  Espejo de endpoints/proyectos/crear.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_comun.php';
require_once __DIR__ . '/_fila_socket.php';

solo_metodo('POST');
requiere_permiso('Proyectos', 'editar');

$in       = body_json();
$id       = trim((string)($in['id'] ?? ''));
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
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}
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

$stmt = $pdo->prepare(
    'SELECT nombre_proyecto, cliente_id, estado, fecha_inicio,
            fecha_entrega_estimada, descripcion
       FROM public.proyectos WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Proyecto no encontrado', 404);
}

if ($clienteBd !== null) {
    $stmt = $pdo->prepare('SELECT 1 FROM public.clientes WHERE id = :id');
    $stmt->execute([':id' => $clienteBd]);
    if (!$stmt->fetchColumn()) {
        json_error('El cliente seleccionado no existe', 422);
    }
}

// Estado anterior de las relaciones (para la auditoria).
$relPrevio = [];
$consultas = [
    'equipo'   => 'SELECT usuario_id  FROM public.proyecto_equipo    WHERE proyecto_id = :id ORDER BY usuario_id',
    'dominios' => 'SELECT dominio_id   FROM public.proyecto_dominios  WHERE proyecto_id = :id ORDER BY dominio_id',
    'vps'      => 'SELECT vps_id       FROM public.proyecto_vps       WHERE proyecto_id = :id ORDER BY vps_id',
    'hosting'  => 'SELECT hosting_id   FROM public.proyecto_hostings  WHERE proyecto_id = :id ORDER BY hosting_id',
];
foreach ($consultas as $clave => $sql) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $relPrevio[$clave] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// --- Actualizacion (proyecto + resync relaciones) en transaccion ----
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.proyectos
            SET nombre_proyecto = :n, cliente_id = :cli, estado = :est,
                fecha_inicio = :fi, fecha_entrega_estimada = :fe,
                descripcion = :desc
          WHERE id = :id'
    );
    $stmt->execute([
        ':n' => $nombre, ':cli' => $clienteBd, ':est' => $estado,
        ':fi' => $fInicio, ':fe' => $entregaBd, ':desc' => $descripBd, ':id' => $id,
    ]);

    proyecto_sincronizar_relaciones($pdo, $id, $rels, true);

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('Alguno de los recursos o integrantes seleccionados no existe', 422);
    }
    throw $e;
}

registrar_auditoria(
    'MODIFICAR',
    'Proyectos',
    'Actualizo el proyecto ' . $nombre,
    $id,
    [
        'nombre_proyecto'        => $actual['nombre_proyecto'],
        'cliente_id'             => $actual['cliente_id'],
        'estado'                 => $actual['estado'],
        'fecha_inicio'           => $actual['fecha_inicio'],
        'fecha_entrega_estimada' => $actual['fecha_entrega_estimada'],
        'equipo'                 => $relPrevio['equipo'],
        'dominios'               => $relPrevio['dominios'],
        'vps'                    => $relPrevio['vps'][0] ?? null,
        'hosting'                => $relPrevio['hosting'],
    ],
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

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que reemplacen
// la fila sin recargar. Se reconsulta con la MISMA forma que el listado (helper
// compartido: cliente y recursos N:N ya resueltos), asi el navegador no
// reconsulta la BD. Se emite SOLO tras el commit y la auditoria.
$filaProy = fila_proyecto_socket($pdo, $id);
if ($filaProy) {
    notificar_socket('proyectos', 'proyecto:actualizado', $filaProy);
}

json_ok(['id' => $id], 'Proyecto actualizado correctamente');
