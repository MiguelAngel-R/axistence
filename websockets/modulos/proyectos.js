// =====================================================================
//  AXISTENCE - Sockets del modulo Proyectos.
//
//  Reemite a la sala "proyectos" los cambios que publica PHP, para que el
//  listado se actualice en vivo (insertar / actualizar / quitar la fila) sin
//  recargar ni volver a consultar la base de datos. El evento ya viaja con
//  los datos de la fila (cliente y recursos N:N ya resueltos), asi que el
//  navegador no tiene que ir a la BD.
//
//  Lo carga y registra index2.js. Cada modulo tiene su propio script con
//  este mismo contrato: { nombre, eventos, conexion(io, socket),
//  emitir(io, evento, data) }. Espejo de ./correo.js.
// =====================================================================

const NOMBRE = 'proyectos';

// Eventos permitidos (whitelist). PHP no puede disparar ninguno fuera de esta
// lista: si lo intenta, emitir() devuelve false e index2.js lo rechaza.
//   proyecto:* -> actualizan el LISTADO (tabla de proyectos).
//   tarea:*    -> actualizan el DETALLE (viewProyecto, tablero Kanban). El
//                 evento viaja con proyecto_id y cada navegador decide si le
//                 corresponde (si tiene abierto el detalle de ese proyecto).
const EVENTOS = [
    'proyecto:creado', 'proyecto:actualizado', 'proyecto:eliminado',
    'tarea:creada', 'tarea:movida', 'tarea:completada', 'tarea:archivada', 'comentario:creado',
    'columnas:reordenadas', 'columna:creada', 'columna:eliminada', 'columna:renombrada',
];

// Un navegador que abre el listado se une a la sala del modulo.
function conexion(io, socket) {
    socket.join(NOMBRE);
    console.log(`[sockets:${NOMBRE}] socket ${socket.id} unido a la sala`);
}

// PHP -> Node -> navegadores. Reemite el evento a todos los que tienen abierto
// el listado. Devuelve false si el evento no esta permitido.
function emitir(io, evento, data) {
    if (!EVENTOS.includes(evento)) {
        return false;
    }
    io.to(NOMBRE).emit(evento, data);
    console.log(`[sockets:${NOMBRE}] emitido ${evento}`);
    return true;
}

export default { nombre: NOMBRE, eventos: EVENTOS, conexion, emitir };
