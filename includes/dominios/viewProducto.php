<?php
/* ---------------------------------------------------------------------
   Modulo: Dominios - Vista de detalle (viewProducto).
   Dashboard superior (vencimiento, dias restantes con color, actualizacion,
   tipo de administracion, proveedor) + 8 pestañas:
     Información · DNS · A/AAAA · MX · TXT · CNAME · Historial · Notas.
   Las pestañas A/AAAA, MX, TXT, CNAME son VISTAS FILTRADAS de la unica
   tabla de registros DNS (dominios.js reparte d.dns por tipo). Cada tab de
   DNS y Notas tiene un boton "Agregar" que crea el registro y lo vincula al
   dominio (audita -> visible en Historial). Lo rellena dominios.js.
   --------------------------------------------------------------------- */

$tiposDns = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];
// Criticidad de las notas (misma clasificacion visual que en VPS).
$criticidades = ['Información', 'Advertencia', 'Importante', 'Crítica'];

$tabs = [
    ['id' => 'informacion', 'label' => 'Información', 'icon' => 'bi-info-circle'],
    ['id' => 'dns',   'label' => 'DNS',    'icon' => 'bi-diagram-3',      'tbody' => 'detDns',      'cols' => ['Tipo', 'Nombre', 'Valor', 'TTL', 'Prioridad'], 'add' => ['btn' => 'btnAddDns',      'texto' => 'Agregar registro', 'tipos' => '']],
    ['id' => 'a',     'label' => 'A/AAAA', 'icon' => 'bi-hdd-network',    'tbody' => 'detDnsA',     'cols' => ['Tipo', 'Nombre', 'Valor', 'TTL'],              'add' => ['btn' => 'btnAddDnsA',     'texto' => 'Agregar A/AAAA',   'tipos' => 'A,AAAA']],
    ['id' => 'mx',    'label' => 'MX',     'icon' => 'bi-envelope',       'tbody' => 'detDnsMx',    'cols' => ['Prioridad', 'Nombre', 'Valor', 'TTL'],        'add' => ['btn' => 'btnAddDnsMx',    'texto' => 'Agregar MX',       'tipos' => 'MX']],
    ['id' => 'txt',   'label' => 'TXT',    'icon' => 'bi-card-text',      'tbody' => 'detDnsTxt',   'cols' => ['Nombre', 'Valor', 'TTL'],                     'add' => ['btn' => 'btnAddDnsTxt',   'texto' => 'Agregar TXT',      'tipos' => 'TXT']],
    ['id' => 'cname', 'label' => 'CNAME',  'icon' => 'bi-signpost-split', 'tbody' => 'detDnsCname', 'cols' => ['Nombre', 'Valor', 'TTL'],                     'add' => ['btn' => 'btnAddDnsCname', 'texto' => 'Agregar CNAME',    'tipos' => 'CNAME']],
    ['id' => 'historial', 'label' => 'Historial', 'icon' => 'bi-clock-history', 'tbody' => 'detLogs', 'cols' => ['Fecha', 'Usuario', 'Acción', 'Módulo', 'Detalle']],
    ['id' => 'notas', 'label' => 'Notas',  'icon' => 'bi-journal-text',   'tbody' => 'detNotas',    'cols' => ['Fecha', 'Criticidad', 'Autor', 'Nota'],       'add' => ['btn' => 'btnAddNotaDom',  'texto' => 'Agregar nota']],
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
                    <span class="detalle__eyebrow">Dominio</span>
                    <h2 class="detalle__title" id="detTitulo">—</h2>
                </div>
            </div>
            <span class="tipo-badge" id="detAdminBadge">—</span>
        </div>

        <!-- Dashboard superior -->
        <section class="dash-cards">
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-calendar-event"></i> Vencimiento</span>
                <span class="dash-card__valor" id="dashVence">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-hourglass-split"></i> Días restantes</span>
                <span class="dash-card__valor"><span class="dash-badge" id="dashDias">—</span></span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-arrow-repeat"></i> Última actualización</span>
                <span class="dash-card__valor" id="dashActualizado">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-person-gear"></i> Administración</span>
                <span class="dash-card__valor" id="dashAdmin">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-truck"></i> Proveedor</span>
                <span class="dash-card__valor" id="dashProveedor">—</span>
            </div>
        </section>

        <!-- Pestañas -->
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

                    <?php if ($t['id'] === 'informacion'): ?>
                        <dl class="detalle__grid">
                            <div><dt>Proveedor</dt><dd id="detProveedor">—</dd></div>
                            <div><dt>Servidor / VPS</dt><dd id="detVps">—</dd></div>
                            <div><dt>Fecha de registro</dt><dd id="detRegistrado">—</dd></div>
                            <div><dt>Fecha de vencimiento</dt><dd id="detVence">—</dd></div>
                            <div><dt>Precio de compra</dt><dd id="detPrecioCompra">—</dd></div>
                            <div><dt>Precio de venta</dt><dd id="detPrecioVenta">—</dd></div>
                            <div><dt>Registrado en el sistema</dt><dd id="detCreado">—</dd></div>
                            <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
                        </dl>

                        <h3 class="detalle__seccion-titulo mt-4"><i class="bi bi-person-gear"></i> Administración</h3>
                        <dl class="detalle__grid">
                            <div><dt>Tipo de administración</dt><dd id="detTipoAdmin">—</dd></div>
                            <div><dt>Cuenta</dt><dd id="detCuentaAlias">—</dd></div>
                            <div><dt>URL de acceso</dt><dd id="detCuentaUrl">—</dd></div>
                            <div><dt>Usuario</dt><dd id="detCuentaUsuario">—</dd></div>
                            <div><dt>Clave</dt><dd id="detCuentaClave">—</dd></div>
                            <div><dt>Correo de recuperación</dt><dd id="detCuentaCorreoRec">—</dd></div>
                            <div><dt>Teléfono de recuperación</dt><dd id="detCuentaTelRec">—</dd></div>
                        </dl>
                        <h3 class="detalle__seccion-titulo mt-3"><i class="bi bi-shield-lock"></i> 2FA de la cuenta</h3>
                        <div class="tabla-wrap">
                            <table class="table tabla">
                                <thead><tr><th>Aplicación</th><th>Responsable</th><th>Notas</th><th>Llaves de recuperación</th></tr></thead>
                                <tbody id="det2fa"></tbody>
                            </table>
                        </div>

                    <?php else: ?>
                        <?php if (!empty($t['add'])): ?>
                            <div class="tab-acciones">
                                <button type="button" class="btn btn-primary btn-sm" id="<?php echo $t['add']['btn']; ?>"
                                        <?php if (isset($t['add']['tipos'])): ?>data-tipos="<?php echo htmlspecialchars($t['add']['tipos']); ?>"<?php endif; ?>>
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> <?php echo htmlspecialchars($t['add']['texto']); ?>
                                </button>
                            </div>
                        <?php endif; ?>
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
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- =====================================================================
     MODALES RELACIONALES DEL DETALLE (fuera de #vistaDetalle).
     ===================================================================== -->

<!-- Agregar registro DNS (mismo modal para todos los tipos) -->
<div class="modal fade" id="modalAddDns" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar registro DNS</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddDnsError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddDns" novalidate autocomplete="off">
                    <!-- Campos en una sola linea horizontal: Tipo, Nombre (host), Valor y TTL.
                         Separacion de 4px entre cada uno (g-1). La Prioridad (MX) es
                         condicional y se muestra al final cuando aplica. -->
                    <div class="row g-1">
                        <div class="col-md-2">
                            <label class="form-label" for="dnTipo">Tipo *</label>
                            <select class="form-select" id="dnTipo" name="tipo_registro">
                                <?php foreach ($tiposDns as $td): ?>
                                    <option value="<?php echo htmlspecialchars($td); ?>"><?php echo htmlspecialchars($td); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="dnNombre">Nombre (host) *</label>
                            <input class="form-control" type="text" id="dnNombre" name="nombre" maxlength="255" placeholder="@, www, mail…" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="dnValor">Valor *</label>
                            <input class="form-control" type="text" id="dnValor" name="valor" placeholder="IP, nombre destino o texto" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="dnTtl">TTL (segundos)</label>
                            <input class="form-control" type="number" id="dnTtl" name="ttl" min="0" value="3600">
                        </div>
                        <div class="col-md-3 grupo-prioridad d-none">
                            <label class="form-label" for="dnPrioridad">Prioridad (MX) *</label>
                            <input class="form-control" type="number" id="dnPrioridad" name="prioridad" min="0" placeholder="10">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddDns"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Agregar nota -->
<div class="modal fade" id="modalAddNotaDom" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar nota</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddNotaDomError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddNotaDom" novalidate autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label" for="ndCriticidad">Criticidad *</label>
                        <select class="form-select" id="ndCriticidad" name="criticidad">
                            <?php foreach ($criticidades as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="form-label" for="ndNota">Nota *</label>
                    <textarea class="form-control" id="ndNota" name="nota" rows="3" required></textarea>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddNotaDom"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: consultar las llaves de recuperacion de un 2FA (solo lectura) -->
<div class="modal fade" id="modalLlaves2fa" tabindex="-1" aria-hidden="true" aria-labelledby="modalLlaves2faTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6" id="modalLlaves2faTitulo">Llaves de recuperación</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="form-panel__sub mb-2" id="llaves2faApp"></p>
                <ol class="llaves-lista" id="llaves2faLista"></ol>
                <p class="tabla-vacia d-none" id="llaves2faVacio">Este 2FA no tiene llaves de recuperación.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
