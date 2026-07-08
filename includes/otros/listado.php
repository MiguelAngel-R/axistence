<?php
/* ---------------------------------------------------------------------
   Modulo: Otros productos (generico/extensible) - Vista principal.
   Producto con listado + formulario inline (incluye editor dinamico de
   atributos clave-valor) + vista de detalle (viewProducto.php).
   Espejo de includes/dominios/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Otros productos';
$tituloSeccion = 'Otros productos';
$moduloActivo  = 'otros';

$cssPagina = ['assets/css/otros.css'];
$jsPagina  = ['assets/js/otros.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarOtro" class="form-control"
               placeholder="Buscar por tipo, referencia o proveedor…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoOtro">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo producto
        </button>
    </div>
</div>

<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Referencia</th>
                    <th>Proveedor</th>
                    <th>Vence</th>
                    <th class="col-precio">Precio venta</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyOtros">
                <tr><td colspan="6" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalOtro" tabindex="-1" aria-hidden="true" aria-labelledby="formOtroTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formOtroTitulo">Nuevo producto</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formOtroError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formOtro" novalidate autocomplete="off">
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label" for="foTipo">Tipo de producto *</label>
                    <input class="form-control" type="text" id="foTipo" name="tipo_producto"
                           maxlength="100" placeholder="Ej: Licencia de software" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="foReferencia">Nombre / Referencia *</label>
                    <input class="form-control" type="text" id="foReferencia" name="nombre_referencia"
                           maxlength="150" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="foProveedor">Proveedor *</label>
                    <select class="form-select" id="foProveedor" name="proveedor_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="foFechaRegistro">Fecha de registro *</label>
                    <input class="form-control" type="date" id="foFechaRegistro" name="fecha_registro" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="foFechaVencimiento">Fecha de vencimiento</label>
                    <input class="form-control" type="date" id="foFechaVencimiento" name="fecha_vencimiento">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="foPrecioCompra">Precio de compra</label>
                    <input class="form-control" type="number" id="foPrecioCompra" name="precio_compra"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="foPrecioVenta">Precio de venta</label>
                    <input class="form-control" type="number" id="foPrecioVenta" name="precio_venta"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Clientes asociados</label>
                    <?php $msId = 'foClientes'; $msPlaceholder = 'Selecciona clientes…'; $msBuscar = 'Buscar cliente…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>

                <div class="col-12">
                    <label class="form-label">Campos adicionales (clave-valor)</label>
                    <div id="foAtributos" class="atributos"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAgregarAtributo">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar campo
                    </button>
                    <div class="form-text">Permite registrar atributos personalizados sin modificar la base de datos.</div>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarOtro">
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
