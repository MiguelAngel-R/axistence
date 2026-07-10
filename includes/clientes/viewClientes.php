<?php
/* ---------------------------------------------------------------------
   Modulo: Clientes - Vista de detalle consolidada (viewClientes).
   Panel de solo lectura que se abre al hacer clic en una fila del listado
   y muestra TODA la informacion del cliente (Modulo 11 - vista consolidada):
     - Dashboard superior: tipo, identificacion, nº de proyectos, nº de productos.
     - Pestañas: Información, Proyectos, Productos (VPS/Dominios/Correo/Hosting/
       Otros que posee), Contactos, Notas e Historial (logs de auditoria).
   El contenido dinamico lo rellena clientes.js desde endpoints/clientes/ver.php.
   Sigue el mismo patron/estetica que includes/vps/viewProducto.php.
   --------------------------------------------------------------------- */

// Definicion de pestañas (orden estricto). Solo tablas; "informacion" es especial.
// La pestaña "Proyectos" absorbe a la de "Productos": cada proyecto muestra a
// que apunta (servidor + resumen de recursos) y, al hacer clic, abre un modal
// con toda su informacion relacionada (dominios, hosting, equipo, notas).
$tabs = [
    ['id' => 'informacion', 'label' => 'Información', 'icon' => 'bi-info-circle'],
    ['id' => 'proyectos',   'label' => 'Proyectos',   'icon' => 'bi-kanban',        'tbody' => 'detProyectos', 'cols' => ['Proyecto', 'Estado', 'Servidor / VPS', 'Recursos', 'Inicio']],
    ['id' => 'contactos',   'label' => 'Contactos',    'icon' => 'bi-person-lines-fill', 'tbody' => 'detContactos', 'cols' => ['Nombre', 'Cargo', 'Correo', 'Teléfono', 'Principal', 'Acciones']],
    ['id' => 'notas',       'label' => 'Notas',        'icon' => 'bi-journal-text',  'tbody' => 'detNotas',     'cols' => ['Fecha', 'Autor', 'Nota']],
    ['id' => 'historial',   'label' => 'Historial',    'icon' => 'bi-clock-history', 'tbody' => 'detLogs',      'cols' => ['Fecha', 'Usuario', 'Acción', 'Módulo', 'Detalle']],
];
?>
<div id="vistaDetalle" class="d-none">
    <div class="detalle">

        <!-- Encabezado (con flecha "Volver" a la izquierda del titulo) -->
        <div class="detalle__head">
            <div class="detalle__head-left">
                <button type="button" class="detalle__volver" id="btnVolverDetalle" data-ax-volver
                        aria-label="Volver al listado" title="Volver">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <div>
                    <span class="detalle__eyebrow">Cliente</span>
                    <h2 class="detalle__title" id="detTitulo">—</h2>
                </div>
            </div>
            <span class="badge-estado" id="detEstado">—</span>
        </div>

        <!-- Dashboard superior: resumen del cliente -->
        <section class="dash-cards">
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-person-vcard"></i> Tipo de cliente</span>
                <span class="dash-card__valor" id="dashTipo">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-card-text"></i> Identificación</span>
                <span class="dash-card__valor" id="dashIdent">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-kanban"></i> Proyectos</span>
                <span class="dash-card__valor" id="dashProyectos">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-box-seam"></i> Productos</span>
                <span class="dash-card__valor" id="dashProductos">—</span>
            </div>
        </section>

        <!-- Barra de filtros (reutilizable): buscador dinamico + rango de fechas -->
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

        <!-- Pestañas (orden estricto) -->
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
                        <!-- Tab Información: datos generales del cliente -->
                        <dl class="detalle__grid">
                            <div><dt>Tipo de cliente</dt><dd id="detTipo">—</dd></div>
                            <div><dt>Razón social</dt><dd id="detRazon">—</dd></div>
                            <div><dt>Nombres</dt><dd id="detNombres">—</dd></div>
                            <div><dt>Apellidos</dt><dd id="detApellidos">—</dd></div>
                            <div><dt>Tipo de identificación</dt><dd id="detTipoIdent">—</dd></div>
                            <div><dt>Número de identificación</dt><dd id="detNumIdent">—</dd></div>
                            <div><dt>Correo</dt><dd id="detCorreo">—</dd></div>
                            <div><dt>Teléfono</dt><dd id="detTelefono">—</dd></div>
                            <div><dt>Dirección</dt><dd id="detDireccion">—</dd></div>
                            <div><dt>Estado</dt><dd id="detEstadoInfo">—</dd></div>
                            <div><dt>Registrado en el sistema</dt><dd id="detCreado">—</dd></div>
                            <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
                        </dl>

                    <?php else: ?>
                        <?php if ($t['id'] === 'contactos'): ?>
                            <!-- Acciones de la pestaña Contactos: alta de contacto -->
                            <div class="detalle-tab-acciones">
                                <button type="button" class="btn btn-primary btn-sm" id="btnNuevoContacto">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar contacto
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

<!-- Modal de alta de persona de contacto del cliente.
     Va FUERA de #vistaDetalle para no heredar su d-none. -->
<div class="modal fade" id="modalContacto" tabindex="-1" aria-hidden="true" aria-labelledby="formContactoTitulo">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formContactoTitulo">Nuevo contacto</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formContactoError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formContacto" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="fkNombres">Nombres *</label>
                            <input class="form-control" type="text" id="fkNombres" name="nombres" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fkApellidos">Apellidos *</label>
                            <input class="form-control" type="text" id="fkApellidos" name="apellidos" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fkCargo">Cargo / puesto</label>
                            <input class="form-control" type="text" id="fkCargo" name="cargo_puesto" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fkEmail">Correo *</label>
                            <input class="form-control" type="email" id="fkEmail" name="email" maxlength="100"
                                   placeholder="contacto@cliente.com" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fkMovil">Teléfono móvil</label>
                            <input class="form-control" type="text" id="fkMovil" name="telefono_movil" maxlength="50">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fkFijo">Teléfono fijo</label>
                            <input class="form-control" type="text" id="fkFijo" name="telefono_fijo" maxlength="50">
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="fkPrincipal" name="es_contacto_principal" value="1">
                                <label class="form-check-label" for="fkPrincipal">
                                    Marcar como contacto principal
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarContacto">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Agregar</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de proyecto del cliente: se abre al hacer clic en una fila de la
     pestaña "Proyectos" y muestra toda la informacion + recursos del proyecto.
     Va FUERA de #vistaDetalle para no heredar su d-none. -->
<div class="modal fade" id="modalProyectoCliente" tabindex="-1" aria-hidden="true" aria-labelledby="mpcTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="detalle__eyebrow">Proyecto</span>
                    <h2 class="modal-title h5" id="mpcTitulo">—</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <dl class="detalle__grid">
                    <div><dt>Estado</dt><dd id="mpcEstado">—</dd></div>
                    <div><dt>Servidor / VPS</dt><dd id="mpcServidor">—</dd></div>
                    <div><dt>Fecha de inicio</dt><dd id="mpcInicio">—</dd></div>
                    <div><dt>Entrega estimada</dt><dd id="mpcEntrega">—</dd></div>
                    <div class="detalle__grid-full"><dt>Descripción / alcance</dt><dd id="mpcDescripcion">—</dd></div>
                </dl>

                <div class="mpc-recursos">
                    <div class="mpc-bloque">
                        <h3 class="mpc-bloque__titulo"><i class="bi bi-people" aria-hidden="true"></i> Equipo</h3>
                        <ul class="mpc-lista" id="mpcEquipo"></ul>
                    </div>
                    <div class="mpc-bloque">
                        <h3 class="mpc-bloque__titulo"><i class="bi bi-globe2" aria-hidden="true"></i> Dominios</h3>
                        <ul class="mpc-lista" id="mpcDominios"></ul>
                    </div>
                    <div class="mpc-bloque">
                        <h3 class="mpc-bloque__titulo"><i class="bi bi-hdd-network" aria-hidden="true"></i> Hosting</h3>
                        <ul class="mpc-lista" id="mpcHosting"></ul>
                    </div>
                    <div class="mpc-bloque">
                        <h3 class="mpc-bloque__titulo"><i class="bi bi-journal-text" aria-hidden="true"></i> Notas</h3>
                        <ul class="mpc-lista" id="mpcNotas"></ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="mpcAbrir" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir proyecto
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
