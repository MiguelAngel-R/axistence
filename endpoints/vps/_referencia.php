<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Resolucion de referencias (compartido por crear/actualizar).
//  Devuelve el id de una referencia del catalogo (referencias_vps) dado
//  el proveedor y el nombre. Garantiza la integridad: la referencia debe
//  existir para ese proveedor (se crea desde el modal anidado con "+").
//  Devuelve el uuid, o null si no existe.
// =====================================================================

function resolver_referencia_vps(PDO $pdo, string $proveedorId, string $nombre): ?string
{
    $stmt = $pdo->prepare(
        'SELECT id FROM public.referencias_vps
          WHERE proveedor_id = :p AND nombre = :n'
    );
    $stmt->execute([':p' => $proveedorId, ':n' => $nombre]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string)$id;
}

// ---------------------------------------------------------------------
//  Regla de negocio del VPS: vencimiento = fecha_creacion + 1 mes - 1 dia.
//  (Ej: 2026-03-15 -> 2026-04-14). El backend es la fuente autoritativa; el
//  frontend solo muestra una vista previa (AX.calcularVencimiento en general.js
//  usa exactamente el mismo calculo). Recibe una fecha 'YYYY-MM-DD' valida y
//  devuelve la fecha de vencimiento en el mismo formato.
// ---------------------------------------------------------------------
function calcular_vencimiento_vps(string $fechaCreacion): string
{
    $fecha = DateTime::createFromFormat('Y-m-d', $fechaCreacion);
    // Se anula la hora para evitar desfases al modificar la fecha.
    $fecha->setTime(0, 0, 0);
    $fecha->modify('+1 month');
    $fecha->modify('-1 day');
    return $fecha->format('Y-m-d');
}
