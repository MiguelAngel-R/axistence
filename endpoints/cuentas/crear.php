<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas de acceso - Crear (modal anidado desde el dominio).
//  POST JSON: {
//    proveedor_id, alias, url_acceso?, usuario?, clave?,
//    correo_recuperacion?, telefono_recuperacion?, notas?,
//    dos_fa?: [ { aplicacion, responsable_id?, llaves?: string|string[] } ]
//  }
//  Inserta la cuenta (vinculada al proveedor) y sus metodos 2FA en una
//  transaccion, y audita. Devuelve { id, alias } para precargar el combobox.
//  Las llaves de recuperacion de cada 2FA se guardan en un .txt bajo
//  uploads/llaves_2fa/<cuenta_id>/<2fa_id>/ (ver _llaves.php).
//  NOTA: la clave se guarda en TEXTO PLANO por requerimiento explicito.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/_llaves.php';

solo_metodo('POST');
requiere_permiso('Dominios', 'crear');

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$in        = body_json();
$proveedor = trim((string)($in['proveedor_id'] ?? ''));
$alias     = trim((string)($in['alias'] ?? ''));
$url       = trim((string)($in['url_acceso'] ?? ''));
$usuario   = trim((string)($in['usuario'] ?? ''));
$clave     = (string)($in['clave'] ?? '');
$correoRec = trim((string)($in['correo_recuperacion'] ?? ''));
$telRec    = trim((string)($in['telefono_recuperacion'] ?? ''));
$notas     = trim((string)($in['notas'] ?? ''));
$dosFa     = $in['dos_fa'] ?? [];

// --- Validaciones ---------------------------------------------------
if (!preg_match(UUID_RE, $proveedor)) {
    json_error('El proveedor seleccionado no es valido', 422);
}
if ($alias === '') {
    json_error('El alias de la cuenta es obligatorio', 422);
}
if (mb_strlen($alias) > 100) {
    json_error('El alias es demasiado largo (maximo 100 caracteres)', 422);
}
if ($correoRec !== '' && !filter_var($correoRec, FILTER_VALIDATE_EMAIL)) {
    json_error('El correo de recuperacion no es valido', 422);
}
if (!is_array($dosFa)) { $dosFa = []; }

$pdo = Database::get();

// El proveedor debe existir.
$stmt = $pdo->prepare('SELECT 1 FROM public.proveedores WHERE id = :id');
$stmt->execute([':id' => $proveedor]);
if (!$stmt->fetchColumn()) {
    json_error('El proveedor seleccionado no existe', 422);
}

// --- Insercion (cuenta + 2FA) en transaccion ------------------------
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO public.cuentas_acceso
            (proveedor_id, alias, url_acceso, usuario, clave,
             correo_recuperacion, telefono_recuperacion, notas)
         VALUES (:p, :al, :url, :us, :cl, :cr, :tr, :notas)
         RETURNING id'
    );
    $stmt->execute([
        ':p'     => $proveedor,
        ':al'    => $alias,
        ':url'   => $url !== '' ? $url : null,
        ':us'    => $usuario !== '' ? $usuario : null,
        ':cl'    => $clave !== '' ? $clave : null,
        ':cr'    => $correoRec !== '' ? $correoRec : null,
        ':tr'    => $telRec !== '' ? $telRec : null,
        ':notas' => $notas !== '' ? $notas : null,
    ]);
    $cuentaId = (string)$stmt->fetchColumn();

    // Metodos 2FA (opcionales, varios). Cada uno devuelve su id para poder
    // guardar sus llaves de recuperacion y registrar la ruta del archivo.
    $ins2fa = $pdo->prepare(
        'INSERT INTO public.cuenta_2fa (cuenta_id, aplicacion, responsable_id, notas)
         VALUES (:c, :app, :resp, :notas)
         RETURNING id'
    );
    $upd2faLlaves = $pdo->prepare(
        'UPDATE public.cuenta_2fa SET llaves_recuperacion_path = :p WHERE id = :id'
    );
    foreach ($dosFa as $fa) {
        $app  = trim((string)($fa['aplicacion'] ?? ''));
        $resp = trim((string)($fa['responsable_id'] ?? ''));
        if ($app === '') { continue; } // fila vacia -> se ignora
        if ($resp !== '' && !preg_match(UUID_RE, $resp)) {
            $pdo->rollBack();
            json_error('El responsable del 2FA no es valido', 422);
        }
        $ins2fa->execute([
            ':c'     => $cuentaId,
            ':app'   => $app,
            ':resp'  => $resp !== '' ? $resp : null,
            ':notas' => null,
        ]);
        $faId = (string)$ins2fa->fetchColumn();

        // Llaves de recuperacion de este 2FA -> archivo .txt en uploads.
        $llaves = normalizar_llaves_2fa($fa['llaves'] ?? []);
        if ($llaves) {
            $ruta = guardar_llaves_2fa($cuentaId, $faId, $llaves);
            $upd2faLlaves->execute([':p' => $ruta, ':id' => $faId]);
        }
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    if ($e->getCode() === '23503') {
        json_error('El responsable del 2FA no existe', 422);
    }
    throw $e;
} catch (RuntimeException $e) {
    // Fallo al escribir el archivo de llaves: se revierte la insercion.
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}

registrar_auditoria(
    'CREAR',
    'Proveedores',
    'Creo la cuenta de acceso ' . $alias,
    $cuentaId,
    null,
    ['proveedor_id' => $proveedor, 'alias' => $alias, 'usuario' => $usuario, 'metodos_2fa' => count($dosFa)]
);

json_ok(['id' => $cuentaId, 'alias' => $alias], 'Cuenta creada correctamente');
