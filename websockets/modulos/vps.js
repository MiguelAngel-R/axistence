// =====================================================================
//  AXISTENCE - Sockets del modulo VPS / Servidores.
//
//  Reemite a la sala "vps" los cambios que publica PHP, para que el listado
//  se actualice en vivo (insertar / actualizar / quitar la fila) sin recargar
//  ni volver a consultar la base de datos. El evento ya viaja con los datos de
//  la fila (hardware/precio/tipo heredados de la referencia + clientes N:N),
//  asi que el navegador no tiene que ir a la BD.
//
//  Lo carga y registra index.js. Mismo contrato que clientes.js:
//  { nombre, eventos, conexion(io, socket), emitir(io, evento, data) }.
// =====================================================================

const NOMBRE = 'vps';

// Eventos permitidos (whitelist). PHP no puede disparar ninguno fuera de esta
// lista: si lo intenta, emitir() devuelve false e index.js lo rechaza.
//   vps:*          -> actualizan el LISTADO (tabla de VPS / servidores).
//   dominio_vps:*  -> actualizan el DETALLE (viewProducto, pestaña Dominios). El
//                     evento viaja con vps_id y cada navegador decide si le
//                     corresponde (si tiene abierto el detalle de ese VPS).
//   ssl_vps:*      -> idem para la pestaña Certificados SSL del DETALLE.
//   inventario_vps:* -> idem para la pestaña Inventario Logico del DETALLE (sin
//                     modulo propio: solo alimenta esa tabla).
//   virtualhost_vps:* -> idem para la pestaña Virtual Hosts del DETALLE (sin
//                     modulo propio).
//   historial_vps:* -> idem para la pestaña Historial del DETALLE (log de
//                     auditoria del VPS; lo dispara cada "agregar_*" al auditar).
//   nota_vps:*      -> idem para la pestaña Notas del DETALLE (sin modulo propio).
//   sesion_vps:*    -> apertura/cierre de una sesion de CONSOLA SSH del VPS, para
//                     que el panel de Historial de la consola muestre en vivo
//                     quien se conecta (punto verde) y cuando termina.
const EVENTOS = [
    'vps:creado', 'vps:actualizado', 'vps:eliminado',
    'dominio_vps:creado', 'ssl_vps:creado', 'inventario_vps:creado',
    'virtualhost_vps:creado', 'historial_vps:creado', 'nota_vps:creado',
    'sesion_vps:abierta', 'sesion_vps:cerrada',
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
