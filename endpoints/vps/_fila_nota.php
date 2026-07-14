<?php
declare(strict_types=1);

// =====================================================================
//  VPS (detalle) - Fila de Nota para el tiempo real (sockets).
//
//  Reconsulta una nota con los MISMOS campos/join (autor) que la seccion
//  "notas" de endpoints/vps/ver.php, para que el evento de socket viaje con la
//  fila EXACTA que pinta la pestaña Notas del detalle (autor resuelto, fecha por
//  defecto de la BD) y el navegador no tenga que reconsultar. Es UNA lectura por
//  clave primaria.
//
//  Como el Inventario/Virtual Hosts, la Nota NO tiene modulo propio: solo
//  alimenta la vista de detalle del VPS (evento 'nota_vps:creado').
//
//  Devuelve la fila (array) o null si la nota no existe.
// =====================================================================

function fila_nota_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT n.id, n.vps_id, n.nota, n.criticidad, n.fecha,
                u.nombre_completo AS autor
           FROM public.vps_notas n
           LEFT JOIN public.usuarios_internos u ON u.id = n.autor_id
          WHERE n.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila !== false ? $fila : null;
}
