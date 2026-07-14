<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Utilidades compartidas por los endpoints "agregar_*" del detalle.
//  Todos crean un activo y lo vinculan al VPS actual, y registran su
//  evento en el log con registro_id = id del VPS, para que sea visible de
//  inmediato en el tab "Historial" del detalle (ver.php filtra por ese id).
// =====================================================================

require_once __DIR__ . '/_fila_historial.php';

const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

// Comprueba que el VPS exista y devuelve su referencia (para descripciones
// de auditoria). Corta con error JSON si el id es invalido o no existe.
function exigir_vps(PDO $pdo, string $vpsId): string
{
    if (!preg_match(UUID_RE, $vpsId)) {
        json_error('Identificador de VPS no valido', 422);
    }
    $stmt = $pdo->prepare('SELECT referencia_vps FROM public.vps_servidores WHERE id = :id');
    $stmt->execute([':id' => $vpsId]);
    $ref = $stmt->fetchColumn();
    if ($ref === false) {
        json_error('VPS no encontrado', 404);
    }
    return (string)$ref;
}

// Registra en el log un evento asociado a ESTE VPS (registro_id = vpsId), de
// modo que aparezca en el tab Historial del detalle. $datos = valores nuevos.
//
// Ademas reparte el log en tiempo real a la sala 'vps' (evento
// 'historial_vps:creado'): como TODOS los "agregar_*" pasan por aqui, esta es la
// via unica para que el tab Historial se actualice en vivo sin tocar cada
// endpoint. Fire-and-forget: un fallo aqui jamas rompe la operacion (igual que
// la propia auditoria).
function auditar_en_vps(string $vpsId, string $descripcion, array $datos): void
{
    $logId = registrar_auditoria('CREAR', 'VPS', $descripcion, $vpsId, null, $datos);
    if ($logId === null) {
        return;
    }
    try {
        $fila = fila_historial_socket(Database::get(), $logId);
        if ($fila) {
            notificar_socket('vps', 'historial_vps:creado', $fila);
        }
    } catch (Throwable $e) {
        error_log('[AXISTENCE] Fallo al reemitir historial VPS: ' . $e->getMessage());
    }
}
