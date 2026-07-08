<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Resolucion del registro MX (compartido por crear/actualizar).
//  Verifica que el MX elegido exista, sea de tipo MX y pertenezca al dominio
//  seleccionado. Devuelve ['id' => ..., 'valor' => ...] (el destino del MX,
//  p.ej. mx.zoho.com) o null si no es valido.
// =====================================================================

function resolver_mx_correo(PDO $pdo, string $dominioId, string $mxId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, valor
           FROM public.dominio_registros_dns
          WHERE id = :mx AND dominio_id = :d AND tipo_registro = 'MX'"
    );
    $stmt->execute([':mx' => $mxId, ':d' => $dominioId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
