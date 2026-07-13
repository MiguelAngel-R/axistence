<?php
declare(strict_types=1);

// =====================================================================
//  Proveedores - Crear.
//  POST JSON: { nombre_proveedor, sitio_web?, tipos?: string[] }
//  'tipos' son los tipos de producto que ofrece (M:N con el enum
//  tipo_producto_enum). Se inserta el proveedor y sus tipos dentro de
//  una transaccion para mantener la consistencia.
//  Espejo de endpoints/clientes/crear.php (mismo patron).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('Proveedores', 'crear');

// Tipos de producto validos (deben coincidir con el enum tipo_producto_enum).
const TIPOS_PRODUCTO_VALIDOS = ['Dominios', 'VPS', 'SSL', 'Correo', 'Hosting', 'Otros'];

$in       = body_json();
$nombre   = trim((string)($in['nombre_proveedor'] ?? ''));
$sitioWeb = trim((string)($in['sitio_web'] ?? ''));
$tipos    = $in['tipos'] ?? [];

// --- Normalizacion de tipos ----------------------------------------
if (!is_array($tipos)) {
    $tipos = [];
}
$tipos = array_values(array_unique(array_filter(array_map(
    static fn($t) => trim((string)$t),
    $tipos
), static fn($t) => $t !== '')));

// --- Validaciones ---------------------------------------------------
$faltan = campos_faltantes(['nombre_proveedor' => $nombre], ['nombre_proveedor']);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 100) {
    json_error('El nombre del proveedor es demasiado largo (maximo 100 caracteres)', 422);
}
// El sitio web es opcional; si viene, se valida su formato y longitud.
if ($sitioWeb !== '') {
    if (mb_strlen($sitioWeb) > 255) {
        json_error('El sitio web es demasiado largo (maximo 255 caracteres)', 422);
    }
    if (!preg_match('~^(https?://)?[\w.-]+\.[a-z]{2,}([/?#].*)?$~i', $sitioWeb)) {
        json_error('El sitio web no tiene un formato valido', 422);
    }
}
// Cada tipo de producto debe pertenecer al enum permitido.
foreach ($tipos as $t) {
    if (!in_array($t, TIPOS_PRODUCTO_VALIDOS, true)) {
        json_error('Tipo de producto no valido: ' . $t, 422);
    }
}

$sitioWebBd = $sitioWeb !== '' ? $sitioWeb : null;

$pdo = Database::get();

// --- Insercion (proveedor + tipos) dentro de una transaccion --------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.proveedores (nombre_proveedor, sitio_web)
         VALUES (:n, :w)
         RETURNING id, created_at'
    );
    $stmt->execute([':n' => $nombre, ':w' => $sitioWebBd]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    $id   = $fila['id'];

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
    'CREAR',
    'Proveedores',
    'Creo el proveedor ' . $nombre,
    $id,
    null,
    [
        'nombre_proveedor' => $nombre,
        'sitio_web'        => $sitioWebBd,
        'tipos'            => $tipos,
    ]
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que inserten
// la fila sin recargar. Se emite SOLO tras el commit y la auditoria: el evento
// viaja con los datos de la fila (incluidos los tipos) para no volver a
// consultar la BD en el cliente.
notificar_socket('proveedores', 'proveedor:creado', [
    'id'               => $id,
    'nombre_proveedor' => $nombre,
    'sitio_web'        => $sitioWebBd,
    'tipos'            => $tipos,
    'created_at'       => $fila['created_at'],
]);

json_ok(['id' => $id], 'Proveedor creado correctamente');
