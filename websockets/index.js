// =====================================================================
//  AXISTENCE - Servidor de Consola SSH (websockets)
//
//  Node mantiene la conexion SSH viva y hace streaming; PHP autoriza y
//  persiste. Cubre:
//    - FASE 2: Express + Socket.IO + CORS y handshake por token validado
//      CONTRA PHP (consola_validar.php) antes de abrir nada.
//    - FASE 3: tras autorizar, abre un shell PTY con `ssh2` hacia el VPS
//      y hace streaming bidireccional (output/input), resize, cierre y
//      timeout por inactividad.
//
//  Aun NO se registran los comandos en BD (eso es la Fase 4).
//
//  ESM ("type": "module"). Requiere Node 18+ (fetch global).
// =====================================================================

import http from 'node:http';
import express from 'express';
import cors from 'cors';
import { Server } from 'socket.io';
import ssh2 from 'ssh2';

const { Client: SSHClient } = ssh2;

// --- Configuracion (env con defaults de desarrollo) ------------------
const PUERTO      = Number(process.env.AXISTENCE_WS_PORT || 3001);
const PHP_URL     = (process.env.AXISTENCE_PHP_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const CORS_ORIGIN = process.env.AXISTENCE_WS_CORS_ORIGIN || '*';
// Clave compartida Node<->PHP: se envia en cada llamada a los endpoints
// server-to-server. Debe coincidir con CONSOLA_NODE_KEY en config.php.
const NODE_KEY    = process.env.AXISTENCE_CONSOLA_NODE_KEY || 'axistence-consola-node-dev-cambiar-en-produccion';
const VALIDAR_URL       = `${PHP_URL}/endpoints/vps/consola_validar.php`;
const CONEXION_URL      = `${PHP_URL}/endpoints/vps/consola_conexion.php`;
const SESION_ABRIR_URL  = `${PHP_URL}/endpoints/vps/consola_sesion_abrir.php`;
const COMANDO_URL       = `${PHP_URL}/endpoints/vps/consola_comando.php`;
const SESION_CERRAR_URL = `${PHP_URL}/endpoints/vps/consola_sesion_cerrar.php`;
const HUERFANAS_URL     = `${PHP_URL}/endpoints/vps/consola_cerrar_huerfanas.php`;
// Timeout por inactividad del shell (ms). 0 lo desactiva.
const IDLE_MS     = Number(process.env.AXISTENCE_SSH_IDLE_MS || 5 * 60 * 1000);
const VERSION     = '0.5.0'; // Fase 7

// --- Express (health-check) ------------------------------------------
const app = express();
app.use(cors({ origin: CORS_ORIGIN }));

app.get('/', (_req, res) => {
    res.json({ ok: true, servicio: 'axistence-consola-ssh', version: VERSION });
});

const server = http.createServer(app);

// --- Socket.IO -------------------------------------------------------
const io = new Server(server, {
    cors: { origin: CORS_ORIGIN, methods: ['GET', 'POST'] },
});

/**
 * POST JSON a un endpoint PHP interno de la consola. Adjunta la clave
 * compartida Node<->PHP. Devuelve `data` si respondio ok, o null (nunca
 * lanza: la persistencia jamas debe romper la consola).
 */
async function phpPost(url, body) {
    try {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Consola-Node-Key': NODE_KEY,
            },
            body: JSON.stringify(body),
        });
        const json = await resp.json().catch(() => null);
        if (resp.ok && json && json.ok) {
            return json.data ?? {};
        }
        console.error(`[consola] PHP ${url} respondio no-ok:`, json?.mensaje || resp.status);
        return null;
    } catch (err) {
        console.error(`[consola] Error llamando ${url}:`, err.message);
        return null;
    }
}

/**
 * Valida un token de consola contra PHP (server-to-server). Devuelve el
 * motivo real para poder mostrarlo (token invalido, node-key, PHP caido...).
 * @param {string} token
 * @returns {Promise<{ok: boolean, data?: object, mensaje?: string}>}
 */
async function validarTokenContraPhp(token) {
    try {
        const resp = await fetch(VALIDAR_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Consola-Node-Key': NODE_KEY,
            },
            body: JSON.stringify({ token }),
        });
        const json = await resp.json().catch(() => null);
        if (resp.ok && json && json.ok) {
            return { ok: true, data: json.data };
        }
        const mensaje = (json && json.mensaje) || `HTTP ${resp.status}`;
        console.warn(`[consola] Validacion rechazada por PHP (${resp.status}): ${mensaje}`);
        return { ok: false, mensaje };
    } catch (err) {
        console.error('[consola] No se pudo contactar a PHP para validar:', err.message);
        return { ok: false, mensaje: 'No se pudo contactar al servidor de autorizacion (¿PHP arriba?).' };
    }
}

/**
 * Crea un lector de linea a partir de las pulsaciones que teclea el
 * operador. Acumula caracteres imprimibles y, al presionar Enter (\r/\n),
 * entrega la linea como "comando". Soporta backspace y Ctrl+C, e ignora las
 * secuencias de escape ANSI (flechas, etc.).
 *
 * Limitacion conocida: al basarse en las teclas del cliente, no "ve" lo que
 * el shell expande por su cuenta (historial con flechas, tab-completion) ni
 * distingue si se esta dentro de un editor; registra la linea tecleada.
 */
function crearBufferComandos(onComando) {
    let buf = '';
    let enEscape = false;
    return (texto) => {
        for (const ch of texto) {
            if (enEscape) {
                if (/[a-zA-Z~]/.test(ch)) enEscape = false; // fin de secuencia
                continue;
            }
            if (ch === '\x1b') {
                enEscape = true;
            } else if (ch === '\r' || ch === '\n') {
                const linea = buf.trim();
                buf = '';
                if (linea) onComando(linea);
            } else if (ch === '\x7f' || ch === '\b') {
                buf = buf.slice(0, -1);
            } else if (ch === '\x03') {
                buf = ''; // Ctrl+C cancela la linea en curso
            } else if (ch.codePointAt(0) >= 0x20) {
                buf += ch;
            }
        }
    };
}

/**
 * Traduce la credencial que devuelve PHP (consola_conexion.php) al formato
 * de conexion de ssh2.
 */
function credencialAParametros(cred) {
    const params = {
        host: cred.host,
        port: Number(cred.puerto) || 22,
        username: cred.usuario,
    };
    if (cred.tipo_auth === 'clave_privada') {
        params.privateKey = cred.secreto;
        if (cred.passphrase) params.passphrase = cred.passphrase;
    } else {
        params.password = cred.secreto ?? '';
    }
    return params;
}

/**
 * Obtiene los parametros de conexion SSH para el contexto autorizado.
 *
 * Fuente principal: PHP (`consola_conexion.php`), que devuelve la credencial
 * del VPS DESCIFRADA a partir del token. Como respaldo de DESARROLLO, si se
 * define AXISTENCE_SSH_DEV_HOST se usa ese objetivo (util para el mock SSH
 * cuando aun no hay credencial cargada).
 *
 * @param {object} _ctx  payload del token
 * @param {string} token token de la consola (para pedir la credencial a PHP)
 * @returns {Promise<object|null>}
 */
async function obtenerParametrosSsh(_ctx, token) {
    const cred = await phpPost(CONEXION_URL, { token });
    if (cred && cred.host) {
        return credencialAParametros(cred);
    }

    // Respaldo de desarrollo (mock SSH) si no hay credencial en BD.
    const host = process.env.AXISTENCE_SSH_DEV_HOST;
    if (!host) {
        return null;
    }
    const params = {
        host,
        port: Number(process.env.AXISTENCE_SSH_DEV_PORT || 22),
        username: process.env.AXISTENCE_SSH_DEV_USER || 'root',
    };
    if (process.env.AXISTENCE_SSH_DEV_KEY) {
        params.privateKey = process.env.AXISTENCE_SSH_DEV_KEY;
        if (process.env.AXISTENCE_SSH_DEV_PASSPHRASE) {
            params.passphrase = process.env.AXISTENCE_SSH_DEV_PASSPHRASE;
        }
    } else {
        params.password = process.env.AXISTENCE_SSH_DEV_PASS || '';
    }
    return params;
}

/**
 * Abre la conexion SSH + shell PTY para un socket ya autorizado y cablea
 * el streaming bidireccional. Toda la maquinaria de limpieza (SSH, timers)
 * se centraliza en cerrar().
 */
function abrirSesionSsh(socket, params, token) {
    const conn = new SSHClient();
    let stream = null;
    let idleTimer = null;
    let cerrado = false;

    // --- Registro de sesion/comandos en BD (via PHP) -----------------
    let sesionId = null;
    let sesionResuelta = false;      // ya llego la respuesta de abrir sesion
    const comandosPendientes = [];   // comandos tecleados antes de tener sesionId

    const registrarComando = (comando) => {
        if (sesionId) {
            // fire-and-forget: no bloquear el tecleo por la persistencia.
            // Se autoriza con la node-key + sesion_id (no el token, que expira).
            phpPost(COMANDO_URL, { sesion_id: sesionId, comando });
        } else if (!sesionResuelta) {
            comandosPendientes.push(comando);
        }
        // si sesionResuelta y sin sesionId, la sesion no se pudo crear -> se descarta.
    };

    const bufferComandos = crearBufferComandos(registrarComando);

    const abrirSesionBd = () => {
        phpPost(SESION_ABRIR_URL, { token }).then((data) => {
            sesionResuelta = true;
            if (data && data.sesion_id) {
                sesionId = data.sesion_id;
                for (const cmd of comandosPendientes) registrarComando(cmd);
            } else {
                console.error(`[consola] No se pudo abrir la sesion en BD socket=${socket.id}`);
            }
            comandosPendientes.length = 0;
        });
    };

    const reiniciarIdle = () => {
        if (!IDLE_MS) return;
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            socket.emit('ssh_cerrado', { mensaje: 'Sesion cerrada por inactividad.' });
            cerrar('timeout');
        }, IDLE_MS);
    };

    const cerrar = (motivo) => {
        if (cerrado) return;
        cerrado = true;
        clearTimeout(idleTimer);
        try { if (stream) stream.end(); } catch { /* noop */ }
        try { conn.end(); } catch { /* noop */ }
        console.log(`[consola] SSH cerrado socket=${socket.id} motivo=${motivo}`);

        // Cerrar la sesion en BD con el estado final (si llego a crearse).
        // Autorizado por node-key + sesion_id (el token pudo expirar ya).
        const estado = (motivo === 'conn-error' || motivo === 'shell-error') ? 'error' : 'cerrada';
        if (sesionId) {
            phpPost(SESION_CERRAR_URL, { sesion_id: sesionId, estado });
        }

        if (socket.connected) socket.disconnect(true);
    };

    conn.on('ready', () => {
        const cols = Number(socket.handshake.auth?.cols) || 80;
        const rows = Number(socket.handshake.auth?.rows) || 24;
        conn.shell({ term: 'xterm-color', cols, rows }, (err, sh) => {
            if (err) {
                socket.emit('ssh_error', { mensaje: 'No se pudo abrir el shell: ' + err.message });
                return cerrar('shell-error');
            }
            stream = sh;
            abrirSesionBd(); // crea la fila de sesion en cuanto hay shell
            socket.emit('ssh_listo', { vps_id: socket.data.consola?.vps_id });
            reiniciarIdle();

            // VPS -> navegador
            stream.on('data', (data) => {
                socket.emit('output', data.toString('utf8'));
                reiniciarIdle();
            });
            stream.stderr.on('data', (data) => {
                socket.emit('output', data.toString('utf8'));
            });
            stream.on('close', () => {
                socket.emit('ssh_cerrado', { mensaje: 'La sesion SSH termino.' });
                cerrar('stream-close');
            });
        });
    });

    conn.on('error', (err) => {
        socket.emit('ssh_error', { mensaje: 'Error de conexion SSH: ' + err.message });
        cerrar('conn-error');
    });

    conn.on('close', () => cerrar('conn-close'));

    // navegador -> VPS
    socket.on('input', (data) => {
        if (stream && typeof data === 'string') {
            stream.write(data);
            bufferComandos(data); // captura la linea para registrar el comando
            reiniciarIdle();
        }
    });

    // redimension del terminal (xterm.js -> PTY)
    socket.on('resize', (dim) => {
        if (stream && dim && Number(dim.cols) && Number(dim.rows)) {
            stream.setWindow(Number(dim.rows), Number(dim.cols), 0, 0);
        }
    });

    socket.on('disconnect', (motivo) => {
        console.log(`[consola] Desconectado socket=${socket.id} motivo=${motivo}`);
        cerrar('socket-disconnect');
    });

    conn.connect(params);
}

io.on('connection', async (socket) => {
    const token = socket.handshake.auth?.token || socket.handshake.query?.token;

    if (!token) {
        socket.emit('no_autorizado', { mensaje: 'Falta el token de acceso.' });
        socket.disconnect(true);
        return;
    }

    const val = await validarTokenContraPhp(String(token));
    if (!val.ok) {
        socket.emit('no_autorizado', { mensaje: val.mensaje || 'Token invalido o expirado.' });
        socket.disconnect(true);
        return;
    }
    const ctx = val.data;

    socket.data.consola = ctx;
    console.log(`[consola] Autorizado socket=${socket.id} vps=${ctx.vps_id} usuario=${ctx.usuario_id}`);
    socket.emit('autorizado', { vps_id: ctx.vps_id, usuario_id: ctx.usuario_id });

    // Fase 3: abrir la conexion SSH real (o simulada en pruebas).
    const params = await obtenerParametrosSsh(ctx, String(token));
    if (!params) {
        socket.emit('ssh_error', { mensaje: 'No hay credenciales SSH configuradas para este VPS.' });
        socket.disconnect(true);
        return;
    }
    abrirSesionSsh(socket, params, String(token));
});

server.listen(PUERTO, () => {
    console.log(`[consola] Servidor escuchando en http://127.0.0.1:${PUERTO}`);
    console.log(`[consola] Validando tokens contra: ${VALIDAR_URL}`);

    // Al arrancar, este proceso no tiene ninguna consola SSH viva: cualquier
    // sesion 'activa' en BD es huerfana de una ejecucion anterior. Se cierran.
    phpPost(HUERFANAS_URL, {}).then((data) => {
        if (data && data.cerradas > 0) {
            console.log(`[consola] Sesiones huerfanas cerradas al arrancar: ${data.cerradas}`);
        }
    });
});
