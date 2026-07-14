<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Agregar un evento al Inventario Logico.
//  (bitacora de software / configuracion: vps_historial_configuracion)
//  POST JSON: { vps_id, tipo_evento, software_componente, version?,
//               ruta_directorio_instalacion?, puertos_usados?,
//               servicios_rutas_acceso?, notas? }
//  El responsable es el usuario en sesion. Audita en el VPS.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_detalle.php';
require_once __DIR__ . '/_fila_inventario.php';

solo_metodo('POST');
requiere_permiso('VPS', 'editar');

const TIPOS_EVENTO = [
    'Sistema Operativo', 'Software', 'Librerías', 'Base de Datos',
];

$in         = body_json();
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$tipoEvento = (string)($in['tipo_evento'] ?? '');
$software   = trim((string)($in['software_componente'] ?? ''));
$version    = trim((string)($in['version'] ?? ''));
$ruta       = trim((string)($in['ruta_directorio_instalacion'] ?? ''));
$puertos    = trim((string)($in['puertos_usados'] ?? ''));
$servicios  = trim((string)($in['servicios_rutas_acceso'] ?? ''));
$notas      = trim((string)($in['notas'] ?? ''));

$pdo = Database::get();
$ref = exigir_vps($pdo, $vpsId);

if (!in_array($tipoEvento, TIPOS_EVENTO, true)) {
    json_error('El tipo de evento no es valido', 422);
}
if ($software === '') {
    json_error('El software / componente es obligatorio', 422);
}

$autor = usuario_actual();

$stmt = $pdo->prepare(
    'INSERT INTO public.vps_historial_configuracion
         (vps_id, tipo_evento, software_componente, version,
          ruta_directorio_instalacion, puertos_usados, servicios_rutas_acceso,
          responsable_id, notas)
     VALUES (:v, :te, :sw, :ver, :ruta, :ptos, :serv, :resp, :notas)
     RETURNING id'
);
$stmt->execute([
    ':v'     => $vpsId,
    ':te'    => $tipoEvento,
    ':sw'    => $software,
    ':ver'   => $version !== '' ? $version : null,
    ':ruta'  => $ruta !== '' ? $ruta : null,
    ':ptos'  => $puertos !== '' ? $puertos : null,
    ':serv'  => $servicios !== '' ? $servicios : null,
    ':resp'  => $autor['id'] ?? null,
    ':notas' => $notas !== '' ? $notas : null,
]);
$id = $stmt->fetchColumn();

auditar_en_vps($vpsId, 'Agrego al inventario logico: ' . $software . ' (' . $tipoEvento . ') en ' . $ref,
    ['tipo_evento' => $tipoEvento, 'software_componente' => $software, 'version' => $version]);

// --- Tiempo real ----------------------------------------------------
// El inventario logico NO tiene modulo propio: solo impacta la pestaña
// Inventario del detalle del VPS. Se reconsulta la fila con la forma exacta que
// pinta ese tab (helper compartido: resuelve el responsable) y se reparte UN
// evento a la sala 'vps' (fire-and-forget, nunca rompe la operacion). Cada
// navegador decide si le corresponde por el vps_id que viaja en la fila.
$filaInv = fila_inventario_socket($pdo, (string)$id);
if ($filaInv) {
    notificar_socket('vps', 'inventario_vps:creado', $filaInv);
}

json_ok(['id' => $id], 'Registro agregado al inventario logico');
