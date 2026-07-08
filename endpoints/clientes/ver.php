<?php
declare(strict_types=1);

// =====================================================================
//  Clientes - Detalle consolidado (para viewClientes / Modulo 11).
//  GET params: id (uuid del cliente)
//  Devuelve el cliente con TODA su informacion relacionada:
//    - datos generales del cliente
//    - proyectos asociados (proyectos.cliente_id)
//    - productos que posee: VPS, dominios, cuentas de correo, hosting y
//      otros productos (via sus tablas puente / cliente_id)
//    - personas de contacto
//    - notas (con autor)
//    - historial de auditoria (logs cuyo registro afectado es el cliente)
//  Mismo patron/forma de respuesta que endpoints/vps/ver.php.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Clientes', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// --- Datos principales del cliente ----------------------------------
$stmt = $pdo->prepare(
    'SELECT c.id, c.tipo_cliente, c.razon_social, c.nombres, c.apellidos,
            c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion,
            c.email_general, c.telefono_principal, c.direccion, c.estado,
            c.created_at, c.updated_at
       FROM public.clientes c
      WHERE c.id = :id'
);
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();
if (!$cliente) {
    json_error('Cliente no encontrado', 404);
}

// --- Proyectos asociados (proyectos.cliente_id) ---------------------
// Cada proyecto trae EMBEBIDOS sus recursos (servidor/VPS, dominios, hosting,
// equipo y notas) via subconsultas json_agg, para poder abrir un modal con
// "a que apunta el proyecto" sin una segunda llamada ni el permiso Proyectos.
$stmt = $pdo->prepare(
    "SELECT p.id, p.nombre_proyecto, p.estado, p.fecha_inicio,
            p.fecha_entrega_estimada, p.descripcion,
            COALESCE((
                SELECT json_agg(json_build_object('id', v.id, 'nombre', v.referencia_vps)
                                ORDER BY v.referencia_vps)
                  FROM public.proyecto_vps pv
                  JOIN public.vps_servidores v ON v.id = pv.vps_id
                 WHERE pv.proyecto_id = p.id
            ), '[]') AS vps,
            COALESCE((
                SELECT json_agg(json_build_object('id', d.id, 'nombre', d.nombre_dominio)
                                ORDER BY d.nombre_dominio)
                  FROM public.proyecto_dominios pd
                  JOIN public.dominios d ON d.id = pd.dominio_id
                 WHERE pd.proyecto_id = p.id
            ), '[]') AS dominios,
            COALESCE((
                SELECT json_agg(json_build_object('id', h.id, 'nombre', v.referencia_vps,
                                                  'espacio', h.espacio_asignado)
                                ORDER BY v.referencia_vps)
                  FROM public.proyecto_hostings ph
                  JOIN public.hosting h ON h.id = ph.hosting_id
                  JOIN public.vps_servidores v ON v.id = h.vps_id
                 WHERE ph.proyecto_id = p.id
            ), '[]') AS hosting,
            COALESCE((
                SELECT json_agg(json_build_object('id', u.id, 'nombre', u.nombre_completo,
                                                  'rol', pe.rol_en_proyecto)
                                ORDER BY u.nombre_completo)
                  FROM public.proyecto_equipo pe
                  JOIN public.usuarios_internos u ON u.id = pe.usuario_id
                 WHERE pe.proyecto_id = p.id
            ), '[]') AS equipo,
            COALESCE((
                SELECT json_agg(json_build_object('nota', n.nota, 'fecha', n.fecha,
                                                  'autor', u.nombre_completo)
                                ORDER BY n.fecha DESC)
                  FROM public.proyecto_notas n
                  LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
                 WHERE n.proyecto_id = p.id
            ), '[]') AS notas
       FROM public.proyectos p
      WHERE p.cliente_id = :id
      ORDER BY p.fecha_inicio DESC, p.nombre_proyecto ASC"
);
$stmt->execute([':id' => $id]);
$proyectos = $stmt->fetchAll();
foreach ($proyectos as &$pr) {
    foreach (['vps', 'dominios', 'hosting', 'equipo', 'notas'] as $k) {
        $pr[$k] = json_decode($pr[$k] ?? '[]', true) ?: [];
    }
}
unset($pr);

// --- VPS del cliente (N:N vps_clientes; hardware/tipo por la referencia) ---
$stmt = $pdo->prepare(
    'SELECT v.id, v.referencia_vps, v.label, v.fecha_vencimiento,
            pr.nombre_proveedor AS proveedor, r.tipo_servidor
       FROM public.vps_clientes vc
       JOIN public.vps_servidores v ON v.id = vc.vps_id
       JOIN public.proveedores pr   ON pr.id = v.proveedor_id
       JOIN public.referencias_vps r ON r.id = v.referencia_vps_id
      WHERE vc.cliente_id = :id
      ORDER BY v.referencia_vps ASC'
);
$stmt->execute([':id' => $id]);
$vps = $stmt->fetchAll();

// --- Dominios del cliente (N:N dominio_clientes) --------------------
$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre_dominio, pr.nombre_proveedor AS proveedor,
            d.fecha_vencimiento
       FROM public.dominio_clientes dc
       JOIN public.dominios d     ON d.id = dc.dominio_id
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
      WHERE dc.cliente_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$dominios = $stmt->fetchAll();

// --- Cuentas de correo del cliente (cuentas_correo.cliente_id) ------
$stmt = $pdo->prepare(
    'SELECT cc.id, d.nombre_dominio AS dominio, cc.cantidad_cuentas,
            cc.fecha_vencimiento
       FROM public.cuentas_correo cc
       JOIN public.dominios d ON d.id = cc.dominio_id
      WHERE cc.cliente_id = :id
      ORDER BY d.nombre_dominio ASC'
);
$stmt->execute([':id' => $id]);
$correos = $stmt->fetchAll();

// --- Hosting del cliente (N:N cliente_hostings) --------------------
$stmt = $pdo->prepare(
    'SELECT h.id, h.espacio_asignado, h.fecha_renovacion,
            v.referencia_vps AS servidor
       FROM public.cliente_hostings ch
       JOIN public.hosting h ON h.id = ch.hosting_id
       LEFT JOIN public.vps_servidores v ON v.id = h.vps_id
      WHERE ch.cliente_id = :id
      ORDER BY h.fecha_renovacion ASC'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetchAll();

// --- Otros productos del cliente (N:N otros_productos_clientes) -----
$stmt = $pdo->prepare(
    'SELECT o.id, o.tipo_producto, o.nombre_referencia,
            pr.nombre_proveedor AS proveedor, o.fecha_vencimiento
       FROM public.otros_productos_clientes oc
       JOIN public.otros_productos o ON o.id = oc.producto_id
       JOIN public.proveedores pr    ON pr.id = o.proveedor_id
      WHERE oc.cliente_id = :id
      ORDER BY o.nombre_referencia ASC'
);
$stmt->execute([':id' => $id]);
$otros = $stmt->fetchAll();

// --- Personas de contacto del cliente ------------------------------
$stmt = $pdo->prepare(
    'SELECT id, nombre_completo, cargo_puesto, email, telefono_movil,
            telefono_fijo, es_contacto_principal
       FROM public.clientes_personas_contacto
      WHERE cliente_id = :id
      ORDER BY es_contacto_principal DESC, nombre_completo ASC'
);
$stmt->execute([':id' => $id]);
$contactos = $stmt->fetchAll();

// --- Notas (con autor) ---------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.fecha, u.nombre_completo AS autor
       FROM public.cliente_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.cliente_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

// --- Historial de auditoria del cliente ----------------------------
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
    'cliente'   => $cliente,
    'proyectos' => $proyectos,
    'vps'       => $vps,
    'dominios'  => $dominios,
    'correos'   => $correos,
    'hosting'   => $hosting,
    'otros'     => $otros,
    'contactos' => $contactos,
    'notas'     => $notas,
    'logs'      => $logs,
]);
