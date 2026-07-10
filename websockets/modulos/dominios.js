// =====================================================================
//  AXISTENCE - Sockets del modulo Dominios.
//
//  Reemite a la sala "dominios" los cambios que publica PHP, para que el
//  listado se actualice en vivo (insertar / actualizar / quitar la fila) sin
//  recargar ni volver a consultar la base de datos. El evento ya viaja con
//  los datos de la fila (con los nombres de proveedor/VPS ya resueltos), asi
//  que el navegador no tiene que ir a la BD.
//
//  Lo carga y registra index2.js. Cada modulo tiene su propio script con
//  este mismo contrato: { nombre, eventos, conexion(io, socket),
//  emitir(io, evento, data) }. Espejo de ./proveedores.js.
// =====================================================================

const NOMBRE = 'dominios';

// Eventos permitidos (whitelist). PHP no puede disparar ninguno fuera de esta
// lista: si lo intenta, emitir() devuelve false e index2.js lo rechaza.
//   dominio:* -> actualizan el LISTADO (tabla de dominios).
//   dns:* / nota:* -> actualizan el DETALLE (viewProducto). El evento viaja con
//                dominio_id y cada navegador decide si le corresponde.
const EVENTOS = [
    'dominio:creado', 'dominio:actualizado', 'dominio:eliminado',
    'dns:creado', 'nota:creada',
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
