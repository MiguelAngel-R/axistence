<?php
/* ---------------------------------------------------------------------
   Modulo: Cuentas de correo - Vista de detalle (viewProducto).
   Muestra la cuenta con su informacion relacionada en tabs: Extensiones
   de espacio, Registros DNS del correo y Notas.
   Lo rellena correo.js desde endpoints/correo/ver.php.
   --------------------------------------------------------------------- */
$tabs = [
    ['id' => 'extensiones', 'label' => 'Complementos',           'icon' => 'bi-hdd-stack',   'tbody' => 'detExtensiones', 'cols' => ['Licencia', 'Complemento', 'Cantidad de cuentas', 'Disponibles', 'Valor', 'Fecha de inicio']],
    ['id' => 'licencias',   'label' => 'Licencias',              'icon' => 'bi-key',          'tbody' => 'detLicencias',   'cols' => ['Tipo de licencia', 'Cantidad de cuentas', 'Creadas']],
    ['id' => 'notas',       'label' => 'Notas',                  'icon' => 'bi-journal-text', 'tbody' => 'detNotas',       'cols' => ['Fecha', 'Autor', 'Nota']],
];
?>
<div id="vistaDetalle" class="d-none">
    <div class="detalle">

        <div class="detalle__head">
            <div class="detalle__head-left">
                <button type="button" class="detalle__volver" id="btnVolverDetalle" data-ax-volver
                        aria-label="Volver al listado" title="Volver">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <div>
                    <span class="detalle__eyebrow">Relación de correo</span>
                    <h2 class="detalle__title" id="detTitulo">—</h2>
                </div>
            </div>
        </div>

        <section class="detalle__seccion">
            <h3 class="detalle__seccion-titulo">Informacion general</h3>
            <dl class="detalle__grid">
                <div><dt>Dominio</dt><dd id="detDominio">—</dd></div>
                <div><dt>Cliente</dt><dd id="detCliente">—</dd></div>
                <div><dt>Servidor de correo / MX</dt><dd id="detServidor">—</dd></div>
                <div><dt>Cantidad de cuentas</dt><dd id="detCantidad">—</dd></div>
                <div><dt>Licencias</dt><dd id="detLicencia">—</dd></div>
                <div><dt>Fecha de registro</dt><dd id="detRegistrado">—</dd></div>
                <div><dt>Fecha de vencimiento</dt><dd id="detVence">—</dd></div>
                <div><dt>Precio de costo</dt><dd id="detPrecioCosto">—</dd></div>
                <div><dt>Precio de venta</dt><dd id="detPrecioVenta">—</dd></div>
                <div><dt>Registrado en el sistema</dt><dd id="detCreado">—</dd></div>
                <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
            </dl>
        </section>

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
                <?php $esLicencias = ($t['id'] === 'licencias'); ?>
                <?php $esClicable  = in_array($t['id'], ['licencias', 'extensiones'], true); ?>
                <div class="tab-pane fade<?php echo $i === 0 ? ' show active' : ''; ?>"
                     id="pane-<?php echo $t['id']; ?>" role="tabpanel"
                     aria-labelledby="tabbtn-<?php echo $t['id']; ?>">
                    <?php if ($t['id'] === 'extensiones'): ?>
                        <!-- Boton para asignar un complemento a una licencia. -->
                        <div class="detalle-tab-acciones">
                            <button type="button" class="btn btn-primary btn-sm" id="btnAsignarExtension">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Asignar complemento
                            </button>
                        </div>
                    <?php endif; ?>
                    <div class="tabla-wrap" id="wrap-<?php echo $t['id']; ?>">
                        <table class="table tabla<?php echo $esClicable ? ' tabla--clic' : ''; ?>">
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

                    <?php if ($esLicencias): ?>
                        <!-- Drill-down: cuentas de una licencia (se muestra en el mismo
                             sitio al hacer click en una fila de la tabla de licencias).
                             La creacion/asignacion de cuentas queda solo maquetada. -->
                        <div id="licenciaCuentas" class="licencia-cuentas d-none">
                            <div class="licencia-cuentas__head">
                                <button type="button" class="detalle__volver detalle__volver--sm" id="btnVolverLicencias"
                                        aria-label="Volver a licencias" title="Volver a licencias">
                                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                                </button>
                                <div class="licencia-cuentas__id">
                                    <span class="detalle__eyebrow">Cuentas de la licencia</span>
                                    <h4 class="licencia-cuentas__titulo" id="licCuentasTitulo">—</h4>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm licencia-cuentas__crear"
                                        id="btnCrearCuenta">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Asignar / crear cuentas
                                </button>
                            </div>
                            <div class="tabla-wrap">
                                <table class="table tabla">
                                    <thead>
                                        <tr><th>Nombre</th><th>Tipo</th><th>Correo</th><th>Contraseña</th><th>Creada</th></tr>
                                    </thead>
                                    <tbody id="detLicCuentas"></tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>
