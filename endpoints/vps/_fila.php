<?php
declare(strict_types=1);

// =====================================================================
//  VPS - Reconstruccion de UNA fila del listado (compartido).
//  Devuelve el VPS con la MISMA forma que produce listar.php (hardware,
//  precios y tipo heredados de la referencia + clientes N:N como arreglo),
//  para un solo id. Se usa tras crear/actualizar y emitir por Socket.IO el
//  evento con la fila ya armada, de modo que el navegador pinte/actualice la
//  tabla en vivo sin volver a consultar la BD.
//
//  IMPORTANTE: el SELECT debe mantenerse alineado con el de listar.php (mismos
//  campos y joins); si alli cambia la forma de la fila, actualizar aqui tambien.
// =====================================================================

function vps_fila_por_id(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT v.id, v.referencia_vps, v.referencia_vps_id, v.label,
                v.fecha_creacion, v.fecha_vencimiento, v.created_at,
                v.proveedor_id, pr.nombre_proveedor AS proveedor,
                r.tipo_servidor,
                r.disco_valor, r.disco_unidad, r.ram_valor, r.ram_unidad,
                r.ancho_banda_valor, r.ancho_banda_unidad,
                r.precio_compra, r.moneda_compra, r.precio_venta, r.moneda_venta,
                COALESCE(
                    json_agg(json_build_object('id', c.id, 'nombre', c.nombre_razon_social)
                             ORDER BY c.nombre_razon_social)
                        FILTER (WHERE c.id IS NOT NULL),
                    '[]'
                ) AS clientes
           FROM public.vps_servidores v
           JOIN public.proveedores pr ON pr.id = v.proveedor_id
           JOIN public.referencias_vps r ON r.id = v.referencia_vps_id
           LEFT JOIN public.vps_clientes vc ON vc.vps_id = v.id
           LEFT JOIN public.clientes c ON c.id = vc.cliente_id
          WHERE v.id = :id
          GROUP BY v.id, pr.nombre_proveedor, r.id"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return null;
    }
    // json_agg llega como cadena JSON; se decodifica a arreglo real (igual que listar.php).
    $fila['clientes'] = json_decode($fila['clientes'] ?? '[]', true) ?: [];
    return $fila;
}
