<?php
declare(strict_types=1);

// =====================================================================
//  Dominios (detalle) - Agregar un registro DNS.
//  POST JSON: { dominio_id, tipo_registro, nombre, valor, ttl?, prioridad? }
//  Inserta en dominio_registros_dns (misma tabla para todos los tipos; las
//  pestañas A/AAAA/MX/TXT/CNAME son vistas filtradas por tipo_registro) y
//  audita el evento en el dominio (visible en el tab Historial).
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'editar');

const TIPOS_DNS = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

$in        = body_json();
$dominioId = trim((string)($in['dominio_id'] ?? ''));
$tipo      = strtoupper(trim((string)($in['tipo_registro'] ?? '')));
$nombre    = trim((string)($in['nombre'] ?? ''));
$valor     = trim((string)($in['valor'] ?? ''));
$ttl       = $in['ttl'] ?? 3600;
$prioridad = $in['prioridad'] ?? '';

$pdo    = Database::get();
$dominio = exigir_dominio($pdo, $dominioId);

// --- Validaciones ---------------------------------------------------
if (!in_array($tipo, TIPOS_DNS, true)) {
    json_error('El tipo de registro DNS no es valido', 422);
}
if ($nombre === '') {
    json_error('El nombre (host) es obligatorio (usa @ para la raiz)', 422);
}
if ($valor === '') {
    json_error('El valor del registro es obligatorio', 422);
}
if (!is_numeric($ttl) || (int)$ttl < 0) {
    json_error('El TTL no es valido', 422);
}
// La prioridad solo aplica (y es obligatoria) para MX.
$prioridadBd = null;
if ($tipo === 'MX') {
    if ($prioridad === '' || !is_numeric($prioridad) || (int)$prioridad < 0) {
        json_error('La prioridad es obligatoria para un registro MX', 422);
    }
    $prioridadBd = (int)$prioridad;
} elseif ($prioridad !== '' && is_numeric($prioridad)) {
    $prioridadBd = (int)$prioridad; // se acepta si viene, pero no es obligatoria
}

$stmt = $pdo->prepare(
    'INSERT INTO public.dominio_registros_dns
         (dominio_id, tipo_registro, nombre, valor, ttl, prioridad)
     VALUES (:d, :t, :n, :v, :ttl, :prio)
     RETURNING id'
);
$stmt->execute([
    ':d'    => $dominioId,
    ':t'    => $tipo,
    ':n'    => $nombre,
    ':v'    => $valor,
    ':ttl'  => (int)$ttl,
    ':prio' => $prioridadBd,
]);
$id = $stmt->fetchColumn();

auditar_en_dominio($dominioId, 'Agrego un registro DNS ' . $tipo . ' (' . $nombre . ') a ' . $dominio,
    ['tipo_registro' => $tipo, 'nombre' => $nombre, 'valor' => $valor, 'ttl' => (int)$ttl, 'prioridad' => $prioridadBd]);

// --- Tiempo real ----------------------------------------------------
// Avisa a los navegadores que tienen abierto el DETALLE de este dominio para que
// agreguen la fila del registro DNS (en la pestaña general y en la de su tipo)
// sin recargar ni volver a consultar la BD. El evento llega a toda la sala del
// modulo; viaja con el dominio_id para que cada navegador decida si le
// corresponde, y con los datos de la fila. Se emite SOLO tras la insercion y la
// auditoria (fire-and-forget: nunca rompe la operacion).
notificar_socket('dominios', 'dns:creado', [
    'id'            => $id,
    'dominio_id'    => $dominioId,
    'tipo_registro' => $tipo,
    'nombre'        => $nombre,
    'valor'         => $valor,
    'ttl'           => (int)$ttl,
    'prioridad'     => $prioridadBd,
]);

json_ok(['id' => $id], 'Registro DNS agregado correctamente');
