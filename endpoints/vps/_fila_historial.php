<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Fila del Historial (log de auditoria) para el tiempo real.
//
//  Reconsulta una entrada del log con los MISMOS campos/join (usuario) que la
//  seccion "logs" de endpoints/vps/ver.php, para que el evento de socket viaje
//  con la fila EXACTA que pinta la pestaña Historial del detalle. Es UNA lectura
//  por clave primaria del log recien insertado.
//
//  El Historial NO tiene modulo propio: es el log de auditoria filtrado por
//  registro_id = id del VPS. Se expone ese registro_id como 'vps_id' para que el
//  navegador decida si el evento corresponde al detalle que tiene abierto.
//
//  Devuelve la fila (array) o null si el log no existe.
// =====================================================================

function fila_historial_socket(PDO $pdo, string $logId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.registro_id AS vps_id, l.tipo_accion, l.modulo_afectado,
                l.descripcion, l.fecha_evento,
                u.nombre_completo AS usuario
           FROM public.logs_auditoria l
           LEFT JOIN public.usuarios_internos u ON u.id = l.usuario_id
          WHERE l.id = :id'
    );
    $stmt->execute([':id' => $logId]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
