<?php
/* ---------------------------------------------------------------------
   Shell de la aplicacion (parte superior): sidebar + topbar.
   Lo usan TODAS las vistas privadas (dashboard y futuros modulos).
   Uso en una vista:
       $tituloPagina  = 'AXISTENCE - Clientes';
       $tituloSeccion = 'Clientes';
       $moduloActivo  = 'clientes';
       require __DIR__ . '/../layout/app_header.php';
       // ... contenido ...
       require __DIR__ . '/../layout/app_footer.php';
   --------------------------------------------------------------------- */
$tituloPagina  = $tituloPagina  ?? 'AXISTENCE';
$tituloSeccion = $tituloSeccion ?? 'AXISTENCE';
$moduloActivo  = $moduloActivo  ?? '';

// El shell siempre carga su hoja de estilos y su JS, ANTES de los
// archivos propios del apartado, para que la cascada respete estos.
// general.js (utilidades DOM reutilizables) se carga primero.
$cssPagina = $cssPagina ?? [];
array_unshift($cssPagina, 'assets/css/app.css');
$jsPagina  = $jsPagina  ?? [];
array_unshift($jsPagina, 'assets/js/app.js');
array_unshift($jsPagina, 'assets/js/general.js');

require __DIR__ . '/head.php';

// Datos del usuario en sesion (el enrutador ya garantizo que existe).
$usuarioSesion = $_SESSION['usuario'] ?? [];
$nombreUsuario = $usuarioSesion['nombre_completo'] ?? 'Usuario';
$rolUsuario    = $usuarioSesion['rol'] ?? '';

$partes    = preg_split('/\s+/', trim($nombreUsuario));
$iniciales = mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1) . (isset($partes[1]) ? mb_substr($partes[1], 0, 1) : ''));

// URL PUBLICA del server de sockets (tiempo real) para la campana de
// notificaciones. Es shell-wide (la campana esta en todas las vistas privadas);
// misma URL que usan los listados, sobreescribible por entorno (wss:// si HTTPS).
$socketsWsUrl = getenv('AXISTENCE_SOCKETS_URL_PUBLICA') ?: 'http://127.0.0.1:3002';
?>
<div class="app">

    <!-- Menu lateral -->
    <aside class="app-sidebar" id="appSidebar" aria-label="Menu lateral">
        <div class="app-sidebar__brand">
            <svg class="app-sidebar__logo" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                <rect x="1.5" y="1.5" width="37" height="37" stroke="currentColor" stroke-width="2"/>
                <path d="M20 9 L29 31 H24.4 L20 20.2 L15.6 31 H11 Z" fill="currentColor"/>
            </svg>
            <span class="app-sidebar__name">AXISTENCE</span>
        </div>

        <?php require __DIR__ . '/sidebar.php'; ?>

        <div class="app-sidebar__foot">v1.0 &middot; Interacto SAS</div>
    </aside>

    <!-- Fondo oscuro al abrir el menu en movil -->
    <div class="app-backdrop d-none" id="appBackdrop"></div>

    <!-- Columna principal -->
    <div class="app-main">
        <header class="app-topbar">
            <button class="app-topbar__toggle d-lg-none" type="button" id="sidebarToggle" aria-label="Abrir menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            <h1 class="app-topbar__title"><?php echo htmlspecialchars($tituloSeccion); ?></h1>

            <!-- Campana de notificaciones (shell-wide; la maneja app.js).
                 Carga por GET al entrar y recibe el empuje en vivo por Socket.IO
                 (data-ws = URL publica del server de sockets). -->
            <div class="app-notif" id="appNotif" data-ws="<?php echo htmlspecialchars($socketsWsUrl); ?>">
                <button class="app-notif__btn" type="button" id="notifToggle"
                        aria-label="Notificaciones" aria-expanded="false" aria-haspopup="true">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <span class="app-notif__badge d-none" id="notifBadge">0</span>
                </button>
                <div class="app-notif__panel d-none" id="notifPanel" role="dialog" aria-label="Notificaciones">
                    <div class="app-notif__head">
                        <span class="app-notif__head-title">Notificaciones</span>
                        <div class="app-notif__head-acc">
                            <button type="button" class="app-notif__link" id="notifLeerTodas">Marcar leídas</button>
                            <button type="button" class="app-notif__link" id="notifLimpiar">Limpiar</button>
                        </div>
                    </div>
                    <div class="app-notif__list" id="notifList">
                        <div class="app-notif__empty">Cargando…</div>
                    </div>
                </div>
            </div>

            <div class="app-topbar__user">
                <div class="app-user">
                    <span class="app-user__name"><?php echo htmlspecialchars($nombreUsuario); ?></span>
                    <span class="app-user__role"><?php echo htmlspecialchars($rolUsuario); ?></span>
                </div>
                <span class="app-user__avatar"><?php echo htmlspecialchars($iniciales); ?></span>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="btnLogout">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Salir
                </button>
            </div>
        </header>

        <main class="app-content">
