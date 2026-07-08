<?php
/* ---------------------------------------------------------------------
   Vista: Dashboard / Panel principal.
   Vista privada (requiere sesion; el enrutador la protege).
   Por ahora vacia: los modulos apareceran en el menu lateral a medida
   que se construyan.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Panel';
$tituloSeccion = 'Panel';
$moduloActivo  = 'dashboard';

// Archivos propios de este apartado (mismo nombre que la vista).
$cssPagina = ['assets/css/dashboard.css'];
$jsPagina  = ['assets/js/dashboard.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="empty-state">
    <i class="bi bi-grid-1x2 empty-state__icon" aria-hidden="true"></i>
    <h2 class="empty-state__title">Bienvenido a AXISTENCE</h2>
    <p class="empty-state__text">El panel esta listo. Los modulos del sistema iran apareciendo en el menu lateral a medida que se construyan.</p>
</div>

<?php
require __DIR__ . '/../layout/app_footer.php';
