<?php
/* ---------------------------------------------------------------------
   Modulo: Usuarios de Sistema - Vista principal (listado).
   Muestra la grilla de usuarios; los datos y el paginador se cargan por
   AJAX (usuarios_internos.js + general.js). El alta, la edicion y el
   cambio de clave se resuelven en MODALES dinamicos independientes; el
   habilitar/deshabilitar es una accion toggle asincrona (Fetch API).
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Usuarios de Sistema';
$tituloSeccion = 'Usuarios de Sistema';
$moduloActivo  = 'usuarios_internos';

// Archivos propios del modulo (compartidos por todas sus vistas).
$cssPagina = ['assets/css/usuarios_internos.css'];
$jsPagina  = ['assets/js/usuarios_internos.js'];

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarUsuario" class="form-control"
               placeholder="Buscar por nombre, usuario, correo o rol…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoUsuario">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo usuario
        </button>
    </div>
</div>

<!-- Vista de listado (tabla) -->
<div id="vistaListado">
    <div class="tabla-wrap">
        <table class="table tabla">
            <thead>
                <tr>
                    <th>Nombre completo</th>
                    <th>Usuario</th>
                    <th>Rol</th>
                    <th>Correo</th>
                    <th>Telefono</th>
                    <th>Estado</th>
                    <th>Ultima sesion</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyUsuarios">
                <tr><td colspan="8" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- =====================================================================
     MODAL A - Alta de usuario (creacion).
     Campos: nombres, apellidos, username, correo, telefono, contrasena, rol.
     Sin "confirmar contrasena" ni "estado" (todo usuario nace Activo).
     ===================================================================== -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-hidden="true" aria-labelledby="modalUsuarioTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalUsuarioTitulo">Nuevo usuario</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formUsuarioError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formUsuario" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="fuNombres">Nombres *</label>
                            <input class="form-control" type="text" id="fuNombres" name="nombres" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuApellidos">Apellidos *</label>
                            <input class="form-control" type="text" id="fuApellidos" name="apellidos" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuUsername">Nombre de usuario *</label>
                            <input class="form-control" type="text" id="fuUsername" name="username" maxlength="50"
                                   placeholder="ej: jperez" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuCorreo">Correo *</label>
                            <input class="form-control" type="email" id="fuCorreo" name="correo" maxlength="100"
                                   placeholder="usuario@interacto.com" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuTelefono">Telefono</label>
                            <input class="form-control" type="text" id="fuTelefono" name="telefono" maxlength="50">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuRol">Rol *</label>
                            <select class="form-select" id="fuRol" name="rol_id" required>
                                <option value="">Cargando…</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="fuClave">Contrasena *</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="fuClave" name="contrasena"
                                       placeholder="Minimo 6 caracteres" required>
                                <button class="password-toggle" type="button" data-toggle-pass="#fuClave"
                                        aria-label="Mostrar u ocultar contrasena">Mostrar</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarUsuario">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Crear
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================================
     MODAL B - Edicion de usuario (alcance restringido).
     SOLO: nombres, apellidos, correo, rol. Sin username ni contrasenas.
     ===================================================================== -->
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-hidden="true" aria-labelledby="modalEditarUsuarioTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalEditarUsuarioTitulo">Editar usuario</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formEditarUsuarioError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formEditarUsuario" novalidate autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="feNombres">Nombres *</label>
                            <input class="form-control" type="text" id="feNombres" name="nombres" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="feApellidos">Apellidos *</label>
                            <input class="form-control" type="text" id="feApellidos" name="apellidos" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="feCorreo">Correo *</label>
                            <input class="form-control" type="email" id="feCorreo" name="correo" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="feRol">Rol *</label>
                            <select class="form-select" id="feRol" name="rol_id" required>
                                <option value="">Cargando…</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnActualizarUsuario">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar cambios
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================================
     MODAL C - Cambio de contrasena (independiente).
     ===================================================================== -->
<div class="modal fade" id="modalClaveUsuario" tabindex="-1" aria-hidden="true" aria-labelledby="modalClaveUsuarioTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalClaveUsuarioTitulo">Cambiar contrasena</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formClaveError" class="alert alert-danger d-none" role="alert"></div>
                <p class="form-panel__sub mb-3" id="claveUsuarioNombre"></p>

                <form id="formClave" novalidate autocomplete="off">
                    <div class="col-12">
                        <label class="form-label" for="fcNuevaClave">Nueva contrasena *</label>
                        <div class="password-field">
                            <input class="form-control" type="password" id="fcNuevaClave" name="contrasena"
                                   placeholder="Minimo 6 caracteres" required>
                            <button class="password-toggle" type="button" data-toggle-pass="#fcNuevaClave"
                                    aria-label="Mostrar u ocultar contrasena">Mostrar</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarClave">
                    <i class="bi bi-key" aria-hidden="true"></i> Cambiar contrasena
                </button>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../layout/app_footer.php';
