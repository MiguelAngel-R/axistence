<?php
/* ---------------------------------------------------------------------
   Modulo: Dominios - Vista principal (listado).
   Producto con listado + formulario inline + vista de detalle
   (viewProducto.php) que muestra toda la informacion relacionada.
   Datos y paginador por AJAX (dominios.js + general.js).
   Espejo de includes/vps/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Dominios';
$tituloSeccion = 'Dominios';
$moduloActivo  = 'dominios';

$cssPagina = ['assets/css/dominios.css'];
$jsPagina  = ['assets/js/dominios.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarDominio" class="form-control"
               placeholder="Buscar por dominio o proveedor…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoDominio">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo dominio
        </button>
    </div>
</div>

<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Dominio</th>
                    <th>Proveedor</th>
                    <th>Servidor / VPS</th>
                    <th>Vence</th>
                    <th class="col-precio">Precio venta</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyDominios">
                <tr><td colspan="6" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalDominio" tabindex="-1" aria-hidden="true" aria-labelledby="formDominioTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formDominioTitulo">Nuevo dominio</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formDominioError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formDominio" novalidate autocomplete="off">
            <div class="row g-4">
                <!-- El proveedor va primero: de el dependen los servidores y las cuentas. -->
                <div class="col-md-6">
                    <label class="form-label" for="fdProveedor">Proveedor *</label>
                    <select class="form-select" id="fdProveedor" name="proveedor_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fdNombre">Nombre de dominio *</label>
                    <input class="form-control" type="text" id="fdNombre" name="nombre_dominio"
                           maxlength="255" placeholder="Ej: interacto.com" required>
                </div>

                <!-- Servidor / VPS: lista los servidores del proveedor elegido y, como
                     ultima opcion, "Servidor externo". Si se elige un servidor del
                     sistema => administracion propia (aparece la cuenta). Si se elige
                     "Servidor externo" => terceros (aparece el campo del servidor externo). -->
                <div class="col-md-6">
                    <label class="form-label" for="fdVps">Servidor / VPS *</label>
                    <select class="form-select" id="fdVps" name="vps_id">
                        <option value="">— Selecciona —</option>
                    </select>
                </div>

                <!-- Servidor externo (solo cuando se elige "Servidor externo"). -->
                <div class="col-md-6 grupo-vps-externa d-none">
                    <label class="form-label" for="fdVpsExterna">VPS / servidor externo *</label>
                    <input class="form-control" type="text" id="fdVpsExterna" name="vps_externa"
                           maxlength="255" placeholder="Nombre o IP del servidor externo">
                </div>

                <!-- Cuenta de acceso (solo con servidor propio): combobox + "+" -->
                <div class="col-md-6 grupo-cuenta d-none">
                    <label class="form-label" for="fdCuentaInput">Cuenta de acceso *</label>
                    <div class="ref-vps-row">
                        <div class="ax-combobox" id="cbCuentaDominio">
                            <input type="text" class="form-control" id="fdCuentaInput" data-rol="input"
                                   autocomplete="off" placeholder="Selecciona o escribe…">
                            <button type="button" class="ax-combobox__toggle" data-rol="toggle" tabindex="-1"
                                    aria-label="Desplegar cuentas">
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                            <ul class="ax-combobox__panel d-none" data-rol="panel"></ul>
                        </div>
                        <button type="button" class="btn btn-outline-secondary ref-vps-add" id="btnNuevaCuenta"
                                title="Nueva cuenta" aria-label="Nueva cuenta">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="form-text">Cuenta del proveedor con la que administramos el dominio.</div>
                </div>

                <!-- Fecha de registro: el vencimiento se calcula solo (registro + 1 año - 1 día). -->
                <div class="col-md-4">
                    <label class="form-label" for="fdFechaRegistro">Fecha de registro *</label>
                    <input class="form-control" type="date" id="fdFechaRegistro" name="fecha_registro" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="fdPrecioCompra">Precio de compra</label>
                    <input class="form-control" type="number" id="fdPrecioCompra" name="precio_compra"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="fdPrecioVenta">Precio de venta</label>
                    <input class="form-control" type="number" id="fdPrecioVenta" name="precio_venta"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <!-- Un dominio se asocia a un unico cliente (o a ninguno). -->
                <div class="col-md-6">
                    <label class="form-label" for="fdCliente">Cliente</label>
                    <select class="form-select" id="fdCliente" name="cliente_id">
                        <option value="">— Sin cliente —</option>
                    </select>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarDominio">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal ANIDADO: crear una cuenta de acceso para el proveedor del dominio.
     Se abre encima del modal de dominio con "+" y, al guardar, precarga la
     cuenta creada en el combobox del modal padre. -->
<div class="modal fade" id="modalNuevaCuenta" tabindex="-1" aria-hidden="true" aria-labelledby="modalNuevaCuentaTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6" id="modalNuevaCuentaTitulo">Nueva cuenta de acceso</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formCuentaError" class="alert alert-danger d-none" role="alert"></div>
                <p class="form-panel__sub mb-3" id="cuentaProveedorNombre"></p>

                <form id="formNuevaCuenta" novalidate autocomplete="off">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fcaAlias">Alias de la cuenta *</label>
                            <input class="form-control" type="text" id="fcaAlias" maxlength="100" placeholder="Ej: GoDaddy Interacto" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcaUrl">URL de acceso</label>
                            <input class="form-control" type="text" id="fcaUrl" maxlength="255" placeholder="https://panel.godaddy.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcaUsuario">Usuario</label>
                            <input class="form-control" type="text" id="fcaUsuario" maxlength="150" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcaClave">Clave</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="fcaClave" autocomplete="new-password">
                                <button class="password-toggle" type="button" data-toggle-pass="#fcaClave"
                                        aria-label="Mostrar u ocultar clave">Mostrar</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcaCorreoRec">Correo de recuperación</label>
                            <input class="form-control" type="email" id="fcaCorreoRec" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcaTelRec">Teléfono de recuperación</label>
                            <input class="form-control" type="text" id="fcaTelRec" maxlength="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label">2FA (autenticación en dos pasos)</label>
                            <div id="dc2faRows"></div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAgregar2fa">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar 2FA
                            </button>
                            <div class="form-text">Aplicación usada y quién la tiene (usuario interno). Puede haber varios.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="fcaNotas">Notas</label>
                            <textarea class="form-control" id="fcaNotas" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarCuenta">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar cuenta
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle (viewProducto) -->
<?php require __DIR__ . '/viewProducto.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
