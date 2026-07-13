<?php
declare(strict_types=1);

// =====================================================================
//  Proyectos - Fila para el tiempo real (sockets). Compartido por
//  crear/actualizar.
//
//  Reconsulta un proyecto con los MISMOS JOINs/campos que
//  endpoints/proyectos/listar.php, para que el evento de socket viaje con la
//  fila EXACTA que pinta el listado (cliente y recursos N:N ya resueltos:
//  equipo/dominios/vps/hosting) y el navegador no tenga que reconsultar la BD.
//  Es UNA lectura por clave primaria; sustituye a que cada navegador con el
//  listado abierto recargue el listar.php completo.
//
//  Devuelve la fila (array, con los N:N ya decodificados) o null si no existe.
// =====================================================================

function fila_proyecto_socket(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.nombre_proyecto, p.descripcion, p.estado,
                p.fecha_inicio, p.fecha_entrega_estimada, p.created_at,
                p.cliente_id, c.nombre_razon_social AS cliente,
                COALESCE((
                    SELECT json_agg(json_build_object('id', u.id, 'nombre', u.nombre_completo)
                                    ORDER BY u.nombre_completo)
                      FROM public.proyecto_equipo pe
                      JOIN public.usuarios_internos u ON u.id = pe.usuario_id
                     WHERE pe.proyecto_id = p.id
                ), '[]') AS equipo,
                COALESCE((
                    SELECT json_agg(json_build_object('id', d.id, 'nombre', d.nombre_dominio)
                                    ORDER BY d.nombre_dominio)
                      FROM public.proyecto_dominios pd
                      JOIN public.dominios d ON d.id = pd.dominio_id
                     WHERE pd.proyecto_id = p.id
                ), '[]') AS dominios,
                COALESCE((
                    SELECT json_agg(json_build_object('id', v.id, 'nombre', v.referencia_vps)
                                    ORDER BY v.referencia_vps)
                      FROM public.proyecto_vps pv
                      JOIN public.vps_servidores v ON v.id = pv.vps_id
                     WHERE pv.proyecto_id = p.id
                ), '[]') AS vps,
                COALESCE((
                    SELECT json_agg(json_build_object('id', h.id, 'nombre', v.referencia_vps)
                                    ORDER BY v.referencia_vps)
                      FROM public.proyecto_hostings ph
                      JOIN public.hosting h ON h.id = ph.hosting_id
                      JOIN public.vps_servidores v ON v.id = h.vps_id
                     WHERE ph.proyecto_id = p.id
                ), '[]') AS hosting
           FROM public.proyectos p
           LEFT JOIN public.clientes c ON c.id = p.cliente_id
          WHERE p.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return null;
    }
    foreach (['equipo', 'dominios', 'vps', 'hosting'] as $rel) {
        $fila[$rel] = json_decode($fila[$rel] ?? '[]', true) ?: [];
    }
    return $fila;
}
