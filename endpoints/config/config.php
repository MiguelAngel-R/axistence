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

// --- Servidor de sockets (tiempo real de los listados) ---------------
// PHP publica los cambios en el server Node (websockets/index2.js), que los
// reemite a los navegadores. SOCKETS_URL es la direccion INTERNA (PHP->Node);
// SOCKETS_KEY es la clave compartida (mismo valor en Node y en PHP).
// EN PRODUCCION define AXISTENCE_SOCKETS_URL y AXISTENCE_SOCKETS_KEY.
define('SOCKETS_URL', getenv('AXISTENCE_SOCKETS_URL') ?: 'http://127.0.0.1:3002');
define('SOCKETS_KEY', getenv('AXISTENCE_SOCKETS_KEY') ?: 'axistence-sockets-dev-cambiar-en-produccion');
