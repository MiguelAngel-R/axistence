// =====================================================================
//  AXISTENCE - Servidor SSH SIMULADO (solo desarrollo/pruebas)
//
//  Sustituye a un VPS real para poder probar la Fase 3 (conexion SSH +
//  streaming) sin infraestructura externa. Levanta un servidor SSH con la
//  clase `Server` de la propia libreria `ssh2`, acepta autenticacion por
//  password y ofrece un "shell" falso que:
//    - hace echo de lo que se escribe,
//    - responde a unos comandos simples (whoami, pwd, ls, date, exit),
//    - soporta backspace y redibuja el prompt en cada Enter.
//
//  NO es parte del producto: es un doble de pruebas. Node (index.js) se
//  conecta a el igual que se conectaria a un VPS real.
//
//  Uso:
//    node mock-ssh-server.mjs           (127.0.0.1:2222, demo/demo123)
//  Variables:
//    MOCK_SSH_PORT (2222) MOCK_SSH_USER (demo) MOCK_SSH_PASS (demo123)
// =====================================================================

import crypto from 'node:crypto';
import ssh2 from 'ssh2';

const { Server } = ssh2;

const PORT = Number(process.env.MOCK_SSH_PORT || 2222);
const USER = process.env.MOCK_SSH_USER || 'demo';
const PASS = process.env.MOCK_SSH_PASS || 'demo123';

// Clave de host efimera (PEM RSA / PKCS1) generada al vuelo: no persistimos
// nada, es un servidor de pruebas.
const { privateKey: HOST_KEY } = crypto.generateKeyPairSync('rsa', {
    modulusLength: 2048,
    privateKeyEncoding: { type: 'pkcs1', format: 'pem' },
    publicKeyEncoding: { type: 'pkcs1', format: 'pem' },
});

const PROMPT = `${USER}@mock:~$ `;

function responder(stream, comando) {
    switch (comando) {
        case '':
            break;
        case 'whoami':
            stream.write(`${USER}\r\n`);
            break;
        case 'pwd':
            stream.write(`/home/${USER}\r\n`);
            break;
        case 'ls':
            stream.write('archivo1.txt  carpeta  script.sh\r\n');
            break;
        case 'date':
            stream.write(`${new Date().toString()}\r\n`);
            break;
        case 'exit':
        case 'logout':
            stream.write('Cerrando sesion...\r\n');
            stream.exit(0);
            stream.end();
            return false;
        default:
            stream.write(`mock: comando no reconocido: ${comando}\r\n`);
    }
    return true;
}

const server = new Server({ hostKeys: [HOST_KEY] }, (client) => {
    console.log('[mock-ssh] cliente conectado');

    client.on('authentication', (ctx) => {
        if (ctx.method === 'password' && ctx.username === USER && ctx.password === PASS) {
            return ctx.accept();
        }
        // Pedir password si el cliente prueba primero 'none'.
        if (ctx.method === 'none') {
            return ctx.reject(['password']);
        }
        return ctx.reject();
    });

    client.on('ready', () => {
        client.on('session', (accept) => {
            const session = accept();

            session.on('pty', (accept2) => { if (accept2) accept2(); });
            session.on('window-change', (accept2) => { if (accept2) accept2(); });

            session.on('shell', (accept2) => {
                const stream = accept2();
                stream.write('Bienvenido al VPS de PRUEBA (mock SSH)\r\n');
                stream.write(PROMPT);

                let linea = '';
                stream.on('data', (data) => {
                    const texto = data.toString('utf8');
                    for (const ch of texto) {
                        if (ch === '\r' || ch === '\n') {
                            stream.write('\r\n');
                            const seguir = responder(stream, linea.trim());
                            linea = '';
                            if (seguir === false) return;
                            stream.write(PROMPT);
                        } else if (ch === '\x7f' || ch === '\b') {
                            if (linea.length > 0) {
                                linea = linea.slice(0, -1);
                                stream.write('\b \b');
                            }
                        } else {
                            linea += ch;
                            stream.write(ch); // echo
                        }
                    }
                });
            });
        });
    });

    client.on('error', (err) => console.error('[mock-ssh] error cliente:', err.message));
    client.on('close', () => console.log('[mock-ssh] cliente desconectado'));
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`[mock-ssh] escuchando en 127.0.0.1:${PORT} (usuario=${USER} pass=${PASS})`);
});
