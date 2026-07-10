<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Fila para el tiempo real (sockets). Compartido por
//  crear/actualizar.
//
//  Reconsulta un dominio con los MISMOS JOINs/campos que
//  endpoints/dominios/listar.php, para que el evento de socket viaje con la
//  fila EXACTA que pinta el listado (nombre de proveedor y de VPS ya resueltos,
//  clientes N:N, etc.) y el navegador no tenga que reconsultar la BD. Es UNA
//  lectura por clave primaria; sustituye a que cada navegador con el listado
//  abierto recargue el listar.php completo.
//
//  Devuelve la fila (array) o null si el dominio no existe.
// =====================================================================

function fila_dominio_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT d.id, d.nombre_dominio, d.fecha_registro, d.fecha_vencimiento,
                d.precio_compra, d.precio_venta, d.created_at,
                d.proveedor_id, pr.nombre_proveedor AS proveedor,
                d.vps_id, v.referencia_vps AS vps, d.vps_externa,
                COALESCE(
                    json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                             ORDER BY c.nombre_razon_social)
                        FILTER (WHERE c.id IS NOT NULL),
                    '[]'
                ) AS clientes
           FROM public.dominios d
           JOIN public.proveedores pr ON pr.id = d.proveedor_id
           LEFT JOIN public.vps_servidores v ON v.id = d.vps_id
           LEFT JOIN public.dominio_clientes dc ON dc.dominio_id = d.id
           LEFT JOIN public.clientes c ON c.id = dc.cliente_id
          WHERE d.id = :id
          GROUP BY d.id, pr.nombre_proveedor, v.referencia_vps"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    $fila['clientes'] = json_decode($fila['clientes'] ?? '[]', true) ?: [];
    return $fila;
}
