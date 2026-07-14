<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Fila de Virtual Host para el tiempo real (sockets).
//
//  Reconsulta un virtual host con los MISMOS campos/join (nombre del dominio)
//  que la seccion "virtual_hosts" de endpoints/vps/ver.php, para que el evento
//  de socket viaje con la fila EXACTA que pinta la pestaña Virtual Hosts del
//  detalle y el navegador no tenga que reconsultar. Es UNA lectura por clave
//  primaria.
//
//  Como el Inventario Logico, el Virtual Host NO tiene modulo propio: solo
//  alimenta la vista de detalle del VPS (evento 'virtualhost_vps:creado').
//
//  Devuelve la fila (array) o null si el virtual host no existe.
// =====================================================================

function fila_virtualhost_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT vh.id, vh.vps_id, vh.aplicacion, vh.server_name, vh.servidor_web, vh.puerto,
                vh.ssl_habilitado, vh.estado, vh.document_root, vh.proxy_pass,
                vh.puerto_aplicacion, vh.dominio_id, d.nombre_dominio AS dominio
           FROM public.virtual_hosts vh
           LEFT JOIN public.dominios d ON d.id = vh.dominio_id
          WHERE vh.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
