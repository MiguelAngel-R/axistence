// =====================================================================
//  AXISTENCE - Sockets del modulo Notificaciones.
//
//  A diferencia de los listados (una sala por MODULO, para todos los que ven
//  la tabla), las notificaciones se emiten a una sala POR USUARIO
//  ('usuario:<uuid>'). El socket entra a su sala tras identificarse con un
//  token firmado por PHP (evento 'autenticar_usuario', ver index.js): nadie
//  puede escuchar las notificaciones de otro.
//
//  PHP -> POST /emitir { modulo:'notificaciones', evento, data:{ usuario_id,
//  payload } } -> este modulo reemite `payload` a la sala del destinatario.
//
//  Mismo contrato que los demas modulos: { nombre, eventos, conexion, emitir }.
// =====================================================================

const NOMBRE = 'notificaciones';

// Eventos permitidos (whitelist). PHP no puede emitir nada fuera de esta lista.
//   notificacion:nueva     -> llego una notificacion nueva para el usuario.
//   notificacion:consumida -> se leyo/elimino en otra pestaña (sincronizar UI).
const EVENTOS = ['notificacion:nueva', 'notificacion:consumida'];

// Las notificaciones no usan el flujo 'unirse' (sala por modulo); el ingreso a
// la sala del usuario lo hace 'autenticar_usuario' en index.js tras validar el
// token. Se deja por compatibilidad con el contrato de modulos.
function conexion(_io, _socket) {
    /* no-op: la sala 'usuario:<id>' se une tras autenticar (ver index.js) */
}

// PHP -> Node -> navegador(es) del destinatario. Reemite `data.payload` a la
// sala 'usuario:<data.usuario_id>'. Devuelve false si el evento no esta
// permitido o falta el destinatario.
function emitir(io, evento, data) {
    if (!EVENTOS.includes(evento)) {
        return false;
    }
    const usuarioId = data && data.usuario_id;
    if (!usuarioId) {
        return false;
    }
    io.to('usuario:' + usuarioId).emit(evento, (data && data.payload) || {});
    console.log(`[sockets:${NOMBRE}] emitido ${evento} -> usuario:${usuarioId}`);
    return true;
}

export default { nombre: NOMBRE, eventos: EVENTOS, conexion, emitir };
