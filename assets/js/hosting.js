/* =====================================================================
   AXISTENCE - hosting.js
   Modulo Hosting (producto). Listado + formulario reutilizable con select
   de VPS y seleccion multiple de clientes (N:N) y dominios (N:N), y vista
   de DETALLE (viewProducto) con pestañas + filtros.
   Espejo de assets/js/dominios.js.

   NOTA: la accion "Eliminar" ejecuta un borrado real (confirmacion + endpoint
   eliminar.php + auditoria ELIMINAR).
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyHosting");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm     = AX.modal("#modalHosting");
    var COLUMNAS = 5;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { vps, clientes, dominios }
    var dropFechas = null;
    var msClientes = null;     // multiselect de clientes (general.js)
    var msDominios = null;     // multiselect de dominios (general.js)

    function money(v) {
        var n = parseFloat(v);
        if (isNaN(n)) { return "—"; }
        return "$" + n.toLocaleString("es-CO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' + AX.escaparHtml(mensaje) + "</td></tr>";
    }

    function pintarSeccion($cuerpo, items, columnas, filaFn) {
        if (!items || !items.length) {
            $cuerpo.html('<tr><td colspan="' + columnas + '" class="tabla-vacia">Sin registros.</td></tr>');
            return;
        }
        $cuerpo.html(items.map(filaFn).join(""));
    }

    function trFecha(valor) {
        var f = valor ? String(valor).substring(0, 10) : "";
        return '<tr data-fecha="' + AX.escaparHtml(f) + '">';
    }

    // ===============================================================
    //  Listado
    // ===============================================================
    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/hosting/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar el hosting.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay servicios de hosting registrados.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(h) {
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(h)) + '">' +
            "<td>" + AX.enlaceProducto("vps", h.vps_id, h.vps) + "</td>" +
            "<td>" + AX.escaparHtml(h.espacio_asignado) + "</td>" +
            "<td>" + AX.formatearFecha(h.fecha_renovacion) + "</td>" +
            '<td class="col-precio">' + money(h.precio_venta) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(h.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(h.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(h.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarHosting").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Opciones de los <select>
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#fhVps").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/hosting/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { vps: [], clientes: [], dominios: [] };
                $("#fhVps").html('<option value="">Seleccione…</option>' +
                    (opciones.vps || []).map(function (v) {
                        return '<option value="' + AX.escaparHtml(v.id) + '">' + AX.escaparHtml(v.referencia_vps) + "</option>";
                    }).join(""));
                if (msClientes) {
                    msClientes.opciones((opciones.clientes || []).map(function (c) {
                        return { id: c.id, texto: c.nombre_razon_social };
                    }));
                }
                if (msDominios) {
                    msDominios.opciones((opciones.dominios || []).map(function (d) {
                        return { id: d.id, texto: d.nombre_dominio };
                    }));
                }
                if (despues) { despues(); }
            } else {
                $("#fhVps").html('<option value="">No se pudieron cargar los servidores</option>');
            }
        }).fail(function () { $("#fhVps").html('<option value="">Error al cargar</option>'); });
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, host) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && host) ? host.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formHosting", "#formHostingError");
        vigHosting.refrescar();   // limpia la previsualizacion de la fecha de renovacion
        $("#formHostingTitulo").text(esEditar ? "Editar hosting" : "Nuevo hosting");
        $("#btnGuardarHosting").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        cargarOpciones(function () {
            if (esEditar && host) {
                $("#fhVps").val(host.vps_id || "");
                $("#fhEspacio").val(host.espacio_asignado || "");
                $("#fhFechaCompra").val((host.fecha_compra || "").substring(0, 10));
                vigHosting.refrescar();   // recalcula la fecha de renovacion desde la compra
                $("#fhPrecioCompra").val(host.precio_compra != null ? host.precio_compra : "");
                $("#fhPrecioVenta").val(host.precio_venta != null ? host.precio_venta : "");
                if (msClientes) { msClientes.seleccion((host.clientes || []).map(function (c) { return c.id; })); }
                if (msDominios) { msDominios.seleccion((host.dominios || []).map(function (d) { return d.id; })); }
            } else {
                if (msClientes) { msClientes.seleccion([]); }
                if (msDominios) { msDominios.seleccion([]); }
            }
        });

        modalForm.abrir();
        $("#fhVps").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formHostingError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var datos = {
            vps_id:           $("#fhVps").val(),
            espacio_asignado: $.trim($("#fhEspacio").val()),
            fecha_compra:     $("#fhFechaCompra").val(),
            // Fecha de renovacion automatica: compra + 1 año - 1 dia (no manipulable).
            fecha_renovacion: AX.calcularVigenciaAnual($("#fhFechaCompra").val()),
            precio_compra:    $("#fhPrecioCompra").val() === "" ? 0 : $("#fhPrecioCompra").val(),
            precio_venta:     $("#fhPrecioVenta").val() === "" ? 0 : $("#fhPrecioVenta").val(),
            clientes:         msClientes ? msClientes.valores() : [],
            dominios:         msDominios ? msDominios.valores() : []
        };

        if (!datos.vps_id || !datos.espacio_asignado || !datos.fecha_compra) {
            return errorFormulario("Completa los campos obligatorios (servidor, espacio y fecha de compra).");
        }

        var url = esEditar ? "endpoints/hosting/actualizar.php" : "endpoints/hosting/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarHosting").prop("disabled", true);
        $.ajax({
            url: url, method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "El hosting se actualizó correctamente." : "El hosting se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el hosting.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el hosting.");
            $btn.prop("disabled", false);
        });
    }

    // ===============================================================
    //  Detalle (viewProducto)
    // ===============================================================
    function tbodyActivo() { return $("#detTabsContent .tab-pane.active tbody"); }

    function aplicarFiltrosDetalle() {
        var f = dropFechas ? dropFechas.valores() : { desde: "", hasta: "" };
        AX.filtrarTabla(tbodyActivo(), { texto: $("#detBuscar").val(), desde: f.desde, hasta: f.hasta });
    }

    function reiniciarFiltrosDetalle() {
        $("#detBuscar").val("");
        if (dropFechas) { dropFechas.limpiar(); }
        var primera = document.querySelector('#detTabs [data-bs-toggle="tab"]');
        if (primera && window.bootstrap && bootstrap.Tab) { bootstrap.Tab.getOrCreateInstance(primera).show(); }
    }

    function inicializarFiltrosDetalle() {
        var t;
        $("#detBuscar").on("input", function () { clearTimeout(t); t = setTimeout(aplicarFiltrosDetalle, 200); });
        dropFechas = AX.dropdownFechas("#detFechas", { onAplicar: aplicarFiltrosDetalle, onLimpiar: aplicarFiltrosDetalle });
        $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", aplicarFiltrosDetalle);
    }

    function abrirDetalle(id) {
        detalleId = id;
        mostrar($vistaDetalle);
        reiniciarFiltrosDetalle();
        // Sin footer en el detalle: la flecha "Volver" es la unica accion de retorno.
        AX.limpiarFooter();
        window.scrollTo({ top: 0, behavior: "smooth" });

        $.ajax({
            url: "endpoints/hosting/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: id }
        }).done(function (res) {
            if (res && res.ok) { pintarDetalle(res.data); }
            else { AX.error((res && res.mensaje) || "No se pudo cargar el detalle."); volverAlListado(); }
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el detalle.");
            volverAlListado();
        });
    }

    function pintarDetalle(d) {
        var h = d.hosting || {};
        // El hosting no tiene nombre propio: el encabezado es fijo ("Hosting").
        // Los datos identificatorios van en la informacion general.
        $("#detVps").html(AX.enlaceProducto("vps", h.vps_id, h.vps));
        $("#detEspacio").text(h.espacio_asignado || "—");
        $("#detCompra").text(AX.formatearFecha(h.fecha_compra));
        $("#detRenueva").text(AX.formatearFecha(h.fecha_renovacion));
        $("#detPrecioCompra").text(money(h.precio_compra));
        $("#detPrecioVenta").text(money(h.precio_venta));
        $("#detCreado").text(AX.formatearFecha(h.created_at));
        $("#detActualizado").text(AX.formatearFecha(h.updated_at));

        pintarSeccion($("#detClientes"), d.clientes, 3, function (c) {
            var badge = c.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
            return "<tr><td>" + AX.escaparHtml(c.nombre_razon_social) + "</td>" +
                   "<td>" + AX.escaparHtml((c.tipo_identificacion ? c.tipo_identificacion + " " : "") + (c.numero_identificacion || "")) + "</td>" +
                   '<td><span class="badge-estado ' + badge + '">' + AX.escaparHtml(c.estado) + "</span></td></tr>";
        });

        pintarSeccion($("#detDominios"), d.dominios, 3, function (x) {
            return trFecha(x.fecha_vencimiento) + "<td>" + AX.enlaceProducto("dominios", x.id, x.nombre_dominio) + "</td>" +
                   "<td>" + AX.escaparHtml(x.proveedor) + "</td>" +
                   "<td>" + AX.formatearFecha(x.fecha_vencimiento) + "</td></tr>";
        });

        pintarSeccion($("#detProyectos"), d.proyectos, 3, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.nombre_proyecto) + "</td>" +
                   "<td>" + AX.escaparHtml(r.estado) + "</td>" +
                   "<td>" + AX.escaparHtml(r.descripcion_uso || "—") + "</td></tr>";
        });

        pintarSeccion($("#detNotas"), d.notas, 3, function (n) {
            return trFecha(n.fecha) + "<td>" + AX.formatearFecha(n.fecha) + "</td>" +
                   "<td>" + AX.escaparHtml(n.autor || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(n.nota) + "</td></tr>";
        });

        aplicarFiltrosDetalle();
    }

    // ===============================================================
    //  Navegacion entre vistas
    // ===============================================================
    function mostrar($vista) {
        $vistaListado.addClass("d-none");
        $vistaDetalle.addClass("d-none");
        $vista.removeClass("d-none");
        $(".toolbar").toggleClass("d-none", $vista[0] !== $vistaListado[0]);
    }

    // Tras guardar en el modal: cerrarlo y refrescar la vista de fondo.
    function trasGuardar() {
        modalForm.cerrar();
        if (detalleId) { abrirDetalle(detalleId); }
        else { cargar(); }
    }

    function volverAlListado() {
        mostrar($vistaListado);
        detalleId = null; editandoId = null;
        cargar();
    }

    // ===============================================================
    //  Eventos
    // ===============================================================
    $("#btnNuevoHosting").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarHosting", enviarFormulario);
    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);

    // Eliminacion real del hosting: confirma, llama al endpoint y recarga.
    function eliminarRegistro(reg) {
        var nombre = (reg.espacio_asignado || "este hosting") + (reg.vps ? " en " + reg.vps : "");
        AX.confirmar({
            titulo: "Eliminar hosting",
            texto: 'Se eliminará el hosting "' + nombre + '" y sus relaciones con clientes y dominios. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/hosting/eliminar.php", { id: reg.id }).then(function (res) {
                if (res.ok) { AX.exito(res.mensaje || "Hosting eliminado."); cargar(); }
                else { AX.error(res.mensaje || "No se pudo eliminar el hosting."); }
            }).catch(function () { AX.error("No se pudo eliminar el hosting."); });
        });
    }

    $tbody.on("click", "tr", function (e) {
        if (AX.esClicEnEnlace(e)) { return; }
        var $btn = $(e.target).closest("[data-accion]");
        var reg = AX.datosFila(this);
        if ($btn.length) {
            var accion = $btn.data("accion");
            if (accion === "ver") { if (reg) { abrirDetalle(reg.id); } }
            else if (accion === "editar") {
                if (reg) { abrirFormulario("editar", reg); }
                else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
            } else if (accion === "eliminar") {
                if (reg) { eliminarRegistro(reg); }
                else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
            }
            return;
        }
        if (reg) { abrirDetalle(reg.id); }
    });

    msClientes = AX.multiselect("#fhClientes");
    msDominios = AX.multiselect("#fhDominios");
    // Vigencia anual: la fecha de renovacion se calcula sola (compra + 1 año - 1
    // dia) y se muestra de solo lectura; ya no es manipulable por el usuario.
    var vigHosting = AX.vincularVigencia("#fhFechaCompra", "#fhFechaRenovacion");
    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
