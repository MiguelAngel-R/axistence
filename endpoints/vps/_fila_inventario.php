<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Fila de Inventario Logico para el tiempo real (sockets).
//
//  Reconsulta un registro del inventario logico con los MISMOS campos/join
//  (responsable) que la seccion "historial" de endpoints/vps/ver.php, para que
//  el evento de socket viaje con la fila EXACTA que pinta la pestaña Inventario
//  del detalle (nombre del responsable ya resuelto, fecha por defecto de la BD)
//  y el navegador no tenga que reconsultar. Es UNA lectura por clave primaria.
//
//  A diferencia de dominios/ssl, el inventario logico NO tiene modulo propio:
//  solo alimenta la vista de detalle del VPS (evento 'inventario_vps:creado').
//
//  Devuelve la fila (array) o null si el registro no existe.
// =====================================================================

function fila_inventario_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT h.id, h.vps_id, h.tipo_evento, h.software_componente, h.version,
                h.ruta_directorio_instalacion, h.puertos_usados,
                h.servicios_rutas_acceso, h.fecha, h.notas,
                u.nombre_completo AS responsable
           FROM public.vps_historial_configuracion h
           LEFT JOIN public.usuarios_internos u ON u.id = h.responsable_id
          WHERE h.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
