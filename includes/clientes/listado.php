<?php
/* ---------------------------------------------------------------------
   Modulo: Clientes - Vista principal (listado).
   'listado.php' es el nombre generico de la vista principal de cada
   modulo. Muestra la tabla de clientes; los datos y el paginador se
   cargan por AJAX (clientes.js + general.js). El paginador se pinta en
   el footer fijo y reutilizable del shell.
   Espejo de includes/usuarios_internos/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Clientes';
$tituloSeccion = 'Clientes';
$moduloActivo  = 'clientes';

// Archivos propios del modulo (compartidos por todas sus vistas).
$cssPagina = ['assets/css/clientes.css'];
$jsPagina  = ['assets/js/clientes.js'];

// URL PUBLICA del server de sockets (tiempo real). El navegador la usa para
// conectarse; en produccion se sobreescribe por entorno (debe ser wss:// si el
// sitio va por HTTPS). Es distinta de la interna PHP->Node (SOCKETS_URL).
$socketsWsUrl = getenv('AXISTENCE_SOCKETS_URL_PUBLICA') ?: 'http://127.0.0.1:3002';

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarCliente" class="form-control"
               placeholder="Buscar por nombre, identificacion o correo…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoCliente">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo cliente
        </button>
    </div>
</div>

<!-- Vista de listado (tabla). data-ws = URL del server de sockets (tiempo real). -->
<div id="vistaListado" data-ws="<?php echo htmlspecialchars($socketsWsUrl); ?>">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Nombre / Razon social</th>
                    <th>Identificacion</th>
                    <th>Correo</th>
                    <th>Telefono</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyClientes">
                <tr><td colspan="7" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar).
     El formulario MUTA segun el tipo de cliente (natural / juridica): la
     logica de mostrar/ocultar campos vive en clientes.js. -->
<div class="modal fade" id="modalCliente" tabindex="-1" aria-hidden="true" aria-labelledby="formClienteTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formClienteTitulo">Nuevo cliente</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formClienteError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formCliente" novalidate autocomplete="off">
                    <div class="row g-4">
                        <!-- Tipo de cliente: determina que campos se solicitan -->
                        <div class="col-12">
                            <label class="form-label d-block">Tipo de cliente *</label>
                            <div class="segmentado" role="group" aria-label="Tipo de cliente">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="tipo_cliente"
                                           id="fcTipoNatural" value="Persona Natural">
                                    <label class="form-check-label" for="fcTipoNatural">Persona Natural</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="tipo_cliente"
                                           id="fcTipoJuridica" value="Persona Jurídica">
                                    <label class="form-check-label" for="fcTipoJuridica">Persona Jurídica</label>
                                </div>
                            </div>
                        </div>

                        <!-- Persona Natural: nombres + apellidos -->
                        <div class="col-md-6 grupo-natural d-none">
                            <label class="form-label" for="fcNombres">Nombres *</label>
                            <input class="form-control" type="text" id="fcNombres" name="nombres" maxlength="100">
                        </div>
                        <div class="col-md-6 grupo-natural d-none">
                            <label class="form-label" for="fcApellidos">Apellidos *</label>
                            <input class="form-control" type="text" id="fcApellidos" name="apellidos" maxlength="100">
                        </div>

                        <!-- Persona Juridica: razon social -->
                        <div class="col-12 grupo-juridica d-none">
                            <label class="form-label" for="fcRazonSocial">Razon social *</label>
                            <input class="form-control" type="text" id="fcRazonSocial" name="razon_social" maxlength="200">
                        </div>

                        <!-- Identificacion (el tipo se fija a NIT en persona juridica) -->
                        <div class="col-md-6">
                            <label class="form-label" for="fcTipoIdent">Tipo de identificacion *</label>
                            <select class="form-select" id="fcTipoIdent" name="tipo_identificacion">
                                <option value="Cédula">Cédula</option>
                                <option value="NIT">NIT</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fcNumIdent">Numero de identificacion *</label>
                            <input class="form-control" type="text" id="fcNumIdent" name="numero_identificacion" maxlength="50" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fcCorreo">Correo</label>
                            <input class="form-control" type="email" id="fcCorreo" name="email_general" maxlength="100"
                                   placeholder="contacto@cliente.com">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fcTelefono">Telefono</label>
                            <input class="form-control" type="text" id="fcTelefono" name="telefono_principal" maxlength="50">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fcEstado">Estado</label>
                            <select class="form-select" id="fcEstado" name="estado">
                                <option value="Activo" selected>Activo</option>
                                <option value="Inactivo">Inactivo</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="fcDireccion">Direccion</label>
                            <textarea class="form-control" id="fcDireccion" name="direccion" rows="2"
                                      placeholder="Direccion fisica (opcional)"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarCliente">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle consolidada (viewClientes): toda la informacion del cliente -->
<?php require __DIR__ . '/viewClientes.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
