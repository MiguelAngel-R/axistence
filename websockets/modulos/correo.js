// =====================================================================
//  AXISTENCE - Sockets del modulo Cuentas de correo.
//
//  Reemite a la sala "correo" los cambios que publica PHP, para que el listado
//  se actualice en vivo (insertar / actualizar / quitar la fila) sin recargar
//  ni volver a consultar la base de datos. El evento ya viaja con los datos de
//  la fila (con los nombres de dominio/cliente/servidor ya resueltos), asi que
//  el navegador no tiene que ir a la BD.
//
//  Lo carga y registra index2.js. Cada modulo tiene su propio script con
//  este mismo contrato: { nombre, eventos, conexion(io, socket),
//  emitir(io, evento, data) }. Espejo de ./ssl.js.
// =====================================================================

const NOMBRE = 'correo';

// Eventos permitidos (whitelist). PHP no puede disparar ninguno fuera de esta
// lista: si lo intenta, emitir() devuelve false e index2.js lo rechaza.
//   correo:*    -> actualizan el LISTADO (tabla de relaciones de correo).
//   cuenta:* / extension:* -> actualizan el DETALLE (viewProducto). El evento
//               viaja con cuenta_correo_id (la relacion) para que cada navegador
//               decida si le corresponde (y licencia_id en el caso de cuenta).
const EVENTOS = [
    'correo:creado', 'correo:actualizado', 'correo:eliminado',
    'cuenta:creada', 'extension:creada',
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
