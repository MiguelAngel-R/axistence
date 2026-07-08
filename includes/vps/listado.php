<?php
/* ---------------------------------------------------------------------
   Modulo: VPS / Servidores - Vista principal (listado).
   Primer modulo de "Productos". Sigue el patron de los modulos previos
   (listado + formulario inline en el mismo espacio) y agrega una vista
   de DETALLE (viewProducto.php) que se abre al hacer clic en una fila y
   muestra toda la informacion relacionada del VPS.
   Datos y paginador por AJAX (vps.js + general.js).
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - VPS / Servidores';
$tituloSeccion = 'VPS / Servidores';
$moduloActivo  = 'vps';

// Archivos propios del modulo (compartidos por todas sus vistas).
$cssPagina = ['assets/css/vps.css'];
$jsPagina  = ['assets/js/vps.js'];

// Tipos de asociacion validos (enum tipo_asociacion_vps).
$tiposAsociacion = ['Compartido', 'Dedicado'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarVps" class="form-control"
               placeholder="Buscar por referencia o proveedor…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoVps">
            Registrar VPS
        </button>
    </div>
</div>


<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Referencia</th>
                    <th>Etiqueta</th>
                    <th>Proveedor</th>
                    <th>Tipo servidor</th>
                    <th>Disco</th>
                    <th>RAM</th>
                    <th class="col-precio">Precio venta</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyVps">
                <tr><td colspan="8" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================================================================
     Modal PRINCIPAL - Registrar / Editar VPS (simplificado).
     Solo captura: proveedor, referencia (que aporta hardware+costos+tipo),
     etiqueta y fecha de creacion. El vencimiento se calcula solo
     (creacion + 1 mes - 1 dia). NO se asocian clientes aqui.
     ===================================================================== -->
<div class="modal fade" id="modalVps" tabindex="-1" aria-hidden="true" aria-labelledby="formVpsTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formVpsTitulo">Nuevo VPS</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formVpsError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formVps" novalidate autocomplete="off">
            <div class="row g-4">
                <div class="col-12">
                    <label class="form-label" for="fvProveedor">Proveedor *</label>
                    <select class="form-select" id="fvProveedor" name="proveedor_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <!-- Referencia: combobox (autocomplete + dropdown) + boton "+"
                     que abre el modal anidado para crear una referencia nueva.
                     Al elegirla, la VPS hereda todas sus specs (se muestran abajo). -->
                <div class="col-12">
                    <label class="form-label" for="fvReferenciaInput">Referencia del VPS *</label>
                    <div class="ref-vps-row">
                        <div class="ax-combobox" id="cbReferenciaVps">
                            <input type="text" class="form-control" id="fvReferenciaInput" data-rol="input"
                                   autocomplete="off" placeholder="Selecciona o escribe…">
                            <button type="button" class="ax-combobox__toggle" data-rol="toggle" tabindex="-1"
                                    aria-label="Desplegar referencias">
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                            <ul class="ax-combobox__panel d-none" data-rol="panel"></ul>
                        </div>
                        <button type="button" class="btn btn-outline-secondary ref-vps-add" id="btnNuevaReferenciaVps"
                                title="Nueva referencia" aria-label="Nueva referencia">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="form-text">Elige una referencia del proveedor o crea una nueva con +.</div>
                    <!-- Resumen de specs heredadas de la referencia elegida (solo lectura). -->
                    <div id="fvRefResumen" class="ref-vps-resumen d-none mt-2"></div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="fvLabel">Etiqueta del VPS</label>
                    <input class="form-control" type="text" id="fvLabel" name="label" maxlength="120"
                           placeholder="Ej: web-produccion-01">
                    <div class="form-text">Nombre corto para identificar esta VPS en concreto.</div>
                </div>

                <!-- Fecha de creacion: sustituye al antiguo "fecha de vencimiento".
                     El vencimiento se calcula solo en el backend (creacion + 1 mes
                     - 1 dia) y se muestra en el detalle (viewProducto), no aqui. -->
                <div class="col-12">
                    <label class="form-label" for="fvCreacion">Fecha de creación *</label>
                    <input class="form-control" type="date" id="fvCreacion" name="fecha_creacion" required>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarVps">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================================
     Modal ANIDADO - Nueva referencia (plantilla tecnica y economica).
     Aqui viven TODOS los campos de hardware, costos y tipo de servidor.
     Se abre encima del modal de VPS con el boton "+" y, al guardar, precarga
     la referencia creada en el combobox del modal padre.
     ===================================================================== -->
<div class="modal fade" id="modalNuevaReferenciaVps" tabindex="-1" aria-hidden="true" aria-labelledby="modalNuevaReferenciaTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6" id="modalNuevaReferenciaTitulo">Nueva referencia</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formReferenciaError" class="alert alert-danger d-none" role="alert"></div>
                <p class="form-panel__sub mb-3" id="referenciaProveedorNombre"></p>

                <form id="formNuevaReferencia" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="frNombre">Nombre / código de la referencia *</label>
                            <input class="form-control" type="text" id="frNombre" maxlength="100"
                                   placeholder="Ej: linode-web-01" required>
                        </div>

                        <!-- Capacidad de disco: valor + unidad (GB / TB) -->
                        <div class="col-md-4">
                            <label class="form-label" for="frDisco">Capacidad de disco *</label>
                            <div class="input-group">
                                <input class="form-control" type="text" inputmode="decimal" id="frDisco"
                                       placeholder="80" required>
                                <select class="form-select ax-unidad" id="frDiscoUnidad" aria-label="Unidad de disco">
                                    <option value="GB">GB</option>
                                    <option value="TB">TB</option>
                                </select>
                            </div>
                        </div>

                        <!-- Memoria RAM: valor + unidad (GB / TB) -->
                        <div class="col-md-4">
                            <label class="form-label" for="frRam">Memoria RAM *</label>
                            <div class="input-group">
                                <input class="form-control" type="text" inputmode="decimal" id="frRam"
                                       placeholder="4" required>
                                <select class="form-select ax-unidad" id="frRamUnidad" aria-label="Unidad de RAM">
                                    <option value="GB">GB</option>
                                    <option value="TB">TB</option>
                                </select>
                            </div>
                        </div>

                        <!-- Ancho de banda: valor + unidad (GB / TB) -->
                        <div class="col-md-4">
                            <label class="form-label" for="frAnchoBanda">Ancho de banda</label>
                            <div class="input-group">
                                <input class="form-control" type="text" inputmode="decimal" id="frAnchoBanda"
                                       placeholder="4">
                                <select class="form-select ax-unidad" id="frAnchoBandaUnidad" aria-label="Unidad de ancho de banda">
                                    <option value="TB">TB</option>
                                    <option value="GB">GB</option>
                                </select>
                            </div>
                        </div>

                        <!-- Precios multimoneda: selector COP / USD + valor -->
                        <div class="col-md-6">
                            <label class="form-label" for="frPrecioCompra">Precio de compra</label>
                            <div class="input-group">
                                <select class="form-select ax-moneda" id="frMonedaCompra" aria-label="Moneda de compra">
                                    <option value="COP">$ COP</option>
                                    <option value="USD">US$ USD</option>
                                </select>
                                <input class="form-control" type="number" id="frPrecioCompra"
                                       min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="frPrecioVenta">Precio de venta</label>
                            <div class="input-group">
                                <select class="form-select ax-moneda" id="frMonedaVenta" aria-label="Moneda de venta">
                                    <option value="COP">$ COP</option>
                                    <option value="USD">US$ USD</option>
                                </select>
                                <input class="form-control" type="number" id="frPrecioVenta"
                                       min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="frTipoServidor">Tipo de servidor</label>
                            <select class="form-select" id="frTipoServidor">
                                <?php foreach ($tiposAsociacion as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarReferencia">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar referencia
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle (viewProducto): toda la informacion relacionada del VPS -->
<?php require __DIR__ . '/viewProducto.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
