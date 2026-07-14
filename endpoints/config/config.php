<?php
declare(strict_types=1);

// =====================================================================
//  AXISTENCE - Configuracion global
//  Los valores pueden sobreescribirse por variables de entorno para no
//  exponer credenciales en el codigo (recomendado en produccion).
// =====================================================================

define('DB_HOST', getenv('AXISTENCE_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('AXISTENCE_DB_PORT') ?: '5432');
define('DB_NAME', getenv('AXISTENCE_DB_NAME') ?: 'AXISTENCE');
define('DB_USER', getenv('AXISTENCE_DB_USER') ?: 'postgres');
define('DB_PASS', getenv('AXISTENCE_DB_PASS') ?: 'miguel');

// development | production
define('APP_ENV', getenv('AXISTENCE_ENV') ?: 'development');

// Raiz de uploads (subcarpetas por tipo: ssl, tareas, etc.)
define('UPLOADS_DIR', realpath(__DIR__ . '/../../uploads') ?: (__DIR__ . '/../../uploads'));

// ---------------------------------------------------------------------
//  Consola SSH (modulo VPS)
//  Secreto para firmar/verificar los tokens temporales de la consola
//  (HMAC-SHA256). El token lo emite PHP y lo valida PHP; el server Node
//  nunca conoce este secreto (solo reenvia el token a consola_validar.php).
//  EN PRODUCCION define AXISTENCE_CONSOLA_SECRET con un valor aleatorio.
// ---------------------------------------------------------------------
define('CONSOLA_SECRET', getenv('AXISTENCE_CONSOLA_SECRET') ?: 'axistence-consola-dev-secret-cambiar-en-produccion');

// Vigencia (segundos) del token de consola desde su emision.
define('CONSOLA_TOKEN_TTL', (int)(getenv('AXISTENCE_CONSOLA_TOKEN_TTL') ?: 60));

// Clave para CIFRAR las credenciales SSH (password/clave privada) en BD
// (AES-256-GCM). Independiente del secreto de tokens.
// EN PRODUCCION define AXISTENCE_CONSOLA_CRYPT_KEY con un valor aleatorio y
// GUARDALO A BUEN RECAUDO: si se pierde, las credenciales no se pueden descifrar.
define('CONSOLA_CRYPT_KEY', getenv('AXISTENCE_CONSOLA_CRYPT_KEY') ?: 'axistence-consola-crypt-dev-cambiar-en-produccion');

// Clave compartida SOLO entre el server Node y PHP. Protege los endpoints
// server-to-server de la consola (validar/conexion/sesion/comando/huerfanas):
// Node la envia en la cabecera X-Consola-Node-Key y PHP la exige. Impide que
// un tercero llame esos endpoints aunque tenga un token robado.
// EN PRODUCCION define AXISTENCE_CONSOLA_NODE_KEY (mismo valor en Node y PHP).
define('CONSOLA_NODE_KEY', getenv('AXISTENCE_CONSOLA_NODE_KEY') ?: 'axistence-consola-node-dev-cambiar-en-produccion');
// --- Servidor de sockets (tiempo real de los listados) ---------------
// PHP publica los cambios en el server Node (websockets/index2.js), que los
// reemite a los navegadores. SOCKETS_URL es la direccion INTERNA (PHP->Node);
// SOCKETS_KEY es la clave compartida (mismo valor en Node y en PHP).
// EN PRODUCCION define AXISTENCE_SOCKETS_URL y AXISTENCE_SOCKETS_KEY.
define('SOCKETS_URL', getenv('AXISTENCE_SOCKETS_URL') ?: 'http://127.0.0.1:3002');
define('SOCKETS_KEY', getenv('AXISTENCE_SOCKETS_KEY') ?: 'axistence-sockets-dev-cambiar-en-produccion');
