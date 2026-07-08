<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Detalle consolidado (para viewProducto).
//  GET params: id (uuid del dominio)
//  Devuelve el dominio con TODA su informacion relacionada por secciones:
//    - proveedor y VPS (en los datos generales)
//    - clientes (N:N), registros DNS, certificados SSL, cuentas de correo,
//      hosting (N:N), proyectos y notas (con autor = usuario interno).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Dominios', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// --- Datos principales + proveedor + VPS ----------------------------
$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre_dominio, d.fecha_registro, d.fecha_vencimiento,
            d.precio_compra, d.precio_venta, d.created_at, d.updated_at,
            d.proveedor_id, pr.nombre_proveedor AS proveedor,
            d.vps_id, v.referencia_vps AS vps, d.vps_externa,
            d.tipo_administracion, d.cuenta_id,
            ca.alias AS cuenta_alias, ca.url_acceso AS cuenta_url,
            ca.usuario AS cuenta_usuario, ca.clave AS cuenta_clave,
            ca.correo_recuperacion AS cuenta_correo_rec, ca.telefono_recuperacion AS cuenta_tel_rec
       FROM public.dominios d
       JOIN public.proveedores pr ON pr.id = d.proveedor_id
       LEFT JOIN public.vps_servidores v ON v.id = d.vps_id
       LEFT JOIN public.cuentas_acceso ca ON ca.id = d.cuenta_id
      WHERE d.id = :id'
);
$stmt->execute([':id' => $id]);
$dominio = $stmt->fetch();
if (!$dominio) {
    json_error('Dominio no encontrado', 404);
}

// --- Clientes (N:N) -------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT c.id, c.nombre_razon_social, c.tipo_identificacion, c.numero_identificacion, c.estado
       FROM public.dominio_clientes dc
       JOIN public.clientes c ON c.id = dc.cliente_id
      WHERE dc.dominio_id = :id
      ORDER BY c.nombre_razon_social ASC'
);
$stmt->execute([':id' => $id]);
$clientes = $stmt->fetchAll();

// --- Registros DNS (una sola tabla; el front la filtra por tipo) ----
$stmt = $pdo->prepare(
    'SELECT id, tipo_registro, nombre, valor, ttl, prioridad
       FROM public.dominio_registros_dns
      WHERE dominio_id = :id
      ORDER BY tipo_registro ASC, nombre ASC'
);
$stmt->execute([':id' => $id]);
$dns = $stmt->fetchAll();

// --- Metodos 2FA de la cuenta (si el dominio es de administracion PROPIA) --
$dosFa = [];
if (!empty($dominio['cuenta_id'])) {
    $stmt = $pdo->prepare(
        'SELECT f.id, f.aplicacion, f.notas, u.nombre_completo AS responsable,
                (f.llaves_recuperacion_path IS NOT NULL) AS tiene_llaves
           FROM public.cuenta_2fa f
           LEFT JOIN public.usuarios_internos u ON u.id = f.responsable_id
          WHERE f.cuenta_id = :c
          ORDER BY f.aplicacion ASC'
    );
    $stmt->execute([':c' => $dominio['cuenta_id']]);
    $dosFa = $stmt->fetchAll();
}

// --- Certificados SSL de este dominio -------------------------------
$stmt = $pdo->prepare(
    'SELECT s.id, pr.nombre_proveedor AS proveedor,
            s.fecha_registro, s.fecha_vencimiento
       FROM public.certificados_ssl s
       JOIN public.proveedores pr ON pr.id = s.proveedor_id
      WHERE s.dominio_id = :id
      ORDER BY s.fecha_vencimiento ASC'
);
$stmt->execute([':id' => $id]);
$certificados = $stmt->fetchAll();

// --- Relaciones de correo de este dominio ---------------------------
$stmt = $pdo->prepare(
    'SELECT cc.id, cc.cantidad_cuentas, cc.servidor_correo_externo,
            c.nombre_razon_social AS cliente, cc.fecha_vencimiento
       FROM public.cuentas_correo cc
       JOIN public.clientes c ON c.id = cc.cliente_id
      WHERE cc.dominio_id = :id
      ORDER BY c.nombre_razon_social ASC'
);
$stmt->execute([':id' => $id]);
$correos = $stmt->fetchAll();

// --- Hosting donde se aloja (N:N) -----------------------------------
$stmt = $pdo->prepare(
    'SELECT h.id, h.espacio_asignado, h.vps_id, v.referencia_vps AS vps,
            h.fecha_renovacion
       FROM public.hosting_dominios hd
       JOIN public.hosting h ON h.id = hd.hosting_id
       LEFT JOIN public.vps_servidores v ON v.id = h.vps_id
      WHERE hd.dominio_id = :id
      ORDER BY h.fecha_renovacion ASC'
);
$stmt->execute([':id' => $id]);
$hosting = $stmt->fetchAll();

// --- Proyectos vinculados -------------------------------------------
$stmt = $pdo->prepare(
    'SELECT p.id, p.nombre_proyecto, p.estado, pd.descripcion_uso
       FROM public.proyecto_dominios pd
       JOIN public.proyectos p ON p.id = pd.proyecto_id
      WHERE pd.dominio_id = :id
      ORDER BY p.nombre_proyecto ASC'
);
$stmt->execute([':id' => $id]);
$proyectos = $stmt->fetchAll();

// --- Notas (con autor) ----------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.criticidad, n.fecha, u.nombre_completo AS autor
       FROM public.dominio_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.dominio_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

// --- Historial (logs de auditoria especificos de este dominio) ------
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
    'dominio'      => $dominio,
    'clientes'     => $clientes,
    'dns'          => $dns,
    'certificados' => $certificados,
    'correos'      => $correos,
    'hosting'      => $hosting,
    'proyectos'    => $proyectos,
    'notas'        => $notas,
    'dos_fa'       => $dosFa,
    'logs'         => $logs,
]);
