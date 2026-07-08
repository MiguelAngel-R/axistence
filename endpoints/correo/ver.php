<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Detalle consolidado de una RELACION de correo (para viewProducto).
//  GET params: id (uuid de la relacion)
//  Devuelve: dominio, cliente, servidor de correo (MX) y cantidad de cuentas
//  (en generales), mas extensiones de espacio y notas (con autor). El MX vive
//  en el DNS del dominio, por eso el correo ya no tiene registros DNS propios.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('GET');
requiere_permiso('Correo', 'ver');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

$stmt = $pdo->prepare(
    'SELECT cc.id, cc.cantidad_cuentas, cc.tipo_licencia,
            cc.precio_costo_cuenta, cc.precio_venta_cuenta,
            cc.fecha_registro, cc.fecha_vencimiento, cc.created_at, cc.updated_at,
            cc.dominio_id, d.nombre_dominio AS dominio,
            cc.cliente_id, c.nombre_razon_social AS cliente,
            cc.servidor_correo_vps_id, v.referencia_vps AS servidor_vps,
            cc.mx_registro_id, cc.servidor_correo_externo
       FROM public.cuentas_correo cc
       JOIN public.dominios d ON d.id = cc.dominio_id
       JOIN public.clientes c ON c.id = cc.cliente_id
       LEFT JOIN public.vps_servidores v ON v.id = cc.servidor_correo_vps_id
      WHERE cc.id = :id'
);
$stmt->execute([':id' => $id]);
$cuenta = $stmt->fetch();
if (!$cuenta) {
    json_error('Cuenta de correo no encontrada', 404);
}

// --- Licencias (tipo + cantidad + cuentas ya creadas) de la relacion -
$stmt = $pdo->prepare(
    'SELECT l.id, l.tipo_licencia, l.cantidad_cuentas,
            (SELECT COUNT(*) FROM public.correo_licencia_cuentas cu
              WHERE cu.licencia_id = l.id) AS cuentas_usadas
       FROM public.correo_licencias l
      WHERE l.cuenta_correo_id = :id
      ORDER BY l.orden ASC, l.created_at ASC'
);
$stmt->execute([':id' => $id]);
$licencias = $stmt->fetchAll();

// --- Extensiones de espacio (con la cuenta y su licencia) -----------
$stmt = $pdo->prepare(
    'SELECT e.id, e.gigas_adicionales, e.fecha_adquisicion,
            e.precio_compra_extension, e.precio_venta_extension,
            cu.nombre AS cuenta_nombre, cu.apellidos AS cuenta_apellidos,
            cu.correo AS cuenta_correo, l.tipo_licencia
       FROM public.correo_extensiones_espacio e
       LEFT JOIN public.correo_licencia_cuentas cu ON cu.id = e.cuenta_id
       LEFT JOIN public.correo_licencias l         ON l.id = cu.licencia_id
      WHERE e.cuenta_correo_id = :id
      ORDER BY e.fecha_adquisicion DESC, e.created_at DESC'
);
$stmt->execute([':id' => $id]);
$extensiones = $stmt->fetchAll();

// --- Notas (con autor) ----------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.id, n.nota, n.fecha, u.nombre_completo AS autor
       FROM public.correo_notas n
       LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
      WHERE n.cuenta_correo_id = :id
      ORDER BY n.fecha DESC'
);
$stmt->execute([':id' => $id]);
$notas = $stmt->fetchAll();

json_ok([
    'cuenta'      => $cuenta,
    'licencias'   => $licencias,
    'extensiones' => $extensiones,
    'notas'       => $notas,
]);
