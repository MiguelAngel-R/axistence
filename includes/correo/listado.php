<?php
/* ---------------------------------------------------------------------
   Modulo: Cuentas de correo - Vista principal (listado).
   Producto con listado + formulario inline + vista de detalle
   (viewProducto.php). Espejo de includes/dominios/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Cuentas de correo';
$tituloSeccion = 'Cuentas de correo';
$moduloActivo  = 'correo';

$cssPagina = ['assets/css/correo.css'];
$jsPagina  = ['assets/js/correo.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarCorreo" class="form-control"
               placeholder="Buscar por dominio, cliente o servidor…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoCorreo">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva relación de correo
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
                    <th>Servidor / MX</th>
                    <th>Cliente</th>
                    <th>Cuentas</th>
                    <th>Vence</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyCorreo">
                <tr><td colspan="6" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalCorreo" tabindex="-1" aria-hidden="true" aria-labelledby="formCorreoTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formCorreoTitulo">Nueva relación de correo</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formCorreoError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formCorreo" novalidate autocomplete="off">
            <div class="row g-4">
                <!-- La relacion asocia un dominio ya registrado con su servidor
                     de correo (MX externo, p.ej. Zoho) y su cantidad de cuentas. -->
                <div class="col-md-6">
                    <label class="form-label" for="fcDominio">Dominio *</label>
                    <select class="form-select" id="fcDominio" name="dominio_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fcCliente">Cliente *</label>
                    <select class="form-select" id="fcCliente" name="cliente_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fcMx">Servidor de correo / MX *</label>
                    <select class="form-select" id="fcMx" name="mx_registro_id" required disabled>
                        <option value="">Selecciona primero un dominio…</option>
                    </select>
                    <div class="form-text">Se elige entre los registros MX del DNS del dominio.</div>
                </div>

                <!-- Licencias: una relacion puede cargar varias (tipo + cantidad).
                     Se agregan con el boton "+" y se listan organizadas abajo. -->
                <div class="col-12">
                    <label class="form-label">Licencias *</label>
                    <div class="lic-alta">
                        <div class="lic-alta__campo">
                            <input class="form-control" type="number" id="fcLicCantidad"
                                   min="1" step="1" placeholder="Cantidad" aria-label="Cantidad de cuentas">
                        </div>
                        <div class="lic-alta__campo lic-alta__campo--tipo">
                            <input class="form-control" type="text" id="fcLicTipo" maxlength="100"
                                   placeholder="Tipo de licencia (opcional)" aria-label="Tipo de licencia">
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm lic-alta__btn" id="btnAgregarLicencia">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar
                        </button>
                    </div>
                    <ul class="lic-lista" id="fcLicenciasLista">
                        <li class="lic-lista__vacio text-muted" data-rol="vacio">Aún no has agregado licencias.</li>
                    </ul>
                    <div class="form-text">Agrega una o varias líneas. El total de cuentas es la suma de todas.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fcFechaRegistro">Fecha de registro *</label>
                    <input class="form-control" type="date" id="fcFechaRegistro" name="fecha_registro" required>
                    <div class="form-text">El vencimiento se calcula solo: registro + 1 año − 1 día.</div>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarCorreo">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de alta de una cuenta (buzon) de correo dentro de una licencia -->
<div class="modal fade" id="modalCuentaCorreo" tabindex="-1" aria-hidden="true" aria-labelledby="formCuentaTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formCuentaTitulo">Nueva cuenta de correo</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formCuentaError" class="alert alert-danger d-none" role="alert"></div>
                <p class="text-muted small mb-3" id="formCuentaInfo"></p>

                <form id="formCuentaCorreo" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="ccNombre">Nombre *</label>
                            <input class="form-control" type="text" id="ccNombre" maxlength="100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="ccApellidos">Apellidos *</label>
                            <input class="form-control" type="text" id="ccApellidos" maxlength="100" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="ccTipoCuenta">Tipo de cuenta *</label>
                            <select class="form-select" id="ccTipoCuenta" required>
                                <option value="Usuario">Usuario</option>
                                <option value="Administrador">Administrador</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="ccUsuario">Dirección de correo *</label>
                            <div class="input-group">
                                <input class="form-control" type="text" id="ccUsuario" maxlength="100"
                                       placeholder="usuario" required autocomplete="off">
                                <span class="input-group-text" id="ccDominioSufijo">@dominio</span>
                            </div>
                            <div class="form-text">Solo la parte antes del @; el dominio se toma de la relación.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="ccClave">Contraseña *</label>
                            <div class="input-group">
                                <input class="form-control" type="password" id="ccClave" maxlength="255"
                                       required autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary" id="ccVerClave"
                                        tabindex="-1" aria-label="Mostrar u ocultar contraseña">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarCuenta">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Crear cuenta
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para asignar una extension de espacio a una cuenta (MAQUETA:
     todavia no esta programada la asignacion; solo el boton + este modal). -->
<div class="modal fade" id="modalExtension" tabindex="-1" aria-hidden="true" aria-labelledby="formExtensionTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formExtensionTitulo">Asignar extensión de espacio</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formExtensionError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formExtension" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="exLicencia">Licencia relacionada *</label>
                            <select class="form-select" id="exLicencia" required>
                                <option value="">Seleccione…</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="exCuenta">Cuenta *</label>
                            <select class="form-select" id="exCuenta" required disabled>
                                <option value="">Seleccione primero una licencia…</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="exCantidad">Cantidad de extensión de espacio (GB) *</label>
                            <input class="form-control" type="number" id="exCantidad" min="1" step="1"
                                   placeholder="Ej: 5" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarExtension">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Asignar extensión
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle (viewProducto) -->
<?php require __DIR__ . '/viewProducto.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
