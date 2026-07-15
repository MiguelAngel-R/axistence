
// =====================================================================
//  AXISTENCE - Servidor unico de websockets.
//
//  Un solo proceso Node / un solo puerto atiende DOS responsabilidades
//  que antes vivian en procesos separados (index.js + index2.js). Se
//  separan por NAMESPACE de Socket.IO para que no se pisen:
//
//    * namespace por defecto '/'  -> LISTADOS EN TIEMPO REAL
//        PHP (crear/actualizar/eliminar)
//            --HTTP POST /emitir (clave SOCKETS_KEY)--> este server
//            --Socket.IO a la sala del modulo-->        navegadores
//        La logica de cada modulo vive en ./modulos/<modulo>.js.
//
//    * namespace '/consola'       -> CONSOLA SSH EN VIVO
//        Node mantiene la conexion SSH viva y hace streaming; PHP
//        autoriza (token, clave CONSOLA_NODE_KEY) y persiste. Abre un
//        shell PTY con `ssh2` y hace streaming bidireccional.
//
//  Las dos claves compartidas se mantienen SEPARADAS a proposito: cubren
//  fronteras de confianza distintas (SOCKETS_KEY autoriza PHP->server en
//  /emitir; CONSOLA_NODE_KEY autoriza server->PHP en los callbacks de la
//  consola). Conviven sin problema en el mismo proceso.
//
//  ESM ("type": "module"). Requiere Node 18+ (fetch global).
// =====================================================================

import http from 'node:http';
import express from 'express';
import cors from 'cors';
import { Server } from 'socket.io';
import ssh2 from 'ssh2';

import clientes from './modulos/clientes.js';
import proveedores from './modulos/proveedores.js';
import dominios from './modulos/dominios.js';
import ssl from './modulos/ssl.js';
import correo from './modulos/correo.js';
import proyectos from './modulos/proyectos.js';
import vps from './modulos/vps.js';

const { Client: SSHClient } = ssh2;

// --- Registro de modulos de listados --------------------------------
// Para sumar un modulo: crear ./modulos/<modulo>.js (mismo contrato que
// clientes.js) e incluirlo aqui.
const MODULOS   = [clientes, proveedores, dominios, ssl, correo, proyectos, vps];
const porNombre = new Map(MODULOS.map((m) => [m.nombre, m]));

// --- Configuracion (env con defaults de desarrollo) ------------------
// Puerto unico. Se mantiene AXISTENCE_SOCKETS_PORT como nombre principal
// (era el de los listados, 3002) y se acepta AXISTENCE_WS_PORT como alias
// para no romper despliegues previos de la consola.
const PUERTO      = Number(process.env.AXISTENCE_SOCKETS_PORT || process.env.AXISTENCE_WS_PORT || 3002);
const CORS_ORIGIN = process.env.AXISTENCE_SOCKETS_CORS_ORIGIN || process.env.AXISTENCE_WS_CORS_ORIGIN || '*';

// -- Clave compartida PHP<->Node para los LISTADOS (POST /emitir). ----
// Debe coincidir con SOCKETS_KEY en endpoints/config/config.php.
const SOCKETS_KEY = process.env.AXISTENCE_SOCKETS_KEY || 'axistence-sockets-dev-cambiar-en-produccion';

// -- Clave compartida Node<->PHP para la CONSOLA (callbacks S2S). -----
// Se envia en cada llamada a los endpoints consola_*. Debe coincidir con
// CONSOLA_NODE_KEY en config.php.
const PHP_URL     = (process.env.AXISTENCE_PHP_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const NODE_KEY    = process.env.AXISTENCE_CONSOLA_NODE_KEY || 'axistence-consola-node-dev-cambiar-en-produccion';
const VALIDAR_URL       = `${PHP_URL}/endpoints/vps/consola_validar.php`;
const CONEXION_URL      = `${PHP_URL}/endpoints/vps/consola_conexion.php`;
const SESION_ABRIR_URL  = `${PHP_URL}/endpoints/vps/consola_sesion_abrir.php`;
const COMANDO_URL       = `${PHP_URL}/endpoints/vps/consola_comando.php`;
const SESION_CERRAR_URL = `${PHP_URL}/endpoints/vps/consola_sesion_cerrar.php`;
const HUERFANAS_URL     = `${PHP_URL}/endpoints/vps/consola_cerrar_huerfanas.php`;
// Timeout por inactividad del shell (ms). 0 lo desactiva.
const IDLE_MS     = Number(process.env.AXISTENCE_SSH_IDLE_MS || 5 * 60 * 1000);

const VERSION     = '2.0.0'; // servidor unificado (listados + consola)

// --- Express (health-check + emisor server-to-server) ----------------
const app = express();
app.use(cors({ origin: CORS_ORIGIN }));
app.use(express.json());

app.get('/', (_req, res) => {
    res.json({
        ok: true,
        servicio: 'axistence-websockets',
        version: VERSION,
        namespaces: {
            '/': { servicio: 'listados', modulos: [...porNombre.keys()] },
            '/consola': { servicio: 'consola-ssh' },
        },
    });
});

// PHP publica aqui los cambios de los listados; se reemiten a la sala del
// modulo. Se exige la clave compartida para que nadie mas inyecte eventos.
app.post('/emitir', (req, res) => {
    if ((req.get('X-Sockets-Key') || '') !== SOCKETS_KEY) {
        return res.status(401).json({ ok: false, mensaje: 'Clave invalida' });
    }
    const { modulo, evento, data } = req.body || {};
    const mod = porNombre.get(modulo);
    if (!mod) {
        return res.status(404).json({ ok: false, mensaje: 'Modulo desconocido' });
    }
    // Los listados viven en el namespace por defecto (io): los modulos
    // reciben ese io tal cual, sin cambios respecto al server anterior.
    const emitido = mod.emitir(io, evento, data);
    if (!emitido) {
        return res.status(422).json({ ok: false, mensaje: 'Evento no permitido para el modulo' });
    }
    return res.json({ ok: true });
});

const server = http.createServer(app);

// --- Socket.IO -------------------------------------------------------
const io = new Server(server, {
    cors: { origin: CORS_ORIGIN, methods: ['GET', 'POST'] },
});

// =====================================================================
//  NAMESPACE POR DEFECTO '/'  ->  LISTADOS EN TIEMPO REAL
// =====================================================================
io.on('connection', (socket) => {
    // El navegador pide unirse a la sala de un modulo (p. ej. 'clientes').
    socket.on('unirse', (nombreModulo) => {
        const mod = porNombre.get(String(nombreModulo));
        if (!mod) {
            socket.emit('sala_rechazada', { modulo: nombreModulo });
            return;
        }
        mod.conexion(io, socket);
        socket.emit('unido', { modulo: mod.nombre });
    });
});

// =====================================================================
//  NAMESPACE '/consola'  ->  CONSOLA SSH EN VIVO
// =====================================================================
const consolaNs = io.of('/consola');

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

// Secuencias ANSI (colores, movimiento de cursor). Se quitan de la salida
// antes de inspeccionar si es un prompt de contraseña, porque estos suelen
// venir coloreados.
const RE_ANSI = /\x1b\[[0-9;?]*[a-zA-Z]|\x1b[()][A-Za-z0-9]|\x1b[=>]/g;

// Prompt tipico con el que el servidor pide una contraseña / passphrase:
// una linea que contiene "password"/"passphrase"/"contraseña" y TERMINA en ":"
// (sin salto de linea despues, porque el cursor queda esperando en la misma
// linea). Cubre casos como:
//   Password:                        | [sudo] password for miguel:
//   miguel@host's password:          | Enter passphrase for key '...':
//   New password: / Retype new password:  (comando passwd)
// El no exigir salto de linea final evita marcar como prompt la salida de un
// comando normal (que termina en "\n"), p. ej. `cat` mostrando "db_password:".
const RE_PROMPT_CLAVE = /(?:password|passphrase|contraseña|verification code)\b[^\r\n]*:[ \t]*$/i;

/**
 * Crea un lector de linea a partir de las pulsaciones que teclea el
 * operador. Acumula caracteres imprimibles y, al presionar Enter (\r/\n),
 * entrega la linea como "comando". Soporta backspace y Ctrl+C, e ignora las
 * secuencias de escape ANSI (flechas, etc.).
 *
 * SEGURIDAD: si `estadoClave.esperando` esta activo (el shell acaba de pedir
 * una contraseña, ver RE_PROMPT_CLAVE), la linea tecleada es un secreto y NO
 * se registra en el historial; solo se consume la bandera. Asi la contraseña
 * de `sudo`, `ssh`, `passwd`, etc. nunca llega a la base de datos.
 *
 * Limitacion conocida: al basarse en las teclas del cliente, no "ve" lo que
 * el shell expande por su cuenta (historial con flechas, tab-completion) ni
 * distingue si se esta dentro de un editor; registra la linea tecleada.
 */
function crearBufferComandos(onComando, estadoClave = { esperando: false }) {
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
                if (estadoClave.esperando) {
                    // La linea era la respuesta a un prompt de contraseña:
                    // se descarta y se apaga la bandera (no se registra nada).
                    estadoClave.esperando = false;
                    continue;
                }
                if (linea) onComando(linea);
            } else if (ch === '\x7f' || ch === '\b') {
                buf = buf.slice(0, -1);
            } else if (ch === '\x03') {
                buf = '';                     // Ctrl+C cancela la linea en curso
                estadoClave.esperando = false; // y tambien el prompt de clave
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
        // Muchos servidores SSH no ofrecen el metodo "password" directo sino
        // "keyboard-interactive"; con solo password fallarian ("All configured
        // authentication methods failed") aunque la clave sea correcta. Se
        // habilita el modo interactivo y se responde con la misma contraseña
        // (ver el handler 'keyboard-interactive' en abrirSesionSsh).
        params.tryKeyboard = true;
    }
    return params;
}

/**
 * Pide a PHP (`consola_conexion.php`) la credencial DESCIFRADA. A diferencia
 * de phpPost, conserva el `mensaje` de error real (p. ej. "Palabra maestra
 * incorrecta") para poder mostrarlo al operador y que reintente.
 *
 * La palabra maestra viaja solo en esta llamada; NO se guarda ni se loguea.
 * @param {string} token
 * @param {string} claveMaestra
 * @returns {Promise<{ok: boolean, data?: object, mensaje?: string}>}
 */
async function pedirCredencialAPhp(token, claveMaestra) {
    try {
        const resp = await fetch(CONEXION_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Consola-Node-Key': NODE_KEY,
            },
            body: JSON.stringify({ token, clave_maestra: claveMaestra }),
        });
        const json = await resp.json().catch(() => null);
        if (resp.ok && json && json.ok) {
            return { ok: true, data: json.data ?? {} };
        }
        const mensaje = (json && json.mensaje) || `HTTP ${resp.status}`;
        console.warn(`[consola] Credencial rechazada por PHP (${resp.status}): ${mensaje}`);
        return { ok: false, mensaje };
    } catch (err) {
        console.error('[consola] No se pudo contactar a PHP para la credencial:', err.message);
        return { ok: false, mensaje: 'No se pudo contactar al servidor de autorizacion (¿PHP arriba?).' };
    }
}

/**
 * Objetivo SSH de DESARROLLO (mock) definido por env. Se usa solo como
 * respaldo cuando el VPS aun no tiene credencial cargada en BD.
 * @returns {object|null}
 */
function objetivoSshDev() {
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
 * Obtiene los parametros de conexion SSH para el contexto autorizado.
 *
 * Fuente principal: PHP (`consola_conexion.php`), que devuelve la credencial
 * DESCIFRADA con la DEK que abre la palabra maestra. Devuelve el resultado
 * enriquecido para poder mostrar el motivo (palabra incorrecta, etc.):
 *   { ok: true, params }         -> listo para conectar
 *   { ok: false, mensaje }       -> error a mostrar (no reintentar solo)
 *
 * Solo cuando el VPS NO tiene credencial (404) se recurre al mock de
 * desarrollo AXISTENCE_SSH_DEV_HOST, si esta definido.
 *
 * @param {object} _ctx  payload del token
 * @param {string} token token de la consola
 * @param {string} claveMaestra  palabra maestra tecleada por el operador
 * @returns {Promise<{ok: boolean, params?: object, mensaje?: string}>}
 */
async function obtenerParametrosSsh(_ctx, token, claveMaestra) {
    const res = await pedirCredencialAPhp(token, claveMaestra);
    if (res.ok && res.data && res.data.host) {
        return { ok: true, params: credencialAParametros(res.data) };
    }

    // Sin credencial en BD: respaldo de desarrollo (mock SSH), si existe.
    if (!res.ok && /no encontrada|no tiene credenciales/i.test(res.mensaje || '')) {
        const dev = objetivoSshDev();
        if (dev) {
            return { ok: true, params: dev };
        }
    }

    return { ok: false, mensaje: res.mensaje || 'No hay credenciales SSH configuradas para este VPS.' };
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

    // Bandera compartida con el lector de teclas: cuando la salida del shell
    // termina en un prompt de contraseña, lo siguiente que teclee el operador
    // es un secreto y NO se debe registrar en el historial.
    const estadoClave = { esperando: false };
    const detectarPromptClave = (texto) => {
        if (RE_PROMPT_CLAVE.test(texto.replace(RE_ANSI, ''))) {
            estadoClave.esperando = true;
        }
    };

    const bufferComandos = crearBufferComandos(registrarComando, estadoClave);

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

    // Autenticacion keyboard-interactive: el servidor manda uno o varios
    // prompts (normalmente "Password:"); se responden todos con la contraseña
    // de la credencial. Solo aplica cuando hay password (auth por contraseña).
    conn.on('keyboard-interactive', (_name, _instructions, _lang, prompts, finish) => {
        finish(prompts.map(() => params.password || ''));
    });

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
                const texto = data.toString('utf8');
                socket.emit('output', texto);
                detectarPromptClave(texto); // ¿el shell esta pidiendo una clave?
                reiniciarIdle();
            });
            stream.stderr.on('data', (data) => {
                // sudo/ssh suelen escribir el prompt de contraseña en stderr.
                const texto = data.toString('utf8');
                socket.emit('output', texto);
                detectarPromptClave(texto);
            });
            stream.on('close', () => {
                socket.emit('ssh_cerrado', { mensaje: 'La sesion SSH termino.' });
                cerrar('stream-close');
            });
        });
    });

    conn.on('error', (err) => {
        // Diagnostico: el 'level' de ssh2 distingue la causa (autenticacion,
        // red, negociacion de algoritmos, timeout...). NO se loguea la clave.
        console.error(`[consola] SSH error socket=${socket.id} level=${err.level || '?'} msg=${err.message}`);
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

    // Diagnostico del intento (sin exponer la contraseña ni la clave privada).
    const metodo = params.privateKey ? 'clave_privada' : 'password';
    console.log(`[consola] Conectando SSH socket=${socket.id} ${params.username}@${params.host}:${params.port} metodo=${metodo} tryKeyboard=${!!params.tryKeyboard}`);
    conn.connect(params);
}

consolaNs.on('connection', async (socket) => {
    const token = socket.handshake.auth?.token || socket.handshake.query?.token;
    // La palabra maestra viaja en el handshake; se usa y se descarta. NO se
    // guarda en socket.data ni se loguea.
    const claveMaestra = String(socket.handshake.auth?.clave_maestra || '');

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

    // Abrir la conexion SSH real (o simulada en pruebas). obtenerParametrosSsh
    // descifra la credencial con la palabra maestra; si es incorrecta, el
    // mensaje real llega aqui para que el operador reintente.
    const res = await obtenerParametrosSsh(ctx, String(token), claveMaestra);
    if (!res.ok) {
        socket.emit('ssh_error', { mensaje: res.mensaje });
        socket.disconnect(true);
        return;
    }
    abrirSesionSsh(socket, res.params, String(token));
});

// --- Arranque --------------------------------------------------------
server.listen(PUERTO, () => {
    console.log(`[ws] Servidor unico escuchando en http://127.0.0.1:${PUERTO}`);
    console.log(`[ws] Listados (namespace '/'): ${[...porNombre.keys()].join(', ') || '(ninguno)'}`);
    console.log(`[ws] Consola SSH (namespace '/consola') validando tokens contra: ${VALIDAR_URL}`);

    // Al arrancar, este proceso no tiene ninguna consola SSH viva: cualquier
    // sesion 'activa' en BD es huerfana de una ejecucion anterior. Se cierran.
    phpPost(HUERFANAS_URL, {}).then((data) => {
        if (data && data.cerradas > 0) {
            console.log(`[consola] Sesiones huerfanas cerradas al arrancar: ${data.cerradas}`);
        }
    });
});
