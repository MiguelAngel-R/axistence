<?php
/* ---------------------------------------------------------------------
   Modulo: Hosting - Vista principal (listado).
   Producto con listado + formulario inline (VPS + clientes N:N +
   dominios N:N) + vista de detalle (viewProducto.php).
   Espejo de includes/dominios/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Hosting';
$tituloSeccion = 'Hosting';
$moduloActivo  = 'hosting';

$cssPagina = ['assets/css/hosting.css'];
$jsPagina  = ['assets/js/hosting.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarHosting" class="form-control"
               placeholder="Buscar por servidor o espacio…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoHosting">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo hosting
        </button>
    </div>
</div>

<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Servidor / VPS</th>
                    <th>Espacio asignado</th>
                    <th>Renueva</th>
                    <th class="col-precio">Precio venta</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyHosting">
                <tr><td colspan="5" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalHosting" tabindex="-1" aria-hidden="true" aria-labelledby="formHostingTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formHostingTitulo">Nuevo hosting</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formHostingError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formHosting" novalidate autocomplete="off">
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label" for="fhVps">Servidor / VPS *</label>
                    <select class="form-select" id="fhVps" name="vps_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fhEspacio">Espacio asignado *</label>
                    <input class="form-control" type="text" id="fhEspacio" name="espacio_asignado"
                           maxlength="50" placeholder="Ej: 10 GB" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fhFechaCompra">Fecha de compra *</label>
                    <input class="form-control" type="date" id="fhFechaCompra" name="fecha_compra" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fhFechaRenovacion">Fecha de renovacion</label>
                    <input class="form-control" type="date" id="fhFechaRenovacion" tabindex="-1" readonly>
                    <div class="form-text">Automática: compra + 1 año − 1 día.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fhPrecioCompra">Precio de compra</label>
                    <input class="form-control" type="number" id="fhPrecioCompra" name="precio_compra"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fhPrecioVenta">Precio de venta</label>
                    <input class="form-control" type="number" id="fhPrecioVenta" name="precio_venta"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Clientes asociados</label>
                    <?php $msId = 'fhClientes'; $msPlaceholder = 'Selecciona clientes…'; $msBuscar = 'Buscar cliente…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Dominios alojados</label>
                    <?php $msId = 'fhDominios'; $msPlaceholder = 'Selecciona dominios…'; $msBuscar = 'Buscar dominio…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarHosting">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle (viewProducto) -->
<?php require __DIR__ . '/viewProducto.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
