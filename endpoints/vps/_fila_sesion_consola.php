<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Fila de sesion para el tiempo real (sockets).
//
//  Reconsulta UNA sesion de consola con la MISMA forma que la lista de sesiones
//  de endpoints/vps/consola_historial.php (usuario resuelto, nº de comandos),
//  para que el evento de socket viaje con la fila EXACTA que pinta el panel de
//  Historial de la consola. Es UNA lectura por clave primaria.
//
//  Sirve a los eventos 'sesion_vps:abierta' (al abrir la sesion) y
//  'sesion_vps:cerrada' (al cerrarla), para que quien tenga abierto el tab
//  Consola de ESE VPS vea aparecer/cerrarse la sesion en vivo (punto verde y
//  quien esta conectado). Incluye vps_id para que el navegador filtre.
//
//  Devuelve la fila (array) o null si la sesion no existe.
// =====================================================================

function fila_sesion_consola(PDO $pdo, string $sesionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.vps_id, s.estado, s.direccion_ip, s.inicio, s.fin,
                u.nombre_completo AS usuario,
                (SELECT COUNT(*) FROM public.vps_consola_comandos c WHERE c.sesion_id = s.id) AS total_comandos
           FROM public.vps_consola_sesiones s
           LEFT JOIN public.usuarios_internos u ON u.id = s.usuario_id
          WHERE s.id = :id'
    );
    $stmt->execute([':id' => $sesionId]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
