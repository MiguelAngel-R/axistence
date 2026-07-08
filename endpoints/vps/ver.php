<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Servidores - Detalle consolidado (para viewProducto).
//  GET params: id (uuid del VPS)
//  Devuelve el VPS con TODA su informacion relacionada, ordenada por
//  secciones (doc 5 / seccion 4.2.2 de la especificacion):
//    - proveedor
//    - clientes asociados (N:N)
//    - dominios alojados / apuntados
//    - certificados SSL configurados
//    - cuentas de correo alojadas
//    - servicios de hosting
//    - proyectos vinculados
//    - historial de configuracion (con responsable / usuario interno)
//    - notas (con autor / usuario interno)
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('VPS', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// --- Datos principales del VPS + proveedor --------------------------
// El hardware, los precios y el tipo se heredan de la referencia (JOIN).
$stmt = $pdo->prepare(
    'SELECT v.id, v.referencia_vps, v.referencia_vps_id, v.label,
            v.fecha_creacion, v.fecha_vencimiento, v.created_at, v.updated_at,
            v.proveedor_id, pr.nombre_proveedor AS proveedor, pr.sitio_web AS proveedor_sitio,
            r.tipo_servidor,
            r.disco_valor, r.disco_unidad, r.ram_valor, r.ram_unidad,
            r.ancho_banda_valor, r.ancho_banda_unidad,
            r.precio_compra, r.moneda_compra, r.precio_venta, r.moneda_venta
       FROM public.vps_servidores v
       JOIN public.proveedores pr ON pr.id = v.proveedor_id
       JOIN public.referencias_vps r ON r.id = v.referencia_vps_id
      WHERE v.id = :id'
);
$stmt->execute([':id' => $id]);
$vps = $stmt->fetch();
if (!$vps) {
    json_error('VPS no encontrado', 404);
}

// --- Clientes asociados (N:N) --------------------------------------
$stmt = $pdo->prepare(
    'SELECT c.id, c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion, c.estado
       FROM public.vps_clientes vc
       JOIN public.clientes c ON c.id = vc.cliente_id
      WHERE vc.vps_id = :id
      ORDER BY c.nombre_razon_social ASC'
);
$stmt->execute([':id' => $id]);
$clientes = $stmt->fetchAll();

// --- Dominios alojados / apuntados a este VPS ----------------------
$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre_dominio, pr.nombre_proveedor AS proveedor,
            d.fecha_vencimiento, d.precio_venta
       FROM public.dominios d
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
      WHERE d.vps_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$dominios = $stmt->fetchAll();

// --- Certificados SSL configurados en este VPS ---------------------
$stmt = $pdo->prepare(
    'SELECT s.id, d.nombre_dominio, pr.nombre_proveedor AS proveedor,
            s.fecha_vencimiento
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
       JOIN public.proveedores pr ON pr.id = s.proveedor_id
      WHERE s.vps_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$certificados = $stmt->fetchAll();

// --- Relaciones de correo servidas por este VPS --------------------
$stmt = $pdo->prepare(
    'SELECT cc.id, d.nombre_dominio AS dominio, cc.cantidad_cuentas,
            c.nombre_razon_social AS cliente, cc.fecha_vencimiento
       FROM public.cuentas_correo cc
       JOIN public.clientes c ON c.id = cc.cliente_id
       JOIN public.dominios d ON d.id = cc.dominio_id
      WHERE cc.servidor_correo_vps_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$correos = $stmt->fetchAll();

// --- Servicios de hosting sobre este VPS ---------------------------
$stmt = $pdo->prepare(
    'SELECT id, espacio_asignado, fecha_renovacion, precio_venta
       FROM public.hosting
      WHERE vps_id = :id
      ORDER BY fecha_renovacion ASC'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetchAll();

// --- Proyectos vinculados a este VPS -------------------------------
$stmt = $pdo->prepare(
    'SELECT p.id, p.nombre_proyecto, p.estado, pv.descripcion_uso
       FROM public.proyecto_vps pv
       JOIN public.proyectos p ON p.id = pv.proyecto_id
      WHERE pv.vps_id = :id
      ORDER BY p.nombre_proyecto ASC'
);
$stmt->execute([':id' => $id]);
$proyectos = $stmt->fetchAll();

// --- Historial de configuracion (con responsable) ------------------
$stmt = $pdo->prepare(
    'SELECT h.id, h.tipo_evento, h.software_componente, h.version,
            h.ruta_directorio_instalacion, h.puertos_usados,
            h.servicios_rutas_acceso, h.fecha, h.notas,
            u.nombre_completo AS responsable
       FROM public.vps_historial_configuracion h
       LEFT JOIN public.usuarios_internos u ON u.id = h.responsable_id
      WHERE h.vps_id = :id
      ORDER BY h.fecha DESC'
);
$stmt->execute([':id' => $id]);
$historial = $stmt->fetchAll();

// --- Notas (con autor y criticidad) --------------------------------
$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.criticidad, n.fecha, u.nombre_completo AS autor
       FROM public.vps_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.vps_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

// --- Virtual Hosts alojados en este VPS ----------------------------
$stmt = $pdo->prepare(
    'SELECT vh.id, vh.aplicacion, vh.server_name, vh.servidor_web, vh.puerto,
            vh.ssl_habilitado, vh.estado, vh.document_root, vh.proxy_pass,
            vh.puerto_aplicacion, vh.dominio_id, d.nombre_dominio AS dominio
       FROM public.virtual_hosts vh
       LEFT JOIN public.dominios d ON d.id = vh.dominio_id
      WHERE vh.vps_id = :id
      ORDER BY vh.aplicacion ASC'
);
$stmt->execute([':id' => $id]);
$virtualHosts = $stmt->fetchAll();

// --- Historial de auditoria (logs) especifico de este VPS ----------
// Muestra los eventos del log cuyo registro afectado es este VPS: su alta,
// ediciones y las altas/vinculaciones de activos hechas desde sus tabs
// (cada endpoint "agregar_*" audita con registro_id = id de este VPS).
$stmt = $pdo->prepare(
    'SELECT l.id, l.tipo_accion, l.modulo_afectado, l.descripcion, l.fecha_evento,
            u.nombre_completo AS usuario
       FROM public.logs_auditoria l
       LEFT JOIN public.usuarios_internos u ON u.id = l.usuario_id
      WHERE l.registro_id = :id
      ORDER BY l.fecha_evento DESC'
);
$stmt->execute([':id' => $id]);
$logs = $stmt->fetchAll();

json_ok([
    'vps'           => $vps,
    'clientes'      => $clientes,
    'dominios'      => $dominios,
    'certificados'  => $certificados,
    'correos'       => $correos,
    'hosting'       => $hosting,
    'proyectos'     => $proyectos,
    'historial'     => $historial,   // Inventario Logico (software/config)
    'notas'         => $notas,
    'virtual_hosts' => $virtualHosts,
    'logs'          => $logs,
]);
