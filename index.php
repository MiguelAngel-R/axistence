<?php
/* =====================================================================
   AXISTENCE - Enrutador (front controller)
   Punto de entrada unico. Segun ?vista= carga la vista del modulo desde
   includes/. La lista blanca evita incluir archivos arbitrarios (LFI).
   Ademas protege las vistas que requieren sesion iniciada.
   ===================================================================== */

require_once __DIR__ . '/endpoints/config/session.php';
iniciar_sesion_axistence();

$autenticado = isset($_SESSION['usuario']);

/* ---------------------------------------------------------------------
   Librerias GLOBALES (CDNs y assets base) cargadas en TODAS las vistas.
   >>> Este es el UNICO lugar donde se registran CDNs y librerias JS. <<<
   Al incluir una nueva libreria (datatables, chart.js, sweetalert, etc.)
   agregar su CDN aqui, no en las vistas ni en el layout.
   --------------------------------------------------------------------- */
$libsCss = [
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'assets/css/base.css',   // tema AXISTENCE (tokens + overrides)
];
$libsJs = [
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11',   // alertas (Swal.fire)
];

// Mapa de vistas permitidas -> archivo de la vista.
$vistas = [
    'login'             => __DIR__ . '/includes/login/login.php',
    'dashboard'         => __DIR__ . '/includes/dashboard/dashboard.php',
    'usuarios_internos' => __DIR__ . '/includes/usuarios_internos/listado.php',
    'clientes'          => __DIR__ . '/includes/clientes/listado.php',
    'proveedores'       => __DIR__ . '/includes/proveedores/listado.php',
    'vps'               => __DIR__ . '/includes/vps/listado.php',
    'dominios'          => __DIR__ . '/includes/dominios/listado.php',
    'otros'             => __DIR__ . '/includes/otros/listado.php',
    'hosting'           => __DIR__ . '/includes/hosting/listado.php',
    'correo'            => __DIR__ . '/includes/correo/listado.php',
    'ssl'               => __DIR__ . '/includes/ssl/listado.php',
    'proyectos'         => __DIR__ . '/includes/proyectos/listado.php',
];

// Vistas que exigen sesion iniciada.
$requiereAuth = ['dashboard', 'usuarios_internos', 'clientes', 'proveedores', 'vps', 'dominios', 'otros', 'hosting', 'correo', 'ssl', 'proyectos'];

// Vista pedida (o por defecto segun el estado de sesion).
$vista = $_GET['vista'] ?? ($autenticado ? 'dashboard' : 'login');

if (!isset($vistas[$vista]) || !is_file($vistas[$vista])) {
    http_response_code(404);
    $vista = $autenticado ? 'dashboard' : 'login';
}

// Librerias por vista: se registran aqui (unico lugar de CDNs) pero solo se
// cargan en la vista que las necesita. El tablero Kanban (viewProyecto) usa
// Sortable.js para el drag & drop; no se carga en el resto de la app.
if ($vista === 'proyectos') {
    $libsJs[] = 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js';
}
// La consola SSH del detalle de VPS usa xterm.js (terminal en el navegador),
// su addon de ajuste y el cliente de Socket.IO. Solo se cargan en el modulo VPS.
if ($vista === 'vps') {
    $libsCss[] = 'https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css';
    $libsJs[]  = 'https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js';
    $libsJs[]  = 'https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js';
    $libsJs[]  = 'https://cdn.jsdelivr.net/npm/socket.io-client@4.8.1/dist/socket.io.min.js';
}

// Proteccion: vista privada sin sesion -> al login.
if (in_array($vista, $requiereAuth, true) && !$autenticado) {
    header('Location: index.php?vista=login');
    exit;
}
// Si ya hay sesion, el login redirige al panel.
if ($vista === 'login' && $autenticado) {
    header('Location: index.php?vista=dashboard');
    exit;
}

require $vistas[$vista];
