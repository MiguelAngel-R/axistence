<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Agregar una nota al servidor.
//  POST JSON: { vps_id, nota, criticidad }
//  Inserta en vps_notas (vinculada al VPS y al autor) y audita en el VPS.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';
require_once __DIR__ . '/_fila_nota.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

const CRITICIDADES = ['Información', 'Advertencia', 'Importante', 'Crítica'];

$in         = body_json();
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$nota       = trim((string)($in['nota'] ?? ''));
$criticidad = (string)($in['criticidad'] ?? 'Información');

$pdo = Database::get();
$ref = exigir_vps($pdo, $vpsId);

if ($nota === '') {
    json_error('La nota no puede estar vacia', 422);
}
if (!in_array($criticidad, CRITICIDADES, true)) {
    json_error('La criticidad no es valida', 422);
}

$autor = usuario_actual();

$stmt = $pdo->prepare(
    'INSERT INTO public.vps_notas (vps_id, autor_id, nota, criticidad)
     VALUES (:v, :a, :n, :c) RETURNING id'
);
$stmt->execute([
    ':v' => $vpsId,
    ':a' => $autor['id'] ?? null,
    ':n' => $nota,
    ':c' => $criticidad,
]);
$id = $stmt->fetchColumn();

auditar_en_vps($vpsId, 'Agrego una nota (' . $criticidad . ') al servidor ' . $ref,
    ['nota' => $nota, 'criticidad' => $criticidad]);

// --- Tiempo real ----------------------------------------------------
// Como el Inventario/Virtual Hosts, la Nota NO tiene modulo propio: solo impacta
// la pestaña Notas del detalle del VPS. Se reconsulta la fila con la forma exacta
// que pinta ese tab (helper compartido: resuelve el autor) y se reparte UN evento
// a la sala 'vps' (fire-and-forget). Cada navegador decide si le corresponde por
// el vps_id. (El alta ademas ya salio en el Historial via auditar_en_vps.)
$filaNota = fila_nota_socket($pdo, (string)$id);
if ($filaNota) {
    notificar_socket('vps', 'nota_vps:creado', $filaNota);
}

json_ok(['id' => $id], 'Nota agregada correctamente');
