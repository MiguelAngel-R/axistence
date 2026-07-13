<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Eliminar un certificado (registro principal del listado).
//  POST JSON: { id }
//    valida id -> comprueba existencia -> DELETE (caen en cascada sus notas y
//    la relacion con proyectos) -> borra el paquete fisico (best-effort) ->
//    audita ELIMINAR -> JSON.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

solo_metodo('POST');
requiere_permiso('SSL', 'eliminar');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in = body_json();
$id = trim((string)($in['id'] ?? ''));
if (!preg_match(UUID_RE, $id)) {
    json_error('Identificador no valido', 422);
}

$pdo = Database::get();

// Existencia + dominio (nombre visible) + ruta del paquete para limpiarlo.
$stmt = $pdo->prepare(
    'SELECT s.archivo_paquete_path, d.nombre_dominio
       FROM public.certificados_ssl s
       JOIN public.dominios d ON d.id = s.dominio_id
      WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$cert = $stmt->fetch();
if (!$cert) {
    json_error('Certificado SSL no encontrado', 404);
}

try {
    $pdo->prepare('DELETE FROM public.certificados_ssl WHERE id = :id')->execute([':id' => $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        json_error('No se puede eliminar el certificado porque tiene registros que lo bloquean', 409);
    }
    throw $e;
}

// Borrado del paquete fisico (best-effort: no debe romper la operacion).
// Misma validacion de ruta que la descarga, para impedir saltos de directorio.
$ruta = (string)$cert['archivo_paquete_path'];
if (preg_match('~^uploads/ssl/[a-f0-9]{32}\.(zip|rar)$~', $ruta)) {
    $abs = UPLOADS_DIR . '/ssl/' . basename($ruta);
    if (is_file($abs)) { @unlink($abs); }
}

registrar_auditoria(
    'ELIMINAR',
    'SSL',
    'Elimino el certificado SSL del dominio ' . $cert['nombre_dominio'],
    $id,
    ['dominio' => $cert['nombre_dominio']],
    null
);

// --- Tiempo real ----------------------------------------------------
// Se avisa a los navegadores que tienen el listado abierto para que quiten la
// fila sin recargar. Solo viaja el id (basta para localizar y quitar la fila).
// Se emite SOLO tras el borrado y la auditoria (no se emite en el caso 409:
// ahi no hubo borrado).
notificar_socket('ssl', 'ssl:eliminado', ['id' => $id]);

json_ok(['id' => $id], 'Certificado SSL eliminado correctamente');
