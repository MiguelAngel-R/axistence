<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Credenciales SSH (frontend, con sesion).
//
//  GET  ?vps_id=...        -> lista las credenciales del VPS SIN secretos
//                             (permiso VPS.ver). Para elegir con cual conectar.
//  POST { ... }            -> crea o actualiza una credencial (permiso VPS.editar),
//                             CIFRANDO el secreto y la passphrase. Nunca devuelve
//                             el secreto.
//
//  Campos POST: id? (para editar), vps_id, etiqueta?, host, puerto?, usuario,
//               tipo_auth ('password'|'clave_privada'), secreto, passphrase?,
//               clave_maestra (palabra maestra para cifrar; no se persiste)
//  En edicion, si `secreto` viene vacio se conserva el guardado.
//
//  El secreto/passphrase se cifran con la DEK que abre la clave_maestra
//  (esquema g2). La palabra maestra nunca se guarda.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_cifrado.php';
require_once __DIR__ . '/../helpers/consola_llave_maestra.php';

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = Database::get();

// ---------------------------------------------------------------------
//  GET: listar credenciales (metadatos, sin secretos)
// ---------------------------------------------------------------------
if ($metodo === 'GET') {
    requiere_permiso('VPS', 'ver');

    $vpsId = trim((string)($_GET['vps_id'] ?? ''));
    if (!preg_match(UUID_RE, $vpsId)) {
        json_error('vps_id no valido', 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, etiqueta, host, puerto, usuario, tipo_auth, created_at, updated_at
           FROM public.vps_credenciales_ssh
          WHERE vps_id = :vps_id
          ORDER BY created_at ASC'
    );
    $stmt->execute([':vps_id' => $vpsId]);
    json_ok($stmt->fetchAll());
}

// ---------------------------------------------------------------------
//  POST: crear o actualizar credencial (cifrando el secreto)
// ---------------------------------------------------------------------
solo_metodo('POST');
requiere_permiso('VPS', 'editar');

$in         = body_json();
$id         = trim((string)($in['id'] ?? ''));
$esEdicion  = $id !== '';
$vpsId      = trim((string)($in['vps_id'] ?? ''));
$etiqueta   = trim((string)($in['etiqueta'] ?? '')) ?: null;
$host       = trim((string)($in['host'] ?? ''));
$puerto     = (int)($in['puerto'] ?? 22);
$usuario    = trim((string)($in['usuario'] ?? ''));
$tipoAuth   = trim((string)($in['tipo_auth'] ?? ''));
$secreto    = (string)($in['secreto'] ?? '');
$passphrase = (string)($in['passphrase'] ?? '');
$claveMaestra = (string)($in['clave_maestra'] ?? '');

if ($esEdicion && !preg_match(UUID_RE, $id)) {
    json_error('id no valido', 422);
}
if (!preg_match(UUID_RE, $vpsId)) {
    json_error('vps_id no valido', 422);
}
$faltan = campos_faltantes(
    ['host' => $host, 'usuario' => $usuario, 'tipo_auth' => $tipoAuth],
    ['host', 'usuario', 'tipo_auth']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (!in_array($tipoAuth, ['password', 'clave_privada'], true)) {
    json_error('tipo_auth invalido', 422);
}
if ($puerto < 1 || $puerto > 65535) {
    json_error('puerto invalido', 422);
}
// Al crear, el secreto es obligatorio; al editar puede omitirse para conservarlo.
if (!$esEdicion && $secreto === '') {
    json_error('El secreto (password o clave privada) es obligatorio', 422);
}

// El VPS debe existir.
$chk = $pdo->prepare('SELECT 1 FROM public.vps_servidores WHERE id = :id');
$chk->execute([':id' => $vpsId]);
if (!$chk->fetchColumn()) {
    json_error('VPS no encontrado', 404);
}

// Abrir la DEK con la palabra maestra. Solo se necesita si hay algo que cifrar.
$hayQueCifrar = $secreto !== '' || $passphrase !== '';
$secretoCifrado    = null;
$passphraseCifrada = null;
if ($hayQueCifrar) {
    if ($claveMaestra === '') {
        json_error('Falta la palabra maestra para cifrar la credencial', 422);
    }
    $km = consola_km_abrir($pdo, $claveMaestra);
    if ($km['estado'] === 'sin_configurar') {
        json_error('Configura la llave maestra antes de guardar credenciales', 409);
    }
    if ($km['estado'] === 'clave_incorrecta') {
        json_error('Palabra maestra incorrecta', 401);
    }
    $dek = $km['dek'];
    $secretoCifrado    = $secreto !== '' ? consola_gcm_cifrar($secreto, $dek) : null;
    $passphraseCifrada = $passphrase !== '' ? consola_gcm_cifrar($passphrase, $dek) : null;
    $dek = null; // se suelta la DEK en cuanto se termina de cifrar
}

try {
    if ($esEdicion) {
        // Conservar el secreto/passphrase si no se envian nuevos.
        $sql = 'UPDATE public.vps_credenciales_ssh
                   SET etiqueta = :etiqueta, host = :host, puerto = :puerto,
                       usuario = :usuario, tipo_auth = :tipo_auth,
                       secreto_cifrado = COALESCE(:secreto, secreto_cifrado),
                       passphrase_cifrada = CASE WHEN :tiene_pass THEN :passphrase ELSE passphrase_cifrada END
                 WHERE id = :id AND vps_id = :vps_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':etiqueta'   => $etiqueta,
            ':host'       => $host,
            ':puerto'     => $puerto,
            ':usuario'    => $usuario,
            ':tipo_auth'  => $tipoAuth,
            ':secreto'    => $secretoCifrado,
            ':tiene_pass' => $passphrase !== '',
            ':passphrase' => $passphraseCifrada,
            ':id'         => $id,
            ':vps_id'     => $vpsId,
        ]);
        if ($stmt->rowCount() === 0) {
            json_error('Credencial no encontrada', 404);
        }
        registrar_auditoria('MODIFICAR', 'VPS', 'Actualizacion de credencial SSH (' . $usuario . '@' . $host . ')', $vpsId);
        json_ok(['id' => $id], 'Credencial actualizada');
    }

    $sql = 'INSERT INTO public.vps_credenciales_ssh
                (vps_id, etiqueta, host, puerto, usuario, tipo_auth, secreto_cifrado, passphrase_cifrada)
            VALUES
                (:vps_id, :etiqueta, :host, :puerto, :usuario, :tipo_auth, :secreto, :passphrase)
            RETURNING id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':vps_id'     => $vpsId,
        ':etiqueta'   => $etiqueta,
        ':host'       => $host,
        ':puerto'     => $puerto,
        ':usuario'    => $usuario,
        ':tipo_auth'  => $tipoAuth,
        ':secreto'    => $secretoCifrado,
        ':passphrase' => $passphraseCifrada,
    ]);
    $nuevoId = $stmt->fetchColumn();
    registrar_auditoria('CREAR', 'VPS', 'Alta de credencial SSH (' . $usuario . '@' . $host . ')', $vpsId);
    json_ok(['id' => $nuevoId], 'Credencial creada');
} catch (PDOException $e) {
    // UNIQUE (vps_id, host, usuario)
    if ($e->getCode() === '23505') {
        json_error('Ya existe una credencial con ese host y usuario en este VPS', 409);
    }
    throw $e;
}
