<?php
/* ---------------------------------------------------------------------
   Modulo: Certificados SSL - Vista principal (listado).
   Producto con listado + formulario inline (incluye SUBIDA SEGURA del
   paquete de certificados) + vista de detalle (viewProducto.php).
   Espejo de includes/correo/listado.php.
   --------------------------------------------------------------------- */
$tituloPagina  = 'AXISTENCE - Certificados SSL';
$tituloSeccion = 'Certificados SSL';
$moduloActivo  = 'ssl';

$cssPagina = ['assets/css/ssl.css'];
$jsPagina  = ['assets/js/ssl.js'];

// URL PUBLICA del server de sockets (tiempo real). El navegador la usa para
// conectarse; en produccion se sobreescribe por entorno (debe ser wss:// si el
// sitio va por HTTPS). Es distinta de la interna PHP->Node (SOCKETS_URL).
$socketsWsUrl = getenv('AXISTENCE_SOCKETS_URL_PUBLICA') ?: 'http://127.0.0.1:3002';

require __DIR__ . '/../layout/app_header.php';
?>

<div class="toolbar">
    <div class="toolbar__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="buscarSsl" class="form-control"
               placeholder="Buscar por dominio o proveedor…" autocomplete="off">
    </div>
    <div class="toolbar__actions">
        <button type="button" class="btn btn-primary btn-sm" id="btnNuevoSsl">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo certificado
        </button>
    </div>
</div>

<!-- Vista de listado (tabla). data-ws = URL del server de sockets (tiempo real). -->
<div id="vistaListado" data-ws="<?php echo htmlspecialchars($socketsWsUrl); ?>">
    <div class="tabla-wrap">
        <table class="table tabla">
            <thead>
                <tr>
                    <th>Dominio protegido</th>
                    <th>Proveedor</th>
                    <th>Servidor</th>
                    <th>Vence</th>
                    <th class="col-precio">Precio venta</th>
                    <th class="tabla-acciones">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodySsl">
                <tr><td colspan="6" class="tabla-vacia">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de alta / edicion (dinamico: mismo modal para crear y editar) -->
<div class="modal fade" id="modalSsl" tabindex="-1" aria-hidden="true" aria-labelledby="formSslTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formSslTitulo">Nuevo certificado</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formSslError" class="alert alert-danger d-none" role="alert"></div>

                <form id="formSsl" novalidate autocomplete="off">
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label" for="fsDominio">Dominio que protege *</label>
                    <select class="form-select" id="fsDominio" name="dominio_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fsProveedor">Proveedor / certificadora *</label>
                    <select class="form-select" id="fsProveedor" name="proveedor_id" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fsVps">Servidor / VPS (del sistema)</label>
                    <select class="form-select" id="fsVps" name="vps_id">
                        <option value="">— Ninguno —</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="fsVpsExterna">Servidor externo</label>
                    <input class="form-control" type="text" id="fsVpsExterna" name="vps_externa"
                           maxlength="255" placeholder="Si no esta en el sistema">
                </div>

                <div class="col-12">
                    <label class="form-label" for="fsRuta">Ruta de almacenamiento en el servidor *</label>
                    <input class="form-control" type="text" id="fsRuta" name="ruta_almacenamiento"
                           placeholder="Ej: /etc/ssl/interacto/" required>
                </div>

                <div class="col-12">
                    <label class="form-label" for="fsArchivo">Paquete de certificados (.zip / .rar) <span id="fsArchivoObl">*</span></label>
                    <input class="form-control" type="file" id="fsArchivo" name="archivo" accept=".zip,.rar">
                    <div class="form-text" id="fsArchivoAyuda">Se almacena de forma segura; solo se descarga con permisos.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fsFechaRegistro">Fecha de registro *</label>
                    <input class="form-control" type="date" id="fsFechaRegistro" name="fecha_registro" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fsFechaVencimiento">Fecha de vencimiento</label>
                    <input class="form-control" type="date" id="fsFechaVencimiento" tabindex="-1" readonly>
                    <div class="form-text">Automática: registro + 1 año − 1 día.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fsPrecioCompra">Precio de compra</label>
                    <input class="form-control" type="number" id="fsPrecioCompra" name="precio_compra"
                           min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="fsPrecioVenta">Precio de venta</label>
                    <input class="form-control" type="number" id="fsPrecioVenta" name="precio_venta"
                           min="0" step="0.01" placeholder="0.00">
                </div>
            </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarSsl">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear</span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../layout/app_footer.php';
