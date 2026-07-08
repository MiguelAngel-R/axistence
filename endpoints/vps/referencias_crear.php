<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Crear una referencia (modal anidado "Nueva referencia").
//  POST JSON: {
//    proveedor_id, nombre,
//    disco_valor, disco_unidad,            (unidad: GB / TB)
//    ram_valor, ram_unidad,
//    ancho_banda_valor?, ancho_banda_unidad?,
//    precio_compra?, moneda_compra?,       (moneda: COP / USD)
//    precio_venta?, moneda_venta?,
//    tipo_servidor?                        (Dedicado / Compartido)
//  }
//  La referencia es la PLANTILLA tecnica/economica del VPS: guarda hardware,
//  costos y tipo de servidor. Es unica por (proveedor, nombre). Se audita el
//  evento y se devuelve el objeto completo para precargarlo en el combobox del
//  modal padre (VPS) y mostrar su resumen de specs heredadas.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('VPS', 'crear');

const UUID_RE          = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
const UNIDADES_VALIDAS = ['GB', 'TB'];
const MONEDAS_VALIDAS  = ['COP', 'USD'];
const TIPOS_SERVIDOR   = ['Dedicado', 'Compartido'];

$in         = body_json();
$proveedor  = trim((string)($in['proveedor_id'] ?? ''));
$nombre     = trim((string)($in['nombre'] ?? ''));
$discoVal   = trim((string)($in['disco_valor'] ?? ''));
$discoUni   = strtoupper((string)($in['disco_unidad'] ?? 'GB'));
$ramVal     = trim((string)($in['ram_valor'] ?? ''));
$ramUni     = strtoupper((string)($in['ram_unidad'] ?? 'GB'));
$abVal      = trim((string)($in['ancho_banda_valor'] ?? ''));
$abUni      = strtoupper((string)($in['ancho_banda_unidad'] ?? 'TB'));
$precioC    = $in['precio_compra'] ?? 0;
$precioV    = $in['precio_venta'] ?? 0;
$monedaC    = (string)($in['moneda_compra'] ?? 'COP');
$monedaV    = (string)($in['moneda_venta'] ?? 'COP');
$tipoServ   = (string)($in['tipo_servidor'] ?? 'Compartido');

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if ($nombre === '') {
    json_error('El nombre de la referencia es obligatorio', 422);
}
if (mb_strlen($nombre) > 100) {
    json_error('La referencia es demasiado larga (maximo 100 caracteres)', 422);
}
// Hardware: disco y RAM obligatorios y numericos; ancho de banda opcional.
if (!is_numeric($discoVal) || (float)$discoVal < 0) {
    json_error('La capacidad de disco debe ser un numero valido', 422);
}
if (!is_numeric($ramVal) || (float)$ramVal < 0) {
    json_error('La memoria RAM debe ser un numero valido', 422);
}
if ($abVal !== '' && (!is_numeric($abVal) || (float)$abVal < 0)) {
    json_error('El ancho de banda debe ser un numero valido', 422);
}
// Unidades de medida.
if (!in_array($discoUni, UNIDADES_VALIDAS, true)
    || !in_array($ramUni, UNIDADES_VALIDAS, true)
    || !in_array($abUni, UNIDADES_VALIDAS, true)) {
    json_error('La unidad de medida seleccionada no es valida', 422);
}
// Precios y monedas.
if (!is_numeric($precioC) || (float)$precioC < 0) {
    json_error('El precio de compra no es valido', 422);
}
if (!is_numeric($precioV) || (float)$precioV < 0) {
    json_error('El precio de venta no es valido', 422);
}
if (!in_array($monedaC, MONEDAS_VALIDAS, true) || !in_array($monedaV, MONEDAS_VALIDAS, true)) {
    json_error('La moneda seleccionada no es valida', 422);
}
// Tipo de servidor.
if (!in_array($tipoServ, TIPOS_SERVIDOR, true)) {
    json_error('El tipo de servidor no es valido', 422);
}

// --- Normalizacion para la BD (numeric(12,2)) -----------------------
$discoBd   = number_format((float)$discoVal, 2, '.', '');
$ramBd     = number_format((float)$ramVal, 2, '.', '');
$abBd      = $abVal !== '' ? number_format((float)$abVal, 2, '.', '') : null;
$precioCbd = number_format((float)$precioC, 2, '.', '');
$precioVbd = number_format((float)$precioV, 2, '.', '');

$pdo = Database::get();

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// --- Insercion (unica por proveedor + nombre) -----------------------
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.referencias_vps
            (proveedor_id, nombre,
             disco_valor, disco_unidad, ram_valor, ram_unidad,
             ancho_banda_valor, ancho_banda_unidad,
             precio_compra, moneda_compra, precio_venta, moneda_venta, tipo_servidor)
         VALUES
            (:prov, :nombre,
             :dv, :du, :rv, :ru,
             :abv, :abu,
             :pc, :mc, :pv, :mv, :tipo)
         RETURNING id'
    );
    $stmt->execute([
        ':prov'   => $proveedor,
        ':nombre' => $nombre,
        ':dv'     => $discoBd,
        ':du'     => $discoUni,
        ':rv'     => $ramBd,
        ':ru'     => $ramUni,
        ':abv'    => $abBd,
        ':abu'    => $abUni,
        ':pc'     => $precioCbd,
        ':mc'     => $monedaC,
        ':pv'     => $precioVbd,
        ':mv'     => $monedaV,
        ':tipo'   => $tipoServ,
    ]);
    $id = (string)$stmt->fetchColumn();
} catch (PDOException $e) {
    // 23505 = violacion de unicidad (ya existe esa referencia para el proveedor).
    if ($e->getCode() === '23505') {
        json_error('Ya existe esa referencia para el proveedor seleccionado', 409);
    }
    throw $e;
}

// --- Objeto de respuesta (specs completas para el frontend) ---------
$referencia = [
    'id'                 => $id,
    'nombre'             => $nombre,
    'disco_valor'        => $discoBd,
    'disco_unidad'       => $discoUni,
    'ram_valor'          => $ramBd,
    'ram_unidad'         => $ramUni,
    'ancho_banda_valor'  => $abBd,
    'ancho_banda_unidad' => $abUni,
    'precio_compra'      => $precioCbd,
    'moneda_compra'      => $monedaC,
    'precio_venta'       => $precioVbd,
    'moneda_venta'       => $monedaV,
    'tipo_servidor'      => $tipoServ,
];

// --- Auditoria (trazabilidad obligatoria) ---------------------------
registrar_auditoria(
    'CREAR',
    'VPS',
    'Creo la referencia de VPS ' . $nombre,
    $id,
    null,
    ['proveedor_id' => $proveedor] + $referencia
);

json_ok($referencia, 'Referencia creada correctamente');
