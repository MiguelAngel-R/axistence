<?php
declare(strict_types=1);

// =====================================================================
//  Log de auditoria (Modulo 12).
//  Registra automaticamente los eventos relevantes del sistema.
//  $tipoAccion debe ser uno del enum tipo_accion_auditoria:
//    CREAR | MODIFICAR | ELIMINAR | AUTENTICACION | DESCARGA_SEGURA | CAMBIO_ESTADO
//  Un fallo al auditar nunca debe romper el flujo principal.
// =====================================================================

function registrar_auditoria(
    string $tipoAccion,
    string $modulo,
    string $descripcion,
    ?string $registroId = null,
    ?array $datosAnteriores = null,
    ?array $datosNuevos = null
): void {
    try {
        $pdo = Database::get();
        $u   = usuario_actual();

        $sql = 'INSERT INTO public.logs_auditoria
                    (usuario_id, tipo_accion, modulo_afectado, registro_id, descripcion,
                     datos_anteriores, datos_nuevos, direccion_ip, user_agent)
                VALUES
                    (:usuario_id, :tipo_accion, :modulo, :registro_id, :descripcion,
                     :datos_ant, :datos_nue, :ip, :ua)';

        $pdo->prepare($sql)->execute([
            ':usuario_id'  => $u['id'] ?? null,
            ':tipo_accion' => $tipoAccion,
            ':modulo'      => $modulo,
            ':registro_id' => $registroId,
            ':descripcion' => $descripcion,
            ':datos_ant'   => $datosAnteriores !== null ? json_encode($datosAnteriores, JSON_UNESCAPED_UNICODE) : null,
            ':datos_nue'   => $datosNuevos !== null ? json_encode($datosNuevos, JSON_UNESCAPED_UNICODE) : null,
            ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        // No interrumpir la operacion principal por un fallo de auditoria.
        error_log('[AXISTENCE] Fallo al registrar auditoria: ' . $e->getMessage());
    }
}
