<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Detalle consolidado (para viewProyecto).
//  GET params: id (uuid del proyecto)
//  Devuelve: proyecto + cliente (en generales), equipo (usuarios con su
//  rol en el proyecto), recursos N:N (dominios/vps/ssl/hosting) y notas.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Proyectos', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT p.id, p.nombre_proyecto, p.descripcion, p.estado,
            p.fecha_inicio, p.fecha_entrega_estimada,
            p.created_at, p.updated_at,
            p.cliente_id, c.nombre_razon_social AS cliente
       FROM public.proyectos p
       LEFT JOIN public.clientes c ON c.id = p.cliente_id
      WHERE p.id = :id'
);
$stmt->execute([':id' => $id]);
$proyecto = $stmt->fetch();
if (!$proyecto) {
    json_error('Proyecto no encontrado', 404);
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.nombre_completo, u.correo, pe.rol_en_proyecto, u.estado
       FROM public.proyecto_equipo pe
       JOIN public.usuarios_internos u ON u.id = pe.usuario_id
      WHERE pe.proyecto_id = :id
      ORDER BY u.nombre_completo ASC'
);
$stmt->execute([':id' => $id]);
$equipo = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre_dominio, pr.nombre_proveedor AS proveedor,
            d.fecha_vencimiento, pd.descripcion_uso
       FROM public.proyecto_dominios pd
       JOIN public.dominios d ON d.id = pd.dominio_id
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
      WHERE pd.proyecto_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$dominios = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT v.id, v.referencia_vps, r.tipo_servidor, pv.descripcion_uso
       FROM public.proyecto_vps pv
       JOIN public.vps_servidores v ON v.id = pv.vps_id
       JOIN public.referencias_vps r ON r.id = v.referencia_vps_id
      WHERE pv.proyecto_id = :id
      ORDER BY v.referencia_vps ASC'
);
$stmt->execute([':id' => $id]);
$vps = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT h.id, v.referencia_vps AS vps, h.espacio_asignado, ph.descripcion_uso
       FROM public.proyecto_hostings ph
       JOIN public.hosting h ON h.id = ph.hosting_id
       JOIN public.vps_servidores v ON v.id = h.vps_id
      WHERE ph.proyecto_id = :id
      ORDER BY v.referencia_vps ASC'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.fecha, u.nombre_completo AS autor
       FROM public.proyecto_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.proyecto_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

json_ok([
    'proyecto' => $proyecto,
    'equipo'   => $equipo,
    'dominios' => $dominios,
    'vps'      => $vps,
    'hosting'  => $hosting,
    'notas'    => $notas,
]);
