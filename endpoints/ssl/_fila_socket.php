<?php
declare(strict_types=1);

// =====================================================================
//  Certificados SSL - Fila para el tiempo real (sockets). Compartido por
//  crear/actualizar.
//
//  Reconsulta un certificado con los MISMOS JOINs/campos que
//  endpoints/ssl/listar.php, para que el evento de socket viaje con la fila
//  EXACTA que pinta el listado (nombre de dominio/proveedor/VPS ya resueltos) y
//  el navegador no tenga que reconsultar la BD. Es UNA lectura por clave
//  primaria; sustituye a que cada navegador con el listado abierto recargue el
//  listar.php completo.
//
//  Devuelve la fila (array) o null si el certificado no existe.
// =====================================================================

function fila_ssl_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT s.id, s.ruta_almacenamiento, s.archivo_paquete_path,
                s.fecha_registro, s.fecha_vencimiento, s.precio_compra, s.precio_venta,
                s.created_at,
                s.dominio_id, d.nombre_dominio AS dominio,
                s.proveedor_id, pr.nombre_proveedor AS proveedor,
                s.vps_id, v.referencia_vps AS vps, s.vps_externa
           FROM public.certificados_ssl s
           JOIN public.dominios d ON d.id = s.dominio_id
           JOIN public.proveedores pr ON pr.id = s.proveedor_id
           LEFT JOIN public.vps_servidores v ON v.id = s.vps_id
          WHERE s.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
