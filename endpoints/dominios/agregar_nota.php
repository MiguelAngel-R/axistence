<?php
declare(strict_types=1);

// =====================================================================
//  Dominios (detalle) - Agregar una nota al dominio.
//  POST JSON: { dominio_id, nota, criticidad? }
//  Inserta en dominio_notas (con autor y criticidad) y audita en el dominio.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'editar');

const CRITICIDADES_VALIDAS = ['Información', 'Advertencia', 'Importante', 'Crítica'];

$in         = body_json();
$dominioId  = trim((string)($in['dominio_id'] ?? ''));
$nota       = trim((string)($in['nota'] ?? ''));
$criticidad = (string)($in['criticidad'] ?? 'Información');

$pdo     = Database::get();
$dominio = exigir_dominio($pdo, $dominioId);

if ($nota === '') {
    json_error('La nota no puede estar vacia', 422);
}
if (!in_array($criticidad, CRITICIDADES_VALIDAS, true)) {
    json_error('La criticidad seleccionada no es valida', 422);
}

$autor = usuario_actual();

$stmt = $pdo->prepare(
    'INSERT INTO public.dominio_notas (dominio_id, autor_id, nota, criticidad)
     VALUES (:d, :a, :n, :c) RETURNING id'
);
$stmt->execute([
    ':d' => $dominioId,
    ':a' => $autor['id'] ?? null,
    ':n' => $nota,
    ':c' => $criticidad,
]);
$id = $stmt->fetchColumn();

auditar_en_dominio($dominioId, 'Agrego una nota a ' . $dominio, ['nota' => $nota, 'criticidad' => $criticidad]);

json_ok(['id' => $id], 'Nota agregada correctamente');
