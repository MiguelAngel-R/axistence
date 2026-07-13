<?php
declare(strict_types=1);

// =====================================================================
//  Cuentas de correo - Fila para el tiempo real (sockets). Compartido por
//  crear/actualizar.
//
//  Reconsulta una relacion de correo con los MISMOS JOINs/campos que
//  endpoints/correo/listar.php, para que el evento de socket viaje con la fila
//  EXACTA que pinta el listado (nombre de dominio/cliente/servidor ya resueltos)
//  y el navegador no tenga que reconsultar la BD. Es UNA lectura por clave
//  primaria; sustituye a que cada navegador con el listado abierto recargue el
//  listar.php completo.
//
//  Devuelve la fila (array) o null si la relacion no existe.
// =====================================================================

function fila_correo_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT cc.id, cc.cantidad_cuentas, cc.tipo_licencia,
                cc.precio_costo_cuenta, cc.precio_venta_cuenta,
                cc.fecha_registro, cc.fecha_vencimiento, cc.created_at,
                cc.dominio_id, d.nombre_dominio AS dominio,
                cc.cliente_id, c.nombre_razon_social AS cliente,
                cc.servidor_correo_vps_id, v.referencia_vps AS servidor_vps,
                cc.mx_registro_id, cc.servidor_correo_externo
           FROM public.cuentas_correo cc
           JOIN public.dominios d ON d.id = cc.dominio_id
           JOIN public.clientes c ON c.id = cc.cliente_id
           LEFT JOIN public.vps_servidores v ON v.id = cc.servidor_correo_vps_id
          WHERE cc.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
