<?php
declare(strict_types=1);

// =====================================================================
//  Notificaciones in-app (helper de creacion).
//
//  notificar(...) inserta UNA notificacion por cada usuario destinatario
//  (modelo fan-out: una fila por usuario, cada quien consume la suya). Sigue
//  el mismo espiritu que registrar_auditoria: un fallo aqui JAMAS debe romper
//  la operacion principal (se registra en el log y se sigue). Conviene
//  llamarlo DESPUES de confirmar el cambio principal (fuera de su transaccion).
//
//  Persiste en BD (fuente de verdad: el usuario desconectado las lee al entrar)
//  y ADEMAS empuja en tiempo real por Socket.IO a la sala del destinatario
//  ('usuario:<id>', evento 'notificacion:nueva') para que le aparezca al vuelo.
//  Devuelve los ids insertados (o [] si no se inserto nada).
// =====================================================================

const NOTIF_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/**
 * Crea una notificacion para uno o varios destinatarios.
 *
 * @param string[] $usuarioIds  ids de los usuarios que la reciben (se filtran
 *                              los invalidos y se eliminan duplicados).
 * @param string   $modulo      vocabulario fijo (Proyectos, Kanban, ...).
 * @param string   $tipo        clase de evento (tarea_asignada, comentario_nuevo, ...).
 * @param string   $titulo      rotulo corto.
 * @param ?string  $mensaje     detalle opcional.
 * @param ?string  $entidadTipo tipo de entidad referida (para deep-link).
 * @param ?string  $entidadId   id de la entidad referida.
 * @param ?array   $datos       payload extra opcional (se guarda como jsonb).
 * @return string[]             ids de las notificaciones creadas.
 */
function notificar(
    array $usuarioIds,
    string $modulo,
    string $tipo,
    string $titulo,
    ?string $mensaje = null,
    ?string $entidadTipo = null,
    ?string $entidadId = null,
    ?array $datos = null
): array {
    try {
        // Destinatarios validos y sin repetir.
        $destinatarios = [];
        foreach ($usuarioIds as $uid) {
            $uid = trim((string)$uid);
            if ($uid !== '' && preg_match(NOTIF_UUID_RE, $uid)) {
                $destinatarios[$uid] = true;   // clave = dedupe
            }
        }
        if (!$destinatarios) {
            return [];
        }

        $titulo = trim($titulo);
        if ($titulo === '') {
            return [];   // sin titulo no tiene sentido notificar
        }
        $mensaje     = ($mensaje !== null && trim($mensaje) !== '') ? trim($mensaje) : null;
        $entidadTipo = ($entidadTipo !== null && trim($entidadTipo) !== '') ? trim($entidadTipo) : null;
        $entidadId   = ($entidadId !== null && preg_match(NOTIF_UUID_RE, trim($entidadId))) ? trim($entidadId) : null;
        $datosJson   = $datos !== null ? json_encode($datos, JSON_UNESCAPED_UNICODE) : null;

        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO public.notificaciones
                 (usuario_id, modulo, tipo, titulo, mensaje, entidad_tipo, entidad_id, datos)
             VALUES
                 (:usuario_id, :modulo, :tipo, :titulo, :mensaje, :entidad_tipo, :entidad_id, :datos)
             RETURNING id, created_at'
        );

        $ids = [];
        foreach (array_keys($destinatarios) as $uid) {
            $stmt->execute([
                ':usuario_id'   => $uid,
                ':modulo'       => $modulo,
                ':tipo'         => $tipo,
                ':titulo'       => $titulo,
                ':mensaje'      => $mensaje,
                ':entidad_tipo' => $entidadTipo,
                ':entidad_id'   => $entidadId,
                ':datos'        => $datosJson,
            ]);
            $fila = $stmt->fetch();
            if ($fila === false) {
                continue;
            }
            $ids[] = (string)$fila['id'];

            // Empuje en tiempo real (Fase 6): se avisa a la sala del destinatario
            // ('usuario:<id>') con la notificacion ya con la MISMA forma que
            // devuelve listar.php, para que el navegador la pinte igual que en la
            // carga por GET. Es fire-and-forget (notificar_socket nunca rompe).
            notificar_socket('notificaciones', 'notificacion:nueva', [
                'usuario_id' => $uid,
                'payload'    => [
                    'id'           => (string)$fila['id'],
                    'modulo'       => $modulo,
                    'tipo'         => $tipo,
                    'titulo'       => $titulo,
                    'mensaje'      => $mensaje,
                    'entidad_tipo' => $entidadTipo,
                    'entidad_id'   => $entidadId,
                    'datos'        => $datos,   // ya como arreglo (se guardo jsonb)
                    'leida'        => false,
                    'created_at'   => $fila['created_at'],
                ],
            ]);
        }

        return $ids;
    } catch (Throwable $e) {
        // No interrumpir la operacion principal por un fallo de notificacion.
        error_log('[AXISTENCE] Fallo al crear notificacion: ' . $e->getMessage());
        return [];
    }
}
