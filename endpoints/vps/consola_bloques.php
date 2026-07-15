<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Bloques de comandos (frontend, sesion).
//
//  Un "bloque" es una secuencia ORDENADA de comandos que el operador arma en
//  el modal "Instrucciones" arrastrando comandos del catalogo a la zona del
//  bloque. Cada comando se guarda como COPIA (titulo + comando): el bloque no
//  depende de que la instruccion original siga existiendo o cambie despues.
//
//    GET  -> lista los bloques con sus comandos (permiso VPS.ver)
//            { data: { bloques: [ { id, nombre, descripcion,
//                comandos: [ { titulo, comando } ] } ] } }
//    POST   -> crea (sin id, permiso VPS.crear) o edita (con id, VPS.editar) un
//              bloque. Campos: id?, nombre, descripcion?, comandos: [ { titulo?, comando } ]
//              { data: { id } }. Nombre duplicado -> 409. Al editar se REEMPLAZAN
//              todos los comandos del bloque por los recibidos (en su orden).
//    DELETE -> elimina un bloque (permiso VPS.eliminar). id en el body. Sus
//              comandos se borran en cascada (FK ON DELETE CASCADE).
//
//  Los bloques son GLOBALES (no dependen del VPS), igual que el catalogo. La
//  ejecucion de un bloque en la terminal es un paso posterior.
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = Database::get();

// ---------------------------------------------------------------------
//  GET: listar bloques activos con sus comandos (en orden)
// ---------------------------------------------------------------------
if ($metodo === 'GET') {
    requiere_permiso('VPS', 'ver');

    $stmt = $pdo->query(
        'SELECT b.id, b.nombre, b.descripcion, c.titulo, c.comando
           FROM public.vps_consola_bloques b
           LEFT JOIN public.vps_consola_bloque_comandos c ON c.bloque_id = b.id
          WHERE b.activo = true
          ORDER BY b.nombre ASC, c.orden ASC'
    );

    // Agrupar los comandos bajo su bloque preservando el orden.
    $bloques = [];
    $indice  = [];
    foreach ($stmt->fetchAll() as $fila) {
        $id = $fila['id'];
        if (!isset($indice[$id])) {
            $indice[$id] = count($bloques);
            $bloques[] = [
                'id'          => $id,
                'nombre'      => $fila['nombre'],
                'descripcion' => $fila['descripcion'],
                'comandos'    => [],
            ];
        }
        // El LEFT JOIN trae una fila con comando NULL si el bloque no tiene comandos.
        if ($fila['comando'] !== null) {
            $bloques[$indice[$id]]['comandos'][] = [
                'titulo'  => $fila['titulo'],
                'comando' => $fila['comando'],
            ];
        }
    }

    json_ok(['bloques' => $bloques]);
}

// ---------------------------------------------------------------------
//  DELETE: eliminar un bloque (sus comandos se borran en cascada)
// ---------------------------------------------------------------------
if ($metodo === 'DELETE') {
    requiere_permiso('VPS', 'eliminar');

    $in = body_json();
    $id = trim((string)($in['id'] ?? ''));
    if (!preg_match(UUID_RE, $id)) {
        json_error('id no valido', 422);
    }

    $stmt = $pdo->prepare('DELETE FROM public.vps_consola_bloques WHERE id = :id RETURNING nombre');
    $stmt->execute([':id' => $id]);
    $nombre = $stmt->fetchColumn();
    if ($nombre === false) {
        json_error('Bloque no encontrado', 404);
    }

    registrar_auditoria('ELIMINAR', 'VPS', 'Eliminacion de bloque de comandos de consola (' . $nombre . ')', $id);
    json_ok(['id' => $id], 'Bloque eliminado');
}

// ---------------------------------------------------------------------
//  POST: crear (sin id) o editar (con id) un bloque
// ---------------------------------------------------------------------
solo_metodo('POST');

$in          = body_json();
$id          = trim((string)($in['id'] ?? ''));
$esEdicion   = $id !== '';
$nombre      = trim((string)($in['nombre'] ?? ''));
$descripcion = trim((string)($in['descripcion'] ?? '')) ?: null;
$comandos    = $in['comandos'] ?? null;

// El permiso depende de la operacion: crear vs editar.
requiere_permiso('VPS', $esEdicion ? 'editar' : 'crear');

// --- Validacion ------------------------------------------------------
if ($esEdicion && !preg_match(UUID_RE, $id)) {
    json_error('id no valido', 422);
}
$faltan = campos_faltantes(['nombre' => $nombre], ['nombre']);
if ($faltan) {
    json_error('Ponle un nombre al bloque', 422, ['faltantes' => $faltan]);
}
if (mb_strlen($nombre) > 120) {
    json_error('El nombre no puede superar 120 caracteres', 422);
}
if ($descripcion !== null && mb_strlen($descripcion) > 255) {
    json_error('La descripcion no puede superar 255 caracteres', 422);
}
if (!is_array($comandos) || count($comandos) === 0) {
    json_error('El bloque debe tener al menos un comando', 422);
}

// Normalizar la lista de comandos preservando el orden recibido.
$items = [];
foreach ($comandos as $c) {
    $comando = trim((string)($c['comando'] ?? ''));
    if ($comando === '') {
        continue;   // ignora entradas vacias
    }
    $titulo = trim((string)($c['titulo'] ?? '')) ?: null;
    if ($titulo !== null && mb_strlen($titulo) > 120) {
        $titulo = mb_substr($titulo, 0, 120);
    }
    $items[] = ['titulo' => $titulo, 'comando' => $comando];
}
if (count($items) === 0) {
    json_error('El bloque debe tener al menos un comando', 422);
}

// --- Persistencia (cabecera + comandos en una transaccion) -----------
$pdo->beginTransaction();
try {
    if ($esEdicion) {
        // Cabecera: actualiza nombre/descripcion del bloque existente.
        $stmt = $pdo->prepare(
            'UPDATE public.vps_consola_bloques
                SET nombre = :nombre, descripcion = :descripcion
              WHERE id = :id AND activo = true'
        );
        $stmt->execute([':nombre' => $nombre, ':descripcion' => $descripcion, ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_error('Bloque no encontrado', 404);
        }
        $bloqueId = $id;
        // Comandos: se REEMPLAZAN por completo (mas simple y respeta el orden).
        $pdo->prepare('DELETE FROM public.vps_consola_bloque_comandos WHERE bloque_id = :id')
            ->execute([':id' => $bloqueId]);
    } else {
        // Alta: nueva cabecera.
        $stmt = $pdo->prepare(
            'INSERT INTO public.vps_consola_bloques (nombre, descripcion)
             VALUES (:nombre, :descripcion) RETURNING id'
        );
        $stmt->execute([':nombre' => $nombre, ':descripcion' => $descripcion]);
        $bloqueId = (string)$stmt->fetchColumn();
    }

    $stmtItem = $pdo->prepare(
        'INSERT INTO public.vps_consola_bloque_comandos (bloque_id, orden, titulo, comando)
         VALUES (:bloque_id, :orden, :titulo, :comando)'
    );
    foreach ($items as $orden => $it) {
        $stmtItem->execute([
            ':bloque_id' => $bloqueId,
            ':orden'     => $orden,
            ':titulo'    => $it['titulo'],
            ':comando'   => $it['comando'],
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    // 23505 = unique_violation -> nombre de bloque ya usado por OTRO bloque.
    if ($e->getCode() === '23505') {
        json_error('Ya existe un bloque con ese nombre', 409);
    }
    throw $e;   // el manejador de bootstrap lo convierte en 500 JSON
}

if ($esEdicion) {
    registrar_auditoria(
        'MODIFICAR', 'VPS',
        'Actualizacion de bloque de comandos de consola (' . $nombre . ', ' . count($items) . ' comando(s))',
        $bloqueId
    );
    json_ok(['id' => $bloqueId], 'Bloque actualizado');
}

registrar_auditoria(
    'CREAR', 'VPS',
    'Alta de bloque de comandos de consola (' . $nombre . ', ' . count($items) . ' comando(s))',
    $bloqueId
);
json_ok(['id' => $bloqueId], 'Bloque guardado');
