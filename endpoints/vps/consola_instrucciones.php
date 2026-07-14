<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Catalogo de "Instrucciones rapidas" (frontend, sesion).
//
//  Catalogo GLOBAL de comandos predefinidos (tabla vps_consola_instrucciones)
//  que el operador puede lanzar en la terminal SSH desde el modal. El catalogo
//  no depende del VPS: mismos comandos para todos. CRUD completo:
//
//    GET     -> lista agrupada por categoria (permiso VPS.ver)
//               { data: { grupos: [ { categoria, items: [
//                   { id, titulo, descripcion, comando } ] } ] } }
//    POST    -> crea (sin id, permiso VPS.crear) o edita (con id, VPS.editar)
//               Campos: id?, categoria, titulo, descripcion?, comando
//    DELETE  -> elimina una instruccion (permiso VPS.eliminar). id en el body.
//
//  El comando se ejecuta luego en el shell enviandolo como input; el registro
//  en el historial de la sesion lo hace el server Node.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = Database::get();

// ---------------------------------------------------------------------
//  GET: listar el catalogo agrupado por categoria
// ---------------------------------------------------------------------
if ($metodo === 'GET') {
    requiere_permiso('VPS', 'ver');

    $stmt = $pdo->query(
        'SELECT id, categoria, titulo, descripcion, comando
           FROM public.vps_consola_instrucciones
          WHERE activo = true
          ORDER BY categoria ASC, orden ASC, titulo ASC'
    );

    // Agrupar por categoria preservando el orden de aparicion.
    $grupos = [];
    $indice = [];
    foreach ($stmt->fetchAll() as $fila) {
        $cat = $fila['categoria'];
        if (!isset($indice[$cat])) {
            $indice[$cat] = count($grupos);
            $grupos[] = ['categoria' => $cat, 'items' => []];
        }
        $grupos[$indice[$cat]]['items'][] = [
            'id'          => $fila['id'],
            'titulo'      => $fila['titulo'],
            'descripcion' => $fila['descripcion'],
            'comando'     => $fila['comando'],
        ];
    }

    json_ok(['grupos' => $grupos]);
}

// ---------------------------------------------------------------------
//  DELETE: eliminar una instruccion
// ---------------------------------------------------------------------
if ($metodo === 'DELETE') {
    requiere_permiso('VPS', 'eliminar');

    $in = body_json();
    $id = trim((string)($in['id'] ?? ''));
    if (!preg_match(UUID_RE, $id)) {
        json_error('id no valido', 422);
    }

    $stmt = $pdo->prepare('DELETE FROM public.vps_consola_instrucciones WHERE id = :id RETURNING titulo');
    $stmt->execute([':id' => $id]);
    $titulo = $stmt->fetchColumn();
    if ($titulo === false) {
        json_error('Instruccion no encontrada', 404);
    }

    registrar_auditoria('ELIMINAR', 'VPS', 'Eliminacion de instruccion de consola (' . $titulo . ')', $id);
    json_ok(['id' => $id], 'Instruccion eliminada');
}

// ---------------------------------------------------------------------
//  POST: crear (sin id) o editar (con id) una instruccion
// ---------------------------------------------------------------------
solo_metodo('POST');

$in        = body_json();
$id        = trim((string)($in['id'] ?? ''));
$esEdicion = $id !== '';

// El permiso depende de la operacion: crear vs editar.
requiere_permiso('VPS', $esEdicion ? 'editar' : 'crear');

$categoria   = trim((string)($in['categoria'] ?? ''));
$titulo      = trim((string)($in['titulo'] ?? ''));
$descripcion = trim((string)($in['descripcion'] ?? '')) ?: null;
$comando     = trim((string)($in['comando'] ?? ''));

if ($esEdicion && !preg_match(UUID_RE, $id)) {
    json_error('id no valido', 422);
}
$faltan = campos_faltantes(
    ['categoria' => $categoria, 'titulo' => $titulo, 'comando' => $comando],
    ['categoria', 'titulo', 'comando']
);
if ($faltan) {
    json_error('Faltan campos obligatorios', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($categoria) > 80) {
    json_error('La categoria no puede superar 80 caracteres', 422);
}
if (mb_strlen($titulo) > 120) {
    json_error('El titulo no puede superar 120 caracteres', 422);
}
if ($descripcion !== null && mb_strlen($descripcion) > 255) {
    json_error('La descripcion no puede superar 255 caracteres', 422);
}

if ($esEdicion) {
    $sql = 'UPDATE public.vps_consola_instrucciones
               SET categoria = :categoria, titulo = :titulo,
                   descripcion = :descripcion, comando = :comando
             WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':categoria'   => $categoria,
        ':titulo'      => $titulo,
        ':descripcion' => $descripcion,
        ':comando'     => $comando,
        ':id'          => $id,
    ]);
    if ($stmt->rowCount() === 0) {
        json_error('Instruccion no encontrada', 404);
    }
    registrar_auditoria('MODIFICAR', 'VPS', 'Actualizacion de instruccion de consola (' . $titulo . ')', $id);
    json_ok(['id' => $id], 'Instruccion actualizada');
}

// Alta: la nueva instruccion se agrega al final de su categoria.
$sql = 'INSERT INTO public.vps_consola_instrucciones (categoria, titulo, descripcion, comando, orden)
        VALUES (:categoria, :titulo, :descripcion, :comando,
                (SELECT COALESCE(MAX(orden), 0) + 1
                   FROM public.vps_consola_instrucciones WHERE categoria = :categoria2))
        RETURNING id';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':categoria'   => $categoria,
    ':titulo'      => $titulo,
    ':descripcion' => $descripcion,
    ':comando'     => $comando,
    ':categoria2'  => $categoria,
]);
$nuevoId = $stmt->fetchColumn();
registrar_auditoria('CREAR', 'VPS', 'Alta de instruccion de consola (' . $titulo . ')', (string)$nuevoId);
json_ok(['id' => $nuevoId], 'Instruccion creada');
