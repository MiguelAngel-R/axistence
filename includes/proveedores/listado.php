<?php
/* ---------------------------------------------------------------------
   Modulo: Proveedores - Vista principal (listado).
   'listado.php' es el nombre generico de la vista principal de cada
   modulo. Muestra la tabla de proveedores; los datos y el paginador se
   cargan por AJAX (proveedores.js + general.js). El paginador se pinta
   en el footer fijo y reutilizable del shell.
   Espejo de includes/clientes/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Proveedores';
$tituloSeccion = 'Proveedores';
$moduloActivo  = 'proveedores';

// Archivos propios del modulo (compartidos por todas sus vistas).
$cssPagina = ['assets/css/proveedores.css'];
$jsPagina  = ['assets/js/proveedores.js'];

// Tipos de producto que un proveedor puede ofrecer (enum tipo_producto_enum).
$tiposProducto = ['Dominios', 'VPS', 'SSL', 'Correo', 'Hosting', 'Otros'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarProveedor" class="form-control"
               placeholder="Buscar por nombre o sitio web…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoProveedor">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo proveedor
        </button>
    </div>
</div>

<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla">
            <thead>
                <tr>
                    <th>Proveedor</th>
                    <th>Sitio web</th>
                    <th>Tipos de producto</th>
                    <th>Creado</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyProveedores">
                <tr><td colspan="5" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalProveedor" tabindex="-1" aria-hidden="true" aria-labelledby="formProveedorTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formProveedorTitulo">Nuevo proveedor</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formProveedorError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formProveedor" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="fpNombre">Nombre del proveedor *</label>
                            <input class="form-control" type="text" id="fpNombre" name="nombre_proveedor" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fpSitioWeb">Sitio web</label>
                            <input class="form-control" type="text" id="fpSitioWeb" name="sitio_web" maxlength="255"
                                   placeholder="https://proveedor.com">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Tipos de producto que ofrece</label>
                            <div class="tipos-check" role="group" aria-label="Tipos de producto que ofrece">
                                <?php foreach ($tiposProducto as $tipo): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tipos"
                                               value="<?php echo htmlspecialchars($tipo); ?>"
                                               id="fpTipo<?php echo htmlspecialchars($tipo); ?>">
                                        <label class="form-check-label" for="fpTipo<?php echo htmlspecialchars($tipo); ?>">
                                            <?php echo htmlspecialchars($tipo); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarProveedor">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../layout/app_footer.php';
