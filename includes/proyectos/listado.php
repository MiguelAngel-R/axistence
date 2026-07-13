<?php
/* ---------------------------------------------------------------------
   Modulo: Proyectos / Desarrollos - Vista principal (listado).
   Listado + formulario modal (info general + cliente opcional + equipo
   N:N + recursos N:N: dominios/vps/ssl/hosting) + vista de detalle
   (viewProyecto.php). El tablero Kanban se implementara despues.
   Espejo estructural de includes/hosting/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Proyectos';
$tituloSeccion = 'Proyectos';
$moduloActivo  = 'proyectos';

$cssPagina = ['assets/css/proyectos.css'];
$jsPagina  = ['assets/js/proyectos.js'];

// Estados del proyecto (enum public.estado_proyecto).
$estadosProyecto = [
    'Planificación', 'En Desarrollo', 'En Pruebas',
    'Entregado', 'Pausado', 'Cancelado',
];

// URL PUBLICA del server de sockets (tiempo real). El navegador la usa para
// conectarse; en produccion se sobreescribe por entorno (debe ser wss:// si el
// sitio va por HTTPS). Es distinta de la interna PHP->Node (SOCKETS_URL).
$socketsWsUrl = getenv('AXISTENCE_SOCKETS_URL_PUBLICA') ?: 'http://127.0.0.1:3002';

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarProyecto" class="form-control"
               placeholder="Buscar por proyecto o cliente…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoProyecto">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo proyecto
        </button>
    </div>
</div>

<!-- Vista de listado (tabla). data-ws = URL del server de sockets (tiempo real). -->
<div id="vistaListado" data-ws="<?php echo htmlspecialchars($socketsWsUrl); ?>">
    <div class="tabla-wrap">
        <table class="table tabla tabla--clic">
            <thead>
                <tr>
                    <th>Proyecto</th>
                    <th>Cliente</th>
                    <th>Estado</th>
                    <th>Inicio</th>
                    <th>Entrega estimada</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyProyecto">
                <tr><td colspan="6" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalProyecto" tabindex="-1" aria-hidden="true" aria-labelledby="formProyectoTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formProyectoTitulo">Nuevo proyecto</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formProyectoError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formProyecto" novalidate autocomplete="off">
            <div class="row g-4">
                <div class="col-md-8">
                    <label class="form-label" for="fpNombre">Nombre del proyecto *</label>
                    <input class="form-control" type="text" id="fpNombre" name="nombre_proyecto"
                           maxlength="200" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="fpEstado">Estado *</label>
                    <select class="form-select" id="fpEstado" name="estado" required>
                        <?php foreach ($estadosProyecto as $e): ?>
                            <option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fpCliente">Cliente asociado</label>
                    <select class="form-select" id="fpCliente" name="cliente_id">
                        <option value="">Sin cliente (proyecto interno)</option>
                    </select>
                    <div class="form-text">Opcional: dejar vacío para un proyecto interno.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fpInicio">Fecha de inicio *</label>
                    <input class="form-control" type="date" id="fpInicio" name="fecha_inicio" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fpEntrega">Entrega estimada</label>
                    <input class="form-control" type="date" id="fpEntrega" name="fecha_entrega_estimada">
                </div>

                <div class="col-12">
                    <label class="form-label" for="fpDescripcion">Descripción / alcance</label>
                    <textarea class="form-control" id="fpDescripcion" name="descripcion" rows="3"
                              maxlength="4000"></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">Equipo asignado</label>
                    <?php $msId = 'fpEquipo'; $msPlaceholder = 'Selecciona integrantes…'; $msBuscar = 'Buscar usuario…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fpVps">Servidor / VPS</label>
                    <select class="form-select" id="fpVps" name="vps_id">
                        <option value="">Sin servidor</option>
                    </select>
                    <div class="form-text">Un proyecto se aloja en un único servidor.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Dominios</label>
                    <?php $msId = 'fpDominios'; $msPlaceholder = 'Selecciona dominios…'; $msBuscar = 'Buscar dominio…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Hosting</label>
                    <?php $msId = 'fpHosting'; $msPlaceholder = 'Selecciona hostings…'; $msBuscar = 'Buscar hosting…'; require __DIR__ . '/../layout/multiselect.php'; ?>
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarProyecto">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vista de detalle (viewProyecto) -->
<?php require __DIR__ . '/viewProyecto.php'; ?>

<?php
require __DIR__ . '/../layout/app_footer.php';
