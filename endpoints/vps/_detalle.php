<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Utilidades compartidas por los endpoints "agregar_*" del detalle.
//  Todos crean un activo y lo vinculan al VPS actual, y registran su
//  evento en el log con registro_id = id del VPS, para que sea visible de
//  inmediato en el tab "Historial" del detalle (ver.php filtra por ese id).
// =====================================================================

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
function auditar_en_vps(string $vpsId, string $descripcion, array $datos): void
{
    registrar_auditoria('CREAR', 'VPS', $descripcion, $vpsId, null, $datos);
}
