<?php
/* ---------------------------------------------------------------------
   Modulo: Hosting - Vista de detalle (viewProducto).
   Muestra el hosting con su informacion relacionada en tabs:
   Clientes (N:N), Dominios (N:N), Proyectos y Notas.
   Lo rellena hosting.js desde endpoints/hosting/ver.php.
   --------------------------------------------------------------------- */
$tabs = [
    ['id' => 'clientes',  'label' => 'Clientes',   'icon' => 'bi-people',       'tbody' => 'detClientes',  'cols' => ['Nombre / Razon social', 'Identificacion', 'Estado']],
    ['id' => 'dominios',  'label' => 'Dominios',    'icon' => 'bi-globe2',       'tbody' => 'detDominios',  'cols' => ['Dominio', 'Proveedor', 'Vence']],
    ['id' => 'proyectos', 'label' => 'Proyectos',   'icon' => 'bi-kanban',       'tbody' => 'detProyectos', 'cols' => ['Proyecto', 'Estado', 'Uso']],
    ['id' => 'notas',     'label' => 'Notas',       'icon' => 'bi-journal-text', 'tbody' => 'detNotas',     'cols' => ['Fecha', 'Autor', 'Nota']],
];
?>
<div id="vistaDetalle" class="d-none">
    <div class="detalle">

        <!-- Encabezado compacto: el hosting no tiene nombre propio, pero se
             conserva la flecha "Volver" al inicio del detalle. -->
        <div class="detalle__head">
            <div class="detalle__head-left">
                <button type="button" class="detalle__volver" id="btnVolverDetalle" data-ax-volver
                        aria-label="Volver al listado" title="Volver">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <div>
                    <span class="detalle__eyebrow">Hosting</span>
                    <h2 class="detalle__title" id="detTitulo">Detalle del hosting</h2>
                </div>
            </div>
        </div>

        <section class="detalle__seccion">
            <h3 class="detalle__seccion-titulo">Informacion general</h3>
            <dl class="detalle__grid">
                <div><dt>Servidor / VPS</dt><dd id="detVps">—</dd></div>
                <div><dt>Espacio asignado</dt><dd id="detEspacio">—</dd></div>
                <div><dt>Fecha de compra</dt><dd id="detCompra">—</dd></div>
                <div><dt>Fecha de renovacion</dt><dd id="detRenueva">—</dd></div>
                <div><dt>Precio de compra</dt><dd id="detPrecioCompra">—</dd></div>
                <div><dt>Precio de venta</dt><dd id="detPrecioVenta">—</dd></div>
                <div><dt>Registrado en el sistema</dt><dd id="detCreado">—</dd></div>
                <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
            </dl>
        </section>

        <div class="detalle-filtros">
            <div class="toolbar__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="detBuscar" class="form-control"
                       placeholder="Buscar en la tabla…" autocomplete="off">
            </div>
            <div class="ax-dropfecha" id="detFechas">
                <button type="button" class="btn btn-outline-secondary btn-sm ax-dropfecha__toggle" data-rol="toggle">
                    <i class="bi bi-calendar-range" aria-hidden="true"></i> Filtros
                </button>
                <div class="ax-dropfecha__panel d-none" data-rol="panel">
                    <label class="ax-dropfecha__label" for="detFechaDesde">Fecha inicio</label>
                    <input type="date" class="form-control form-control-sm" id="detFechaDesde" data-rol="desde">
                    <label class="ax-dropfecha__label" for="detFechaHasta">Fecha final</label>
                    <input type="date" class="form-control form-control-sm" id="detFechaHasta" data-rol="hasta">
                    <div class="ax-dropfecha__acciones">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-rol="limpiar">Limpiar</button>
                        <button type="button" class="btn btn-primary btn-sm" data-rol="aplicar">Aplicar</button>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs detalle-tabs" id="detTabs" role="tablist">
            <?php foreach ($tabs as $i => $t): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?php echo $i === 0 ? ' active' : ''; ?>"
                            id="tabbtn-<?php echo $t['id']; ?>"
                            data-bs-toggle="tab" data-bs-target="#pane-<?php echo $t['id']; ?>"
                            type="button" role="tab"
                            aria-controls="pane-<?php echo $t['id']; ?>"
                            aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                        <i class="bi <?php echo $t['icon']; ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($t['label']); ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content detalle-tabs__content" id="detTabsContent">
            <?php foreach ($tabs as $i => $t): ?>
                <div class="tab-pane fade<?php echo $i === 0 ? ' show active' : ''; ?>"
                     id="pane-<?php echo $t['id']; ?>" role="tabpanel"
                     aria-labelledby="tabbtn-<?php echo $t['id']; ?>">
                    <div class="tabla-wrap">
                        <table class="table tabla">
                            <thead>
                                <tr>
                                    <?php foreach ($t['cols'] as $col): ?>
                                        <th><?php echo htmlspecialchars($col); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="<?php echo $t['tbody']; ?>"></tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>
