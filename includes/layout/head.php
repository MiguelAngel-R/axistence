<?php
/* ---------------------------------------------------------------------
   Cabecera comun de todas las vistas (layout).
   Los CSS globales (CDNs + tema) se definen en index.php ($libsCss).
   Variables opcionales que la vista puede definir ANTES de incluir:
     $tituloPagina  (string)  titulo del <title>
     $cssPagina     (array)   rutas de CSS extra SOLO de esta vista
     $bodyClass     (string)  clase(s) para el <body>
   --------------------------------------------------------------------- */
$tituloPagina = $tituloPagina ?? 'AXISTENCE';
$libsCss      = $libsCss      ?? [];   // provisto por index.php
$cssPagina    = $cssPagina    ?? [];
$bodyClass    = $bodyClass    ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($tituloPagina); ?></title>

    <!-- CSS globales (CDNs + tema), definidos en index.php -->
    <?php foreach ($libsCss as $css): ?>
    <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; ?>

    <!-- CSS especifico de esta vista -->
    <?php foreach ($cssPagina as $css): ?>
    <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; ?>
</head>
<body<?php echo $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass) . '"' : ''; ?>>
