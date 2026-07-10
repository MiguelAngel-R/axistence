// =====================================================================
//  AXISTENCE - Servidor de tiempo real (websockets) para los listados.
//
//  Reemite a los navegadores los cambios (alta / edicion / borrado) que
//  ocurren en cada modulo, para que las tablas se actualicen SIN recargar
//  ni volver a consultar la base de datos. El flujo es:
//
//    PHP (crear / actualizar / eliminar)
//        --HTTP POST /emitir (con clave compartida)-->  este server
//        --Socket.IO a la sala del modulo-->            navegadores
//
//  La logica concreta de cada modulo vive en ./modulos/<modulo>.js y se
//  registra en el arreglo MODULOS. Este archivo solo orquesta: servidor,
//  autorizacion por clave, salas y despacho al modulo correspondiente.
//
//  Es un servidor DISTINTO al de la consola SSH (index.js): usa otro puerto
//  y otra clave, para que ambos convivan sin pisarse.
//
//  NOTA de seguridad (pendiente): por ahora cualquier cliente que alcance
//  este server puede unirse a una sala y recibir los eventos. Cuando se
//  saque a produccion conviene validar la sesion/permiso del navegador en el
//  handshake (igual que hace la consola con su token). Ver GUIA_DESARROLLO.
//
//  ESM ("type": "module"). Requiere Node 18+.
// =====================================================================

import http from 'node:http';
import express from 'express';
import cors from 'cors';
import { Server } from 'socket.io';

import clientes from './modulos/clientes.js';
import proveedores from './modulos/proveedores.js';
import dominios from './modulos/dominios.js';
import ssl from './modulos/ssl.js';
import correo from './modulos/correo.js';
import proyectos from './modulos/proyectos.js';

// --- Registro de modulos --------------------------------------------
// Para sumar un modulo: crear ./modulos/<modulo>.js (mismo contrato que
// clientes.js) e incluirlo aqui.
const MODULOS   = [clientes, proveedores, dominios, ssl, correo, proyectos];
const porNombre = new Map(MODULOS.map((m) => [m.nombre, m]));

// --- Configuracion (env con defaults de desarrollo) ------------------
const PUERTO      = Number(process.env.AXISTENCE_SOCKETS_PORT || 3002);
const CORS_ORIGIN = process.env.AXISTENCE_SOCKETS_CORS_ORIGIN || '*';
// Clave compartida PHP<->Node: PHP la envia en cada POST a /emitir. Debe
// coincidir con SOCKETS_KEY en endpoints/config/config.php.
const SOCKETS_KEY = process.env.AXISTENCE_SOCKETS_KEY || 'axistence-sockets-dev-cambiar-en-produccion';
const VERSION     = '1.0.0';

// --- Express (health-check + emisor server-to-server) ----------------
const app = express();
app.use(cors({ origin: CORS_ORIGIN }));
app.use(express.json());

app.get('/', (_req, res) => {
    res.json({
        ok: true,
        servicio: 'axistence-sockets',
        version: VERSION,
        modulos: [...porNombre.keys()],
    });
});

const server = http.createServer(app);

// --- Socket.IO -------------------------------------------------------
const io = new Server(server, {
    cors: { origin: CORS_ORIGIN, methods: ['GET', 'POST'] },
});

// --- Emisor server-to-server (PHP -> este server) --------------------
// PHP publica aqui los cambios; se reemiten a la sala del modulo. Se exige
// la clave compartida para que nadie mas pueda inyectar eventos.
app.post('/emitir', (req, res) => {
    if ((req.get('X-Sockets-Key') || '') !== SOCKETS_KEY) {
        return res.status(401).json({ ok: false, mensaje: 'Clave invalida' });
    }
    const { modulo, evento, data } = req.body || {};
    const mod = porNombre.get(modulo);
    if (!mod) {
        return res.status(404).json({ ok: false, mensaje: 'Modulo desconocido' });
    }
    const emitido = mod.emitir(io, evento, data);
    if (!emitido) {
        return res.status(422).json({ ok: false, mensaje: 'Evento no permitido para el modulo' });
    }
    return res.json({ ok: true });
});

// --- Socket.IO: los navegadores se unen a la sala de su modulo -------
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

server.listen(PUERTO, () => {
    console.log(`[sockets] Servidor de tiempo real escuchando en http://127.0.0.1:${PUERTO}`);
    console.log(`[sockets] Modulos registrados: ${[...porNombre.keys()].join(', ') || '(ninguno)'}`);
});
