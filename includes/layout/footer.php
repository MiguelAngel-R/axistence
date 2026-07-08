<?php
/* ---------------------------------------------------------------------
   Pie comun de todas las vistas (layout).
   Los JS globales (CDNs + librerias) se definen en index.php ($libsJs).
   Variable opcional que la vista puede definir ANTES de incluir:
     $jsPagina  (array)  rutas de JS especificas de esta vista
   --------------------------------------------------------------------- */
$libsJs   = $libsJs   ?? [];   // provisto por index.php
$jsPagina = $jsPagina ?? [];
?>
    <!-- JS globales (CDNs + librerias), definidos en index.php -->
    <?php foreach ($libsJs as $js): ?>
    <script src="<?php echo $js; ?>"></script>
    <?php endforeach; ?>

    <!-- JS especifico de esta vista -->
    <?php foreach ($jsPagina as $js): ?>
    <script src="<?php echo $js; ?>"></script>
    <?php endforeach; ?>
</body>
</html>
