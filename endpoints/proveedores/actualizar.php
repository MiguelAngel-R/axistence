<?php
declare(strict_types=1);

// =====================================================================
//  Proveedores - Actualizar.
//  POST JSON: { id, nombre_proveedor, sitio_web?, tipos?: string[] }
//  Actualiza el proveedor y resincroniza sus tipos de producto (M:N)
//  dentro de una transaccion. Audita MODIFICAR con datos anteriores y
//  nuevos. Espejo de endpoints/clientes/actualizar.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Proveedores', 'editar');

// Tipos de producto validos (deben coincidir con el enum tipo_producto_enum).
const TIPOS_PRODUCTO_VALIDOS = ['Dominios', 'VPS', 'SSL', 'Correo', 'Hosting', 'Otros'];

$in       = body_json();
$id       = trim((string)($in['id'] ?? ''));
$nombre   = trim((string)($in['nombre_proveedor'] ?? ''));
$sitioWeb = trim((string)($in['sitio_web'] ?? ''));
$tipos    = $in['tipos'] ?? [];

$UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// --- Normalizacion de tipos ----------------------------------------
if (!is_array($tipos)) {
    $tipos = [];
}
$tipos = array_values(array_unique(array_filter(array_map(
    static fn($t) => trim((string)$t),
    $tipos
), static fn($t) => $t !== '')));
sort($tipos); // orden estable para comparar/auditar

// --- Validaciones ---------------------------------------------------
if (!preg_match($UUID, $id)) {
    json_error('Identificador no valido', 422);
}
$faltan = campos_faltantes(['nombre_proveedor' => $nombre], ['nombre_proveedor']);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 100) {
    json_error('El nombre del proveedor es demasiado largo (maximo 100 caracteres)', 422);
}
if ($sitioWeb !== '') {
    if (mb_strlen($sitioWeb) > 255) {
        json_error('El sitio web es demasiado largo (maximo 255 caracteres)', 422);
    }
    if (!preg_match('~^(https?://)?[\w.-]+\.[a-z]{2,}([/?#].*)?$~i', $sitioWeb)) {
        json_error('El sitio web no tiene un formato valido', 422);
    }
}
foreach ($tipos as $t) {
    if (!in_array($t, TIPOS_PRODUCTO_VALIDOS, true)) {
        json_error('Tipo de producto no valido: ' . $t, 422);
    }
}

$sitioWebBd = $sitioWeb !== '' ? $sitioWeb : null;

$pdo = Database::get();

// El proveedor debe existir (guardamos su estado previo para la auditoria).
$stmt = $pdo->prepare('SELECT nombre_proveedor, sitio_web FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $id]);
$actual = $stmt->fetch();
if (!$actual) {
    json_error('Proveedor no encontrado', 404);
}

// Tipos previos (para la auditoria del cambio).
$stmt = $pdo->prepare(
    'SELECT tipo_producto FROM public.proveedor_tipos_producto
      WHERE proveedor_id = :id ORDER BY tipo_producto'
);
$stmt->execute([':id' => $id]);
$tiposActuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

// --- Actualizacion (proveedor + resync de tipos) en transaccion -----
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'UPDATE public.proveedores
            SET nombre_proveedor = :n, sitio_web = :w
          WHERE id = :id'
    );
    $stmt->execute([':n' => $nombre, ':w' => $sitioWebBd, ':id' => $id]);

    // Resincronizacion simple: se borran los tipos previos y se insertan
    // los nuevos (el conjunto es pequeno y acotado por el enum).
    $pdo->prepare('DELETE FROM public.proveedor_tipos_producto WHERE proveedor_id = :id')
        ->execute([':id' => $id]);

    if ($tipos) {
        $insTipo = $pdo->prepare(
            'INSERT INTO public.proveedor_tipos_producto (proveedor_id, tipo_producto)
             VALUES (:p, :t)'
        );
        foreach ($tipos as $t) {
            $insTipo->execute([':p' => $id, ':t' => $t]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e; // lo maneja el handler global -> JSON 500
}

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'MODIFICAR',
    'Proveedores',
    'Actualizo el proveedor ' . $nombre,
    $id,
    [
        'nombre_proveedor' => $actual['nombre_proveedor'],
        'sitio_web'        => $actual['sitio_web'],
        'tipos'            => $tiposActuales,
    ],
    [
        'nombre_proveedor' => $nombre,
        'sitio_web'        => $sitioWebBd,
        'tipos'            => $tipos,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que reemplacen
// la fila sin recargar. El evento viaja con los datos ya actualizados (incluidos
// los tipos); el navegador los fusiona sobre la fila previa, asi que conserva lo
// que no viaja (p. ej. created_at) sin reconsultar la BD. Se emite SOLO tras el
// commit y la auditoria.
notificar_socket('proveedores', 'proveedor:actualizado', [
    'id'               => $id,
    'nombre_proveedor' => $nombre,
    'sitio_web'        => $sitioWebBd,
    'tipos'            => $tipos,
]);

json_ok(['id' => $id], 'Proveedor actualizado correctamente');
