<?php
/* ---------------------------------------------------------------------
   Componente reutilizable: multiselect con buscador + chips.
   Reemplaza los <select multiple> feos en los formularios (clientes
   asociados, dominios alojados, etc.).

   Estructura:
     - un boton "toggle" que abre un panel desplegable,
     - dentro del panel un buscador y una lista de opciones,
     - debajo, los seleccionados como "chips" con boton de quitar.

   IMPORTANTE: aqui va SOLO la maquetacion. El comportamiento (abrir el
   panel, filtrar la lista, insertar un chip al hacer clic y quitarlo) se
   programara despues en JS. Los ganchos ya estan listos via data-rol.

   Variables esperadas (definirlas antes del require):
     $msId          -> id del contenedor (lo usara el JS futuro)
     $msPlaceholder -> texto del boton cuando no hay seleccion
     $msBuscar      -> placeholder del buscador
   --------------------------------------------------------------------- */
$msId          = $msId          ?? 'ax-ms';
$msPlaceholder = $msPlaceholder ?? 'Selecciona…';
$msBuscar      = $msBuscar      ?? 'Buscar…';
?>
<div class="ax-multiselect" id="<?php echo htmlspecialchars($msId); ?>" data-ms>
    <button type="button" class="ax-multiselect__toggle" data-rol="toggle">
        <span class="ax-multiselect__ph"><?php echo htmlspecialchars($msPlaceholder); ?></span>
        <i class="bi bi-chevron-down ax-multiselect__flecha" aria-hidden="true"></i>
    </button>

    <div class="ax-multiselect__panel d-none" data-rol="panel">
        <div class="ax-multiselect__buscar">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" data-rol="buscar"
                   placeholder="<?php echo htmlspecialchars($msBuscar); ?>" autocomplete="off">
        </div>
        <ul class="ax-multiselect__opciones" data-rol="opciones">
            <!-- Las opciones se poblaran por JS (pendiente). -->
        </ul>
    </div>

    <ul class="ax-multiselect__chips" data-rol="chips">
        <!-- Los seleccionados se insertaran aqui como chips (pendiente). -->
        <li class="ax-multiselect__vacio" data-rol="vacio">Ninguno seleccionado</li>
    </ul>
</div>
