/* =====================================================================
   AXISTENCE - otros.js
   Modulo Otros productos (generico/extensible). Listado + formulario
   reutilizable (alta/edicion) con seleccion multiple de clientes (N:N) y
   un editor dinamico de atributos clave-valor, y vista de DETALLE
   (viewProducto) con pestañas + filtros. Espejo de assets/js/dominios.js.

   NOTA: la accion "Eliminar" queda solo maquetada; no se implementa borrado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyOtros");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm     = AX.modal("#modalOtro");
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { proveedores, clientes }
    var dropFechas = null;
    var msClientes = null;     // multiselect de clientes (general.js)

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
            url: "endpoints/otros/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los productos.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay productos registrados.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(o) {
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(o)) + '">' +
            "<td>" + AX.escaparHtml(o.tipo_producto) + "</td>" +
            "<td>" + AX.escaparHtml(o.nombre_referencia) + "</td>" +
            "<td>" + AX.escaparHtml(o.proveedor) + "</td>" +
            "<td>" + (o.fecha_vencimiento ? AX.formatearFecha(o.fecha_vencimiento) : "—") + "</td>" +
            '<td class="col-precio">' + money(o.precio_venta) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(o.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(o.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(o.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarOtro").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Opciones de los <select>
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#foProveedor").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/otros/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { proveedores: [], clientes: [] };
                $("#foProveedor").html('<option value="">Seleccione…</option>' +
                    (opciones.proveedores || []).map(function (p) {
                        return '<option value="' + AX.escaparHtml(p.id) + '">' + AX.escaparHtml(p.nombre_proveedor) + "</option>";
                    }).join(""));
                if (msClientes) {
                    msClientes.opciones((opciones.clientes || []).map(function (c) {
                        return { id: c.id, texto: c.nombre_razon_social };
                    }));
                }
                if (despues) { despues(); }
            } else {
                $("#foProveedor").html('<option value="">No se pudieron cargar los proveedores</option>');
            }
        }).fail(function () { $("#foProveedor").html('<option value="">Error al cargar</option>'); });
    }

    // ===============================================================
    //  Editor dinamico de atributos (clave-valor)
    // ===============================================================
    function filaAtributo(clave, valor) {
        return '<div class="atributo-row">' +
            '<input class="form-control" data-rol="clave" maxlength="100" placeholder="Campo" value="' + AX.escaparHtml(clave || "") + '">' +
            '<input class="form-control" data-rol="valor" placeholder="Valor" value="' + AX.escaparHtml(valor || "") + '">' +
            '<button type="button" class="btn-icono btn-icono--peligro" data-rol="quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>' +
        '</div>';
    }

    function setAtributos(lista) {
        var html = (lista || []).map(function (a) { return filaAtributo(a.campo_clave, a.valor); }).join("");
        $("#foAtributos").html(html);
    }

    function leerAtributos() {
        return $("#foAtributos .atributo-row").map(function () {
            var clave = $.trim($(this).find("[data-rol='clave']").val());
            var valor = $.trim($(this).find("[data-rol='valor']").val());
            return clave ? { campo_clave: clave, valor: valor } : null;
        }).get();
    }

    $("#btnAgregarAtributo").on("click", function () {
        $("#foAtributos").append(filaAtributo("", ""));
        $("#foAtributos .atributo-row:last-child [data-rol='clave']").trigger("focus");
    });
    $("#foAtributos").on("click", "[data-rol='quitar']", function () {
        $(this).closest(".atributo-row").remove();
    });

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, prod) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && prod) ? prod.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formOtro", "#formOtroError");
        $("#formOtroTitulo").text(esEditar ? "Editar producto" : "Nuevo producto");
        $("#btnGuardarOtro").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");
        setAtributos([]);

        cargarOpciones(function () {
            if (esEditar && prod) {
                $("#foTipo").val(prod.tipo_producto || "");
                $("#foReferencia").val(prod.nombre_referencia || "");
                $("#foProveedor").val(prod.proveedor_id || "");
                $("#foFechaRegistro").val((prod.fecha_registro || "").substring(0, 10));
                $("#foFechaVencimiento").val((prod.fecha_vencimiento || "").substring(0, 10));
                $("#foPrecioCompra").val(prod.precio_compra != null ? prod.precio_compra : "");
                $("#foPrecioVenta").val(prod.precio_venta != null ? prod.precio_venta : "");
                if (msClientes) { msClientes.seleccion((prod.clientes || []).map(function (c) { return c.id; })); }
                setAtributos(prod.atributos || []);
            } else {
                if (msClientes) { msClientes.seleccion([]); }
            }
        });

        modalForm.abrir();
        $("#foTipo").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formOtroError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var datos = {
            tipo_producto:     $.trim($("#foTipo").val()),
            nombre_referencia: $.trim($("#foReferencia").val()),
            proveedor_id:      $("#foProveedor").val(),
            fecha_registro:    $("#foFechaRegistro").val(),
            fecha_vencimiento: $("#foFechaVencimiento").val(),
            precio_compra:     $("#foPrecioCompra").val() === "" ? 0 : $("#foPrecioCompra").val(),
            precio_venta:      $("#foPrecioVenta").val() === "" ? 0 : $("#foPrecioVenta").val(),
            clientes:          msClientes ? msClientes.valores() : [],
            atributos:         leerAtributos()
        };

        if (!datos.tipo_producto || !datos.nombre_referencia || !datos.proveedor_id || !datos.fecha_registro) {
            return errorFormulario("Completa los campos obligatorios (tipo, referencia, proveedor y fecha de registro).");
        }

        var url = esEditar ? "endpoints/otros/actualizar.php" : "endpoints/otros/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarOtro").prop("disabled", true);
        $.ajax({
            url: url, method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "El producto se actualizó correctamente." : "El producto se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el producto.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el producto.");
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
            url: "endpoints/otros/ver.php", method: "GET", dataType: "json",
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
        var o = d.producto || {};
        $("#detTitulo").text(o.nombre_referencia || "—");
        $("#detTipo").text(o.tipo_producto || "Producto");
        $("#detTipoProducto").text(o.tipo_producto || "—");
        $("#detProveedor").text(o.proveedor || "—");
        $("#detRegistrado").text(AX.formatearFecha(o.fecha_registro));
        $("#detVence").text(o.fecha_vencimiento ? AX.formatearFecha(o.fecha_vencimiento) : "—");
        $("#detPrecioCompra").text(money(o.precio_compra));
        $("#detPrecioVenta").text(money(o.precio_venta));
        $("#detCreado").text(AX.formatearFecha(o.created_at));
        $("#detActualizado").text(AX.formatearFecha(o.updated_at));

        pintarSeccion($("#detClientes"), d.clientes, 3, function (c) {
            var badge = c.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
            return "<tr><td>" + AX.escaparHtml(c.nombre_razon_social) + "</td>" +
                   "<td>" + AX.escaparHtml((c.tipo_identificacion ? c.tipo_identificacion + " " : "") + (c.numero_identificacion || "")) + "</td>" +
                   '<td><span class="badge-estado ' + badge + '">' + AX.escaparHtml(c.estado) + "</span></td></tr>";
        });

        pintarSeccion($("#detAtributos"), d.atributos, 2, function (a) {
            return "<tr><td>" + AX.escaparHtml(a.campo_clave) + "</td>" +
                   "<td>" + AX.escaparHtml(a.valor) + "</td></tr>";
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
    $("#btnNuevoOtro").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarOtro", enviarFormulario);
    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);

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
            } else { AX.toast("Eliminación de productos: disponible próximamente.", "info"); }
            return;
        }
        if (reg) { abrirDetalle(reg.id); }
    });

    msClientes = AX.multiselect("#foClientes");
    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
