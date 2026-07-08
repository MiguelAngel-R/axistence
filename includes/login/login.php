<?php
/* ---------------------------------------------------------------------
   Vista: Login (Modulo 2 - acceso).
   Se carga a traves del enrutador index.php (index.php?vista=login).
   --------------------------------------------------------------------- */
$tituloPagina = 'AXISTENCE - Acceso';
$cssPagina    = ['assets/css/login.css'];
$bodyClass    = 'login-body';
require __DIR__ . '/../layout/head.php';
?>

<main class="login container-fluid p-0">
    <div class="row g-0 min-vh-100">

        <!-- Panel de marca (oculto en pantallas pequenas) -->
        <aside class="col-lg-6 login__brand d-none d-lg-flex flex-column justify-content-between">
            <div class="brand-mark">
                <svg class="brand-mark__logo" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                    <rect x="1.5" y="1.5" width="37" height="37" stroke="currentColor" stroke-width="2"/>
                    <path d="M20 9 L29 31 H24.4 L20 20.2 L15.6 31 H11 Z" fill="currentColor"/>
                </svg>
                <span class="brand-mark__name">AXISTENCE</span>
            </div>

            <div class="login__pitch">
                <h2>Gestion centralizada de clientes, productos y proyectos.</h2>
                <p>Un unico punto de verdad para dominios, servidores, certificados, correos, hosting y desarrollos de Interacto SAS.</p>
            </div>

            <div class="login__brand-footer">
                &copy; <?php echo date('Y'); ?> Interacto SAS &middot; Sistema interno
            </div>
        </aside>

        <!-- Panel del formulario -->
        <section class="col-12 col-lg-6 login__form-side d-flex align-items-center justify-content-center">
            <div class="login__card">

                <div class="login__head">
                    <h1>Iniciar sesion</h1>
                    <p>Ingresa tus credenciales para acceder al sistema.</p>
                </div>

                <form id="loginForm" novalidate autocomplete="on">
                        <div id="loginError" class="alert alert-danger d-none" role="alert"></div>

                        <div class="mb-3">
                            <label class="form-label" for="correo">Correo</label>
                            <input class="form-control" type="email" id="correo" name="correo"
                                   placeholder="usuario@interacto.com" autocomplete="username" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="contrasena">Contrasena</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="contrasena" name="contrasena"
                                       placeholder="Tu contrasena" autocomplete="current-password" required>
                                <button class="password-toggle" type="button" id="togglePass"
                                        aria-label="Mostrar u ocultar contrasena">Mostrar</button>
                            </div>
                        </div>

                        <button class="btn btn-primary w-100" type="submit" id="btnLogin">
                            <span class="spinner d-none" aria-hidden="true"></span>
                            <span id="btnLoginLabel">Iniciar sesion</span>
                        </button>
                </form>

                <div class="login__foot">
                    Acceso restringido a personal autorizado de Interacto SAS.
                </div>

            </div>
        </section>

    </div>
</main>

<?php
$jsPagina = ['assets/js/login.js'];
require __DIR__ . '/../layout/footer.php';
