<?php
/* ---------------------------------------------------------------------
   Modulo: VPS / Servidores - Vista de detalle (viewProducto).
   Panel de solo lectura que se muestra al hacer clic en una fila del
   listado. Reestructurado en:
     - Dashboard superior: tarjetas de estado (vencimiento, dias restantes
       con alerta de color, ultima actualizacion, tipo de servidor, proveedor).
     - 8 pestañas en orden estricto: Información, Proyectos, Dominios,
       Certificados SSL, Inventario Lógico, Virtual Hosts, Historial (logs)
       y Notas (con criticidad visual).
   Cada tab (salvo Información e Historial) tiene un boton "Agregar" que abre
   un modal para crear el activo y vincularlo AL VPS ACTUAL de inmediato.
   El contenido dinamico lo rellena vps.js desde endpoints/vps/ver.php.
   --------------------------------------------------------------------- */

// Opciones de enums para los modales relacionales.
$criticidades  = ['Información', 'Advertencia', 'Importante', 'Crítica'];
$tiposEvento   = ['Sistema Operativo', 'Software', 'Librerías', 'Base de Datos'];
$servidoresWeb = ['Nginx', 'Apache', 'Otro'];

// URL del server de websockets (consola SSH). Sobrescribible por entorno en
// produccion; por defecto apunta al server Node local de desarrollo.
$consolaWsUrl = getenv('AXISTENCE_CONSOLA_WS_URL') ?: 'http://127.0.0.1:3001';

// Definicion de pestañas (orden estricto). 'add' define el boton "Agregar".
$tabs = [
    ['id' => 'informacion',  'label' => 'Información',      'icon' => 'bi-info-circle'],
    ['id' => 'proyectos',    'label' => 'Proyectos',        'icon' => 'bi-kanban',        'tbody' => 'detProyectos',    'cols' => ['Proyecto', 'Estado', 'Uso'],                                       'add' => ['btn' => 'btnAddProyecto',    'texto' => 'Agregar proyecto']],
    ['id' => 'dominios',     'label' => 'Dominios',         'icon' => 'bi-globe2',        'tbody' => 'detDominios',     'cols' => ['Dominio', 'Proveedor', 'Vence', 'Precio venta'],                    'add' => ['btn' => 'btnAddDominio',     'texto' => 'Agregar dominio']],
    ['id' => 'certificados', 'label' => 'Certificados SSL', 'icon' => 'bi-shield-lock',   'tbody' => 'detCertificados', 'cols' => ['Dominio protegido', 'Proveedor', 'Vence'],                          'add' => ['btn' => 'btnAddSsl',         'texto' => 'Agregar certificado']],
    ['id' => 'inventario',   'label' => 'Inventario Lógico','icon' => 'bi-hdd-stack',     'tbody' => 'detHistorial',    'cols' => ['Fecha', 'Evento', 'Software', 'Versión', 'Responsable'],            'add' => ['btn' => 'btnAddInventario',  'texto' => 'Agregar registro']],
    ['id' => 'virtualhosts', 'label' => 'Virtual Hosts',    'icon' => 'bi-diagram-3',     'tbody' => 'detVirtualHosts', 'cols' => ['Aplicación', 'ServerName', 'Dominio', 'Servidor', 'Puerto', 'SSL', 'Estado'], 'add' => ['btn' => 'btnAddVirtualHost', 'texto' => 'Agregar virtual host']],
    ['id' => 'historial',    'label' => 'Historial',        'icon' => 'bi-clock-history', 'tbody' => 'detLogs',         'cols' => ['Fecha', 'Usuario', 'Acción', 'Módulo', 'Detalle']],
    ['id' => 'notas',        'label' => 'Notas',            'icon' => 'bi-journal-text',  'tbody' => 'detNotas',        'cols' => ['Fecha', 'Criticidad', 'Autor', 'Nota'],                             'add' => ['btn' => 'btnAddNota',        'texto' => 'Agregar nota']],
    ['id' => 'consola',      'label' => 'Consola SSH',      'icon' => 'bi-terminal'],
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
                    <span class="detalle__eyebrow">VPS / Servidor</span>
                    <h2 class="detalle__title" id="detTitulo">—</h2>
                </div>
            </div>
            <span class="tipo-badge" id="detAsociacion">—</span>
        </div>

        <!-- Dashboard superior: tarjetas de estado del VPS -->
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
                <span class="dash-card__label"><i class="bi bi-hdd-rack"></i> Tipo de servidor</span>
                <span class="dash-card__valor" id="dashTipo">—</span>
            </div>
            <div class="dash-card">
                <span class="dash-card__label"><i class="bi bi-truck"></i> Proveedor</span>
                <span class="dash-card__valor" id="dashProveedor">—</span>
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
                        <!-- Tab Información: bloque general (movido del tope) -->
                        <dl class="detalle__grid">
                            <div><dt>Proveedor</dt><dd id="detProveedor">—</dd></div>
                            <div><dt>Etiqueta</dt><dd id="detEtiqueta">—</dd></div>
                            <div><dt>Tipo de servidor</dt><dd id="detTipoAsociacion">—</dd></div>
                            <div><dt>Capacidad de disco</dt><dd id="detDisco">—</dd></div>
                            <div><dt>Memoria RAM</dt><dd id="detRam">—</dd></div>
                            <div><dt>Ancho de banda</dt><dd id="detAnchoBanda">—</dd></div>
                            <div><dt>Precio de compra</dt><dd id="detPrecioCompra">—</dd></div>
                            <div><dt>Precio de venta</dt><dd id="detPrecioVenta">—</dd></div>
                            <div><dt>Fecha de creación</dt><dd id="detFechaCreacion">—</dd></div>
                            <div><dt>Registrado</dt><dd id="detCreado">—</dd></div>
                            <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
                        </dl>

                    <?php elseif ($t['id'] === 'consola'): ?>
                        <!-- Tab Consola SSH: terminal en tiempo real (xterm.js) +
                             panel lateral con el historial de comandos por sesion.
                             La conexion SSH la mantiene el server Node (websockets);
                             aqui solo se pide el token y se abre el socket. -->
                        <div class="consola" data-ws="<?php echo htmlspecialchars($consolaWsUrl); ?>">
                            <div class="consola__barra">
                                <label class="consola__campo">
                                    <span>Credencial</span>
                                    <select id="consolaCredencial" class="form-select form-select-sm">
                                        <option value="">— Sin credenciales —</option>
                                    </select>
                                </label>
                                <button type="button" class="btn btn-primary btn-sm" id="consolaConectar">
                                    <i class="bi bi-plug" aria-hidden="true"></i> Conectar
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="consolaDesconectar" disabled>
                                    <i class="bi bi-x-circle" aria-hidden="true"></i> Desconectar
                                </button>
                                <span class="consola__estado" id="consolaEstado" data-estado="off">Desconectado</span>
                            </div>

                            <div class="consola__cuerpo">
                                <div class="consola__term" id="consolaTerminal"></div>
                                <aside class="consola__lateral">
                                    <div class="consola__lateral-head">
                                        <span><i class="bi bi-clock-history" aria-hidden="true"></i> Historial</span>
                                        <button type="button" class="btn btn-icon btn-sm" id="consolaRefrescar" title="Refrescar historial">
                                            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <ul class="consola__sesiones" id="consolaSesiones">
                                        <li class="consola__vacio">Sin sesiones.</li>
                                    </ul>
                                    <div class="consola__comandos" id="consolaComandos"></div>
                                </aside>
                            </div>
                        </div>

                    <?php elseif ($t['id'] === 'inventario'): ?>
                        <!-- Tab Inventario Lógico dividido en subtabs:
                             - Registros: software / componentes instalados (tabla actual).
                             - Configuraciones: otra tabla (por ahora vacía). -->
                        <ul class="nav nav-tabs detalle-tabs detalle-subtabs" id="invSubtabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="invtab-registros" data-bs-toggle="tab"
                                        data-bs-target="#subpane-inv-registros" type="button" role="tab"
                                        aria-controls="subpane-inv-registros" aria-selected="true">
                                    <i class="bi bi-card-list" aria-hidden="true"></i> Registros
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="invtab-config" data-bs-toggle="tab"
                                        data-bs-target="#subpane-inv-config" type="button" role="tab"
                                        aria-controls="subpane-inv-config" aria-selected="false">
                                    <i class="bi bi-sliders" aria-hidden="true"></i> Configuraciones
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content detalle-subtabs__content">
                            <!-- Subtab: Registros (software / componentes) -->
                            <div class="tab-pane fade show active" id="subpane-inv-registros"
                                 role="tabpanel" aria-labelledby="invtab-registros">
                                <div class="tab-acciones">
                                    <button type="button" class="btn btn-primary btn-sm" id="btnAddInventario">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar registro
                                    </button>
                                </div>
                                <div class="tabla-wrap">
                                    <table class="table tabla">
                                        <thead>
                                            <tr><th>Fecha</th><th>Evento</th><th>Software</th><th>Versión</th><th>Responsable</th></tr>
                                        </thead>
                                        <tbody id="detHistorial"></tbody>
                                    </table>
                                </div>
                            </div>
                            <!-- Subtab: Configuraciones (otra tabla, por ahora vacía) -->
                            <div class="tab-pane fade" id="subpane-inv-config"
                                 role="tabpanel" aria-labelledby="invtab-config">
                                <div class="tabla-wrap">
                                    <table class="table tabla">
                                        <thead>
                                            <tr><th>Configuración</th><th>Valor</th><th>Descripción</th></tr>
                                        </thead>
                                        <tbody id="detConfiguraciones">
                                            <tr><td colspan="3" class="tabla-vacia">Sin registros.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <?php if (!empty($t['add'])): ?>
                            <div class="tab-acciones">
                                <button type="button" class="btn btn-primary btn-sm" id="<?php echo $t['add']['btn']; ?>">
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
     MODALES RELACIONALES DEL DETALLE (fuera de #vistaDetalle para que no
     los oculte su d-none). Cada uno crea un activo y lo vincula al VPS.
     ===================================================================== -->

<!-- Agregar Nota -->
<div class="modal fade" id="modalAddNota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar nota</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddNotaError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddNota" novalidate autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label" for="anCriticidad">Criticidad *</label>
                        <select class="form-select" id="anCriticidad" name="criticidad">
                            <?php foreach ($criticidades as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="anNota">Nota *</label>
                        <textarea class="form-control" id="anNota" name="nota" rows="3" required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddNota"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Agregar registro al Inventario Lógico -->
<div class="modal fade" id="modalAddInventario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar al inventario lógico</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddInventarioError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddInventario" novalidate autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="aiTipo">Tipo de evento *</label>
                            <select class="form-select" id="aiTipo" name="tipo_evento">
                                <?php foreach ($tiposEvento as $te): ?>
                                    <option value="<?php echo htmlspecialchars($te); ?>"><?php echo htmlspecialchars($te); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="aiSoftware">Software / componente *</label>
                            <input class="form-control" type="text" id="aiSoftware" name="software_componente" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="aiVersion">Versión</label>
                            <input class="form-control" type="text" id="aiVersion" name="version" maxlength="50">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="aiRuta">Ruta / directorio de instalación</label>
                            <input class="form-control" type="text" id="aiRuta" name="ruta_directorio_instalacion">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="aiPuertos">Puerto(s)</label>
                            <input class="form-control" type="text" id="aiPuertos" name="puertos_usados" maxlength="50">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="aiServicios">Servicios / rutas de acceso</label>
                            <input class="form-control" type="text" id="aiServicios" name="servicios_rutas_acceso">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="aiNotas">Notas</label>
                            <textarea class="form-control" id="aiNotas" name="notas" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddInventario"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Agregar Virtual Host -->
<div class="modal fade" id="modalAddVirtualHost" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar virtual host</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddVhostError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddVhost" novalidate autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="vhApp">Aplicación *</label>
                            <input class="form-control" type="text" id="vhApp" name="aplicacion" maxlength="150" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vhServerName">ServerName *</label>
                            <input class="form-control" type="text" id="vhServerName" name="server_name" maxlength="255" placeholder="app.interacto.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vhServerAlias">ServerAlias</label>
                            <input class="form-control" type="text" id="vhServerAlias" name="server_alias" maxlength="255" placeholder="www.app.interacto.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="vhServidorWeb">Servidor web</label>
                            <select class="form-select" id="vhServidorWeb" name="servidor_web">
                                <?php foreach ($servidoresWeb as $sw): ?>
                                    <option value="<?php echo htmlspecialchars($sw); ?>"><?php echo htmlspecialchars($sw); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="vhPuerto">Puerto *</label>
                            <input class="form-control" type="number" id="vhPuerto" name="puerto" min="1" max="65535" value="80" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vhDominio">Dominio (apunta al VPS)</label>
                            <select class="form-select" id="vhDominio" name="dominio_id">
                                <option value="">— Ninguno —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vhCertificado">Certificado SSL</label>
                            <select class="form-select" id="vhCertificado" name="certificado_id">
                                <option value="">— Ninguno —</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="vhDocRoot">DocumentRoot</label>
                            <input class="form-control" type="text" id="vhDocRoot" name="document_root" placeholder="/var/www/app">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="vhProxyPass">ProxyPass (upstream)</label>
                            <input class="form-control" type="text" id="vhProxyPass" name="proxy_pass" maxlength="255" placeholder="http://127.0.0.1:3000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="vhPuertoApp">Puerto app</label>
                            <input class="form-control" type="number" id="vhPuertoApp" name="puerto_aplicacion" min="1" max="65535" placeholder="3000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="vhEstado">Estado</label>
                            <select class="form-select" id="vhEstado" name="estado">
                                <option value="Activo" selected>Activo</option>
                                <option value="Inactivo">Inactivo</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="vhRutaConfig">Ruta del archivo de configuración (.conf)</label>
                            <input class="form-control" type="text" id="vhRutaConfig" name="ruta_configuracion" placeholder="/etc/nginx/sites-available/app.conf">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="vhSsl" name="ssl_habilitado">
                                <label class="form-check-label" for="vhSsl">SSL habilitado (sirve HTTPS)</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="vhNotas">Notas</label>
                            <textarea class="form-control" id="vhNotas" name="notas" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddVhost"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Agregar Dominio -->
<div class="modal fade" id="modalAddDominio" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar dominio</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddDominioError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddDominio" novalidate autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="adNombre">Nombre de dominio *</label>
                            <input class="form-control" type="text" id="adNombre" name="nombre_dominio" maxlength="255" placeholder="interacto.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="adProveedor">Proveedor *</label>
                            <select class="form-select" id="adProveedor" name="proveedor_id" required>
                                <option value="">Cargando…</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="adFechaReg">Fecha de registro *</label>
                            <input class="form-control" type="date" id="adFechaReg" name="fecha_registro" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="adFechaVen">Fecha de vencimiento</label>
                            <input class="form-control" type="date" id="adFechaVen" tabindex="-1" readonly>
                            <div class="form-text">Automática: registro + 1 año − 1 día.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="adPrecioC">Precio de compra</label>
                            <input class="form-control" type="number" id="adPrecioC" name="precio_compra" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="adPrecioV">Precio de venta</label>
                            <input class="form-control" type="number" id="adPrecioV" name="precio_venta" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddDominio"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Agregar Certificado SSL -->
<div class="modal fade" id="modalAddSsl" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6">Agregar certificado SSL</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formAddSslError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formAddSsl" novalidate autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="asDominio">Dominio que protege *</label>
                            <select class="form-select" id="asDominio" name="dominio_id" required>
                                <option value="">Cargando…</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="asProveedor">Proveedor / certificadora *</label>
                            <select class="form-select" id="asProveedor" name="proveedor_id" required>
                                <option value="">Cargando…</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="asRuta">Ruta de almacenamiento en el servidor *</label>
                            <input class="form-control" type="text" id="asRuta" name="ruta_almacenamiento" placeholder="/etc/ssl/interacto/" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="asArchivo">Paquete de certificados (.zip / .rar) *</label>
                            <input class="form-control" type="file" id="asArchivo" name="archivo" accept=".zip,.rar">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="asFechaReg">Fecha de registro *</label>
                            <input class="form-control" type="date" id="asFechaReg" name="fecha_registro" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="asFechaVen">Fecha de vencimiento</label>
                            <input class="form-control" type="date" id="asFechaVen" tabindex="-1" readonly>
                            <div class="form-text">Automática: registro + 1 año − 1 día.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="asPrecioC">Precio de compra</label>
                            <input class="form-control" type="number" id="asPrecioC" name="precio_compra" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="asPrecioV">Precio de venta</label>
                            <input class="form-control" type="number" id="asPrecioV" name="precio_venta" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarAddSsl"><i class="bi bi-check-lg"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>
