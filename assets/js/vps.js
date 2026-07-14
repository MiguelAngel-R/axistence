/* =====================================================================
   AXISTENCE - vps.js
   Logica del modulo VPS / Servidores (primer modulo de "Productos").
   - Listado por AJAX + paginador (AX).
   - Formulario reutilizable (alta/edicion) con selects de proveedor y
     seleccion multiple de clientes (N:N).
   - Vista de DETALLE (viewProducto): al hacer clic en una fila carga y
     muestra toda la informacion relacionada del VPS.
   Espejo de assets/js/proveedores.js (mismo patron).

   NOTA: la accion "Eliminar" ejecuta un borrado real (confirmacion + endpoint
   eliminar.php + auditoria ELIMINAR); 409 si tiene hosting asociado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var socketActivo = false;   // true cuando el socket de tiempo real esta conectado
    var $tbody = $("#tbodyVps");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm     = AX.modal("#modalVps");
    var COLUMNAS = 8;
    var modo = "crear";        // "crear" | "editar"
    var editandoId = null;     // id del VPS en edicion
    var detalleId = null;      // id del VPS mostrado en el detalle
    var opciones = null;       // { proveedores } cacheadas
    var dropFechas = null;     // controlador del dropdown de fechas (general.js)
    var cbRef = null;          // combobox de referencia (general.js)
    var refsSpec = {};         // mapa id -> specs de la referencia (para heredar/mostrar)
    var modalRef = AX.modal("#modalNuevaReferenciaVps"); // modal anidado (nueva referencia)

    // ---------------------------------------------------------------
    //  Utilidades locales
    // ---------------------------------------------------------------
    // Formatea un valor monetario con su divisa (COP -> "$", USD -> "US$").
    function money(v, moneda) {
        var n = parseFloat(v);
        if (isNaN(n)) { return "—"; }
        var s = n.toLocaleString("es-CO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (moneda === "USD" ? "US$ " : "$ ") + s;
    }

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' +
               AX.escaparHtml(mensaje) + "</td></tr>";
    }

    // Rellena un <tbody> del detalle; si no hay items, muestra un vacio.
    function pintarSeccion($cuerpo, items, columnas, filaFn) {
        if (!items || !items.length) {
            $cuerpo.html('<tr><td colspan="' + columnas + '" class="tabla-vacia">Sin registros.</td></tr>');
            return;
        }
        $cuerpo.html(items.map(filaFn).join(""));
    }

    // ===============================================================
    //  Listado
    // ===============================================================
    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/vps/listar.php",
            method: "GET",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: {
                pagina: estado.pagina,
                por_pagina: estado.porPagina,
                buscar: estado.buscar
            }
        }).done(function (res) {
            if (!res || !res.ok) {
                $tbody.html(filaVacia("No se pudo cargar la lista."));
                return;
            }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los VPS.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];

        if (!items.length) {
            $tbody.html(filaVacia("No hay VPS registrados."));
        } else {
            $tbody.html(items.map(fila).join(""));
        }

        AX.renderPaginador({
            pagina: data.pagina,
            totalPaginas: data.total_paginas,
            total: data.total,
            porPagina: data.por_pagina,
            onCambio: function (p) {
                estado.pagina = p;
                cargar();
                window.scrollTo({ top: 0, behavior: "smooth" });
            },
            onPorPagina: function (n) {
                estado.porPagina = n;
                estado.pagina = 1;
                cargar();
            }
        });
    }

    function fila(v) {
        // Hardware, precio y tipo se heredan de la referencia (join en el backend).
        var claseAso = v.tipo_servidor === "Dedicado" ? "tipo-badge--dedicado" : "tipo-badge--compartido";
        // Los datos de la fila quedan embebidos para editar/ver sin reconsultar.
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(v)) + '">' +
            "<td>" + AX.escaparHtml(v.referencia_vps) + "</td>" +
            "<td>" + (v.label ? AX.escaparHtml(v.label) : '<span class="text-muted">—</span>') + "</td>" +
            "<td>" + AX.escaparHtml(v.proveedor) + "</td>" +
            '<td><span class="tipo-badge ' + claseAso + '">' + AX.escaparHtml(v.tipo_servidor) + "</span></td>" +
            "<td>" + AX.escaparHtml(AX.formatearMedida(v.disco_valor, v.disco_unidad) || "—") + "</td>" +
            "<td>" + AX.escaparHtml(AX.formatearMedida(v.ram_valor, v.ram_unidad) || "—") + "</td>" +
            '<td class="col-precio">' + money(v.precio_venta, v.moneda_venta) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(v.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(v.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(v.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    // --- Busqueda con retardo (debounce) ---------------------------
    var temporizador;
    $("#buscarVps").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            estado.buscar = valor.trim();
            estado.pagina = 1;
            cargar();
        }, 350);
    });

    // ===============================================================
    //  Opciones de los <select> (proveedores + clientes)
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) {
            if (despues) { despues(); }
            return;
        }
        $("#fvProveedor").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/vps/opciones.php",
            method: "GET",
            dataType: "json",
            xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { proveedores: [] };
                var provOpts = '<option value="">Seleccione…</option>' +
                    (opciones.proveedores || []).map(function (p) {
                        return '<option value="' + AX.escaparHtml(p.id) + '">' + AX.escaparHtml(p.nombre_proveedor) + "</option>";
                    }).join("");
                $("#fvProveedor").html(provOpts);

                if (despues) { despues(); }
            } else {
                $("#fvProveedor").html('<option value="">No se pudieron cargar los proveedores</option>');
            }
        }).fail(function () {
            $("#fvProveedor").html('<option value="">Error al cargar los proveedores</option>');
        });
    }

    // Carga en el combobox las referencias del proveedor indicado. Cada
    // referencia trae sus specs completas (hardware, precios, tipo) que se
    // guardan en refsSpec para poder heredarlas / mostrarlas al elegirla.
    // Si no hay proveedor, deja el combobox vacio. 'despues' se invoca al terminar.
    function cargarReferencias(proveedorId, despues) {
        if (!cbRef) { if (despues) { despues(); } return; }
        refsSpec = {};
        ocultarResumenRef();
        if (!proveedorId) {
            cbRef.opciones([]); cbRef.limpiar();
            if (despues) { despues(); }
            return;
        }
        $.ajax({
            url: "endpoints/vps/referencias_listar.php",
            method: "GET", dataType: "json", xhrFields: { withCredentials: true },
            data: { proveedor_id: proveedorId }
        }).done(function (res) {
            var lista = (res && res.ok && res.data) ? res.data : [];
            lista.forEach(function (r) { refsSpec[r.id] = r; });
            cbRef.opciones(lista.map(function (r) { return { id: r.id, texto: r.nombre }; }));
            if (despues) { despues(); }
        }).fail(function () {
            cbRef.opciones([]);
            if (despues) { despues(); }
        });
    }

    // Muestra (solo lectura) el resumen de specs que la VPS heredara de la
    // referencia elegida. 'spec' es el objeto de refsSpec, o null para ocultarlo.
    function mostrarResumenRef(spec) {
        var $box = $("#fvRefResumen");
        if (!spec) { ocultarResumenRef(); return; }
        var disco = AX.formatearMedida(spec.disco_valor, spec.disco_unidad) || "—";
        var ram   = AX.formatearMedida(spec.ram_valor, spec.ram_unidad) || "—";
        var ab    = AX.formatearMedida(spec.ancho_banda_valor, spec.ancho_banda_unidad) || "—";
        $box.html(
            '<span class="ref-vps-resumen__item"><strong>Tipo:</strong> ' + AX.escaparHtml(spec.tipo_servidor || "—") + "</span>" +
            '<span class="ref-vps-resumen__item"><strong>Disco:</strong> ' + AX.escaparHtml(disco) + "</span>" +
            '<span class="ref-vps-resumen__item"><strong>RAM:</strong> ' + AX.escaparHtml(ram) + "</span>" +
            '<span class="ref-vps-resumen__item"><strong>Ancho de banda:</strong> ' + AX.escaparHtml(ab) + "</span>" +
            '<span class="ref-vps-resumen__item"><strong>Venta:</strong> ' + money(spec.precio_venta, spec.moneda_venta) + "</span>"
        ).removeClass("d-none");
    }

    function ocultarResumenRef() {
        $("#fvRefResumen").addClass("d-none").empty();
    }

    // Refresca el resumen segun la referencia actualmente elegida en el combobox.
    function refrescarResumenRef() {
        var ref = cbRef ? cbRef.valor() : { id: null };
        mostrarResumenRef(ref.id && refsSpec[ref.id] ? refsSpec[ref.id] : null);
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, vps) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && vps) ? vps.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formVps", "#formVpsError");
        $("#formVpsTitulo").text(esEditar ? "Editar VPS" : "Nuevo VPS");
        $("#btnGuardarVps").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        // Reset de los campos propios del VPS (no cubiertos por limpiarFormulario).
        $("#fvLabel").val("");
        $("#fvCreacion").val("");
        ocultarResumenRef();

        // Poblar selects y, cuando esten listos, precargar valores en edicion.
        cargarOpciones(function () {
            if (esEditar && vps) {
                $("#fvProveedor").val(vps.proveedor_id || "");
                $("#fvLabel").val(vps.label || "");
                var creacion = vps.fecha_creacion ? String(vps.fecha_creacion).substring(0, 10) : "";
                $("#fvCreacion").val(creacion);
                // Referencias del proveedor -> combobox -> preselecciona la del VPS.
                cargarReferencias(vps.proveedor_id, function () {
                    if (cbRef) { cbRef.seleccionarTexto(vps.referencia_vps || ""); }
                    refrescarResumenRef();
                });
            } else {
                if (cbRef) { cbRef.opciones([]); cbRef.limpiar(); }
                // Por comodidad, la fecha de creacion arranca en hoy.
                $("#fvCreacion").val(fechaHoyIso());
            }
        });

        modalForm.abrir();
        $("#fvProveedor").trigger("focus");
    }

    // Fecha de hoy en formato yyyy-mm-dd (para el input date).
    function fechaHoyIso() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formVpsError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var ref = cbRef ? cbRef.valor() : { texto: "", id: null };
        // Formulario simplificado: proveedor, referencia (aporta specs+costos),
        // etiqueta y fecha de creacion. El vencimiento lo calcula el backend.
        var datos = {
            proveedor_id:      $("#fvProveedor").val(),
            referencia_vps:    ref.texto,
            referencia_vps_id: ref.id,   // opcional: el backend tambien resuelve por texto
            label:             $.trim($("#fvLabel").val()),
            fecha_creacion:    $("#fvCreacion").val()
        };

        if (!datos.proveedor_id) {
            return errorFormulario("Selecciona un proveedor.");
        }
        if (!datos.referencia_vps) {
            return errorFormulario("Selecciona o crea una referencia de VPS.");
        }
        if (!datos.fecha_creacion) {
            return errorFormulario("Indica la fecha de creación.");
        }

        var url = esEditar ? "endpoints/vps/actualizar.php" : "endpoints/vps/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarVps").prop("disabled", true);
        $.ajax({
            url: url,
            method: "POST",
            contentType: "application/json",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "El VPS se actualizó correctamente." : "El VPS se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el VPS.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el VPS.");
            $btn.prop("disabled", false);
        });
    }

    // ===============================================================
    //  Detalle (viewProducto)
    // ===============================================================
    // Devuelve el <tbody> de la tabla de la pestana activa del detalle. Se filtra
    // por :visible para soportar subtabs anidados (Inventario): asi solo se toma
    // la tabla realmente visible y no las de subpanes ocultos.
    function tbodyActivo() {
        return $("#detTabsContent .tab-pane.active tbody:visible");
    }

    // Aplica el buscador dinamico + rango de fechas a la tabla activa.
    function aplicarFiltrosDetalle() {
        var f = dropFechas ? dropFechas.valores() : { desde: "", hasta: "" };
        AX.filtrarTabla(tbodyActivo(), {
            texto: $("#detBuscar").val(),
            desde: f.desde,
            hasta: f.hasta
        });
    }

    // Reinicia los filtros y vuelve a la primera pestana (al abrir un detalle).
    function reiniciarFiltrosDetalle() {
        $("#detBuscar").val("");
        if (dropFechas) { dropFechas.limpiar(); }
        var primera = document.querySelector('#detTabs [data-bs-toggle="tab"]');
        if (primera && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(primera).show();
        }
    }

    // Cablea (una sola vez) el buscador, el dropdown de fechas y el cambio
    // de pestana. Reutiliza las utilidades de general.js.
    function inicializarFiltrosDetalle() {
        var t;
        $("#detBuscar").on("input", function () {
            clearTimeout(t);
            t = setTimeout(aplicarFiltrosDetalle, 200);
        });
        dropFechas = AX.dropdownFechas("#detFechas", {
            onAplicar: aplicarFiltrosDetalle,
            onLimpiar: aplicarFiltrosDetalle
        });
        // Al cambiar de pestana (o de subtab del inventario), se re-aplican los
        // filtros a la tabla que quede visible.
        $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", aplicarFiltrosDetalle);
        $('#invSubtabs [data-bs-toggle="tab"]').on("shown.bs.tab", aplicarFiltrosDetalle);
    }

    function abrirDetalle(id) {
        detalleId = id;
        // Exponer el id del VPS actual para modulos hermanos del detalle
        // (p. ej. vps_consola.js lee #vistaDetalle[data-vps-id]).
        $vistaDetalle.attr("data-vps-id", id);
        mostrar($vistaDetalle);
        reiniciarFiltrosDetalle();
        cargarDetalleOpciones(id);   // opciones para los modales "Agregar ..."
        // Sin footer en el detalle: la unica accion de retorno es la flecha
        // "Volver" junto al titulo (ver viewProducto). Se oculta el footer.
        AX.limpiarFooter();
        window.scrollTo({ top: 0, behavior: "smooth" });

        $.ajax({
            url: "endpoints/vps/ver.php",
            method: "GET",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: { id: id }
        }).done(function (res) {
            if (res && res.ok) {
                pintarDetalle(res.data);
            } else {
                AX.error((res && res.mensaje) || "No se pudo cargar el detalle del VPS.");
                volverAlListado();
            }
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el detalle del VPS.");
            volverAlListado();
        });
    }

    function pintarDetalle(d) {
        var v = d.vps || {};

        // Encabezado + datos generales. El hardware/precio/tipo se hereda de la
        // referencia (el backend los devuelve ya unidos al VPS).
        var titulo = v.referencia_vps || "—";
        if (v.label) { titulo += " · " + v.label; }
        $("#detTitulo").text(titulo);
        var claseAso = v.tipo_servidor === "Dedicado" ? "tipo-badge--dedicado" : "tipo-badge--compartido";
        $("#detAsociacion").text(v.tipo_servidor || "—").attr("class", "tipo-badge " + claseAso);

        $("#detProveedor").text(v.proveedor || "—");
        $("#detEtiqueta").text(v.label || "—");
        $("#detTipoAsociacion").text(v.tipo_servidor || "—");
        $("#detDisco").text(AX.formatearMedida(v.disco_valor, v.disco_unidad) || "—");
        $("#detRam").text(AX.formatearMedida(v.ram_valor, v.ram_unidad) || "—");
        $("#detAnchoBanda").text(AX.formatearMedida(v.ancho_banda_valor, v.ancho_banda_unidad) || "—");
        $("#detPrecioCompra").text(money(v.precio_compra, v.moneda_compra));
        $("#detPrecioVenta").text(money(v.precio_venta, v.moneda_venta));
        $("#detFechaCreacion").text(v.fecha_creacion ? AX.formatearFecha(v.fecha_creacion) : "—");
        $("#detCreado").text(AX.formatearFecha(v.created_at));
        $("#detActualizado").text(AX.formatearFecha(v.updated_at));

        // --- Dashboard superior (estado del VPS) --------------------
        pintarDashboard(v);

        // Dominios (enlace cruzado + proveedor y precio de venta).
        pintarSeccion($("#detDominios"), d.dominios, 4, filaDominioDetalle);

        // Certificados SSL (enlace cruzado + proveedor).
        pintarSeccion($("#detCertificados"), d.certificados, 3, filaCertificadoDetalle);

        // Proyectos.
        pintarSeccion($("#detProyectos"), d.proyectos, 3, function (x) {
            return "<tr><td>" + AX.escaparHtml(x.nombre_proyecto) + "</td>" +
                   "<td>" + AX.escaparHtml(x.estado) + "</td>" +
                   "<td>" + AX.escaparHtml(x.descripcion_uso || "—") + "</td></tr>";
        });

        // Inventario Logico (software / configuracion instalada).
        pintarSeccion($("#detHistorial"), d.historial, 5, filaInventarioDetalle);

        // Virtual Hosts.
        pintarSeccion($("#detVirtualHosts"), d.virtual_hosts, 7, filaVirtualHostDetalle);

        // Historial (logs de auditoria especificos de este VPS).
        pintarSeccion($("#detLogs"), d.logs, 5, filaHistorialDetalle);

        // Notas (con criticidad visual): la fila completa se tinta segun la
        // prioridad (Advertencia / Importante / Crítica; Información sin tinte).
        pintarSeccion($("#detNotas"), d.notas, 4, filaNotaDetalle);

        // Tras (re)pintar, se aplican los filtros vigentes a la tabla activa.
        aplicarFiltrosDetalle();
    }

    // Dashboard: vencimiento, dias restantes (con alerta de color), ultima
    // actualizacion, tipo de servidor y proveedor.
    function pintarDashboard(v) {
        $("#dashVence").text(v.fecha_vencimiento ? AX.formatearFecha(v.fecha_vencimiento) : "Sin fecha");
        $("#dashActualizado").text(AX.formatearFecha(v.updated_at) || "—");
        $("#dashTipo").text(v.tipo_servidor || "—");
        $("#dashProveedor").text(v.proveedor || "—");

        var $dias = $("#dashDias").attr("class", "dash-badge");
        if (!v.fecha_vencimiento) {
            $dias.addClass("dash-badge--none").text("Sin fecha");
            return;
        }
        var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        var vence = new Date(String(v.fecha_vencimiento).substring(0, 10) + "T00:00:00");
        var dias = Math.round((vence - hoy) / 86400000);
        var clase, texto;
        if (dias < 0)        { clase = "dash-badge--danger"; texto = "Vencido hace " + Math.abs(dias) + " día(s)"; }
        else if (dias === 0) { clase = "dash-badge--danger"; texto = "Vence hoy"; }
        else if (dias <= 7)  { clase = "dash-badge--danger"; texto = dias + " día(s)"; }
        else if (dias <= 30) { clase = "dash-badge--warn";   texto = dias + " día(s)"; }
        else                 { clase = "dash-badge--ok";     texto = dias + " día(s)"; }
        $dias.addClass(clase).text(texto);
    }

    // Mapa criticidad -> sufijo de clase CSS (compartido por el badge y la fila).
    var CRIT_CLASES = { "Información": "informacion", "Advertencia": "advertencia", "Importante": "importante", "Crítica": "critica" };

    // Badge de criticidad de una nota (Información / Advertencia / Importante / Crítica).
    function critBadge(criticidad) {
        var cls = CRIT_CLASES[criticidad] || "informacion";
        return '<span class="crit-badge crit-badge--' + cls + '">' + AX.escaparHtml(criticidad || "Información") + "</span>";
    }

    // Atributo class para la fila de una nota segun su criticidad. Devuelve ""
    // para "Información" (sin tinte) o ' class="nota-row--<crit>"' en el resto.
    function critClaseFila(criticidad) {
        var cls = CRIT_CLASES[criticidad] || "informacion";
        if (cls === "informacion") { return ""; }
        return ' class="nota-row--' + cls + '"';
    }

    // Refresca el detalle SIN reiniciar la pestaña activa ni los filtros (para
    // usar tras agregar un activo desde un tab: la grilla se actualiza en sitio).
    function refrescarDetalle() {
        if (!detalleId) { return; }
        $.ajax({
            url: "endpoints/vps/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: detalleId }
        }).done(function (res) {
            if (res && res.ok) { pintarDetalle(res.data); }
        });
    }

    // Carga en los <select> de los modales relacionales las opciones de este
    // VPS (proveedores, dominios y certificados) desde detalle_opciones.php.
    function cargarDetalleOpciones(vpsId) {
        $.ajax({
            url: "endpoints/vps/detalle_opciones.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { vps_id: vpsId }
        }).done(function (res) {
            if (!res || !res.ok) { return; }
            var o = res.data || {};

            var optProv = '<option value="">Seleccione…</option>' +
                (o.proveedores || []).map(function (p) {
                    return '<option value="' + AX.escaparHtml(p.id) + '">' + AX.escaparHtml(p.nombre_proveedor) + "</option>";
                }).join("");
            $("#adProveedor, #asProveedor").html(optProv);

            $("#asDominio").html('<option value="">Seleccione…</option>' +
                (o.dominios || []).map(function (d) {
                    return '<option value="' + AX.escaparHtml(d.id) + '">' + AX.escaparHtml(d.nombre_dominio) + "</option>";
                }).join(""));

            $("#vhDominio").html('<option value="">— Ninguno —</option>' +
                (o.dominios_vps || []).map(function (d) {
                    return '<option value="' + AX.escaparHtml(d.id) + '">' + AX.escaparHtml(d.nombre_dominio) + "</option>";
                }).join(""));

            $("#vhCertificado").html('<option value="">— Ninguno —</option>' +
                (o.certificados_vps || []).map(function (c) {
                    return '<option value="' + AX.escaparHtml(c.id) + '">SSL: ' + AX.escaparHtml(c.nombre_dominio) + "</option>";
                }).join(""));
        });
    }

    // ===============================================================
    //  Modales "Agregar ..." de los tabs (creacion relacional)
    // ===============================================================
    var modalAddNota       = AX.modal("#modalAddNota");
    var modalAddInventario = AX.modal("#modalAddInventario");
    var modalAddVhost      = AX.modal("#modalAddVirtualHost");
    var modalAddDominio    = AX.modal("#modalAddDominio");
    var modalAddSsl        = AX.modal("#modalAddSsl");

    // Guardado relacional generico: envia por Fetch, cierra el modal, avisa y
    // refresca el detalle en sitio (la grilla del tab se actualiza sin recargar).
    function guardarRelacional(url, datos, modalCtrl, cajaError, $btn, exitoMsg) {
        $btn.prop("disabled", true);
        AX.enviarJSON(url, datos).then(function (res) {
            if (res.ok) {
                modalCtrl.cerrar();
                AX.exito(exitoMsg);
                refrescarDetalle();
                cargarDetalleOpciones(detalleId); // por si cambio la lista de dominios/certificados
            } else {
                AX.errorFormulario(cajaError, res.mensaje || "No se pudo guardar.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario(cajaError, "Error de conexión.");
            $btn.prop("disabled", false);
        });
    }

    // --- Botones "Agregar" de cada tab ------------------------------
    // Proyectos: por pedido, el boton existe pero sin funcionalidad todavia.
    $("#btnAddProyecto").on("click", function () {
        AX.toast("Creación de proyectos: disponible próximamente.", "info");
    });
    $("#btnAddNota").on("click", function () {
        AX.limpiarFormulario("#formAddNota", "#formAddNotaError");
        $("#btnGuardarAddNota").prop("disabled", false);
        modalAddNota.abrir();
    });
    $("#btnAddInventario").on("click", function () {
        AX.limpiarFormulario("#formAddInventario", "#formAddInventarioError");
        $("#btnGuardarAddInventario").prop("disabled", false);
        modalAddInventario.abrir();
    });
    $("#btnAddVirtualHost").on("click", function () {
        AX.limpiarFormulario("#formAddVhost", "#formAddVhostError");
        $("#btnGuardarAddVhost").prop("disabled", false);
        modalAddVhost.abrir();
    });
    $("#btnAddDominio").on("click", function () {
        AX.limpiarFormulario("#formAddDominio", "#formAddDominioError");
        vigAddDominio.refrescar();   // reinicia la previsualizacion de la fecha final
        $("#btnGuardarAddDominio").prop("disabled", false);
        modalAddDominio.abrir();
    });
    $("#btnAddSsl").on("click", function () {
        AX.limpiarFormulario("#formAddSsl", "#formAddSslError");
        vigAddSsl.refrescar();
        $("#btnGuardarAddSsl").prop("disabled", false);
        modalAddSsl.abrir();
    });

    // --- Guardado de cada modal -------------------------------------
    $("#btnGuardarAddNota").on("click", function () {
        var nota = $.trim($("#anNota").val());
        if (!nota) { return AX.errorFormulario("#formAddNotaError", "Escribe la nota."); }
        guardarRelacional("endpoints/vps/agregar_nota.php",
            { vps_id: detalleId, nota: nota, criticidad: $("#anCriticidad").val() },
            modalAddNota, "#formAddNotaError", $("#btnGuardarAddNota"), "Nota agregada correctamente.");
    });

    $("#btnGuardarAddInventario").on("click", function () {
        var software = $.trim($("#aiSoftware").val());
        if (!software) { return AX.errorFormulario("#formAddInventarioError", "Indica el software / componente."); }
        guardarRelacional("endpoints/vps/agregar_inventario.php", {
            vps_id: detalleId,
            tipo_evento: $("#aiTipo").val(),
            software_componente: software,
            version: $.trim($("#aiVersion").val()),
            ruta_directorio_instalacion: $.trim($("#aiRuta").val()),
            puertos_usados: $.trim($("#aiPuertos").val()),
            servicios_rutas_acceso: $.trim($("#aiServicios").val()),
            notas: $.trim($("#aiNotas").val())
        }, modalAddInventario, "#formAddInventarioError", $("#btnGuardarAddInventario"), "Registro agregado al inventario lógico.");
    });

    $("#btnGuardarAddVhost").on("click", function () {
        var app = $.trim($("#vhApp").val());
        var sn  = $.trim($("#vhServerName").val());
        if (!app || !sn) { return AX.errorFormulario("#formAddVhostError", "La aplicación y el ServerName son obligatorios."); }
        guardarRelacional("endpoints/vps/agregar_virtualhost.php", {
            vps_id: detalleId,
            aplicacion: app,
            server_name: sn,
            server_alias: $.trim($("#vhServerAlias").val()),
            servidor_web: $("#vhServidorWeb").val(),
            puerto: $("#vhPuerto").val() || 80,
            dominio_id: $("#vhDominio").val(),
            certificado_id: $("#vhCertificado").val(),
            document_root: $.trim($("#vhDocRoot").val()),
            proxy_pass: $.trim($("#vhProxyPass").val()),
            puerto_aplicacion: $("#vhPuertoApp").val(),
            ruta_configuracion: $.trim($("#vhRutaConfig").val()),
            ssl_habilitado: $("#vhSsl").is(":checked"),
            estado: $("#vhEstado").val(),
            notas: $.trim($("#vhNotas").val())
        }, modalAddVhost, "#formAddVhostError", $("#btnGuardarAddVhost"), "Virtual Host agregado correctamente.");
    });

    $("#btnGuardarAddDominio").on("click", function () {
        var nombre = $.trim($("#adNombre").val());
        if (!nombre || !$("#adProveedor").val() || !$("#adFechaReg").val()) {
            return AX.errorFormulario("#formAddDominioError", "Completa dominio, proveedor y fecha de registro.");
        }
        guardarRelacional("endpoints/vps/agregar_dominio.php", {
            vps_id: detalleId,
            nombre_dominio: nombre,
            proveedor_id: $("#adProveedor").val(),
            fecha_registro: $("#adFechaReg").val(),
            // Fecha final automatica: registro + 1 año - 1 dia (no manipulable).
            fecha_vencimiento: AX.calcularVigenciaAnual($("#adFechaReg").val()),
            precio_compra: $("#adPrecioC").val() === "" ? 0 : $("#adPrecioC").val(),
            precio_venta: $("#adPrecioV").val() === "" ? 0 : $("#adPrecioV").val()
        }, modalAddDominio, "#formAddDominioError", $("#btnGuardarAddDominio"), "Dominio agregado correctamente.");
    });

    $("#btnGuardarAddSsl").on("click", function () {
        var dominio   = $("#asDominio").val();
        var proveedor = $("#asProveedor").val();
        var ruta      = $.trim($("#asRuta").val());
        if (!dominio || !proveedor || !ruta || !$("#asFechaReg").val()) {
            return AX.errorFormulario("#formAddSslError", "Completa dominio, proveedor, ruta y fecha de registro.");
        }
        var archivo = document.getElementById("asArchivo").files[0];
        if (!archivo) { return AX.errorFormulario("#formAddSslError", "Sube el paquete de certificados (.zip o .rar)."); }

        // Primero se sube el paquete de forma segura; con su ruta se crea el SSL.
        var $btn = $("#btnGuardarAddSsl").prop("disabled", true);
        var fd = new FormData();
        fd.append("archivo", archivo);
        $.ajax({
            url: "endpoints/ssl/subir.php", method: "POST", data: fd,
            processData: false, contentType: false, dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (up) {
            if (up && up.ok && up.data && up.data.ruta) {
                guardarRelacional("endpoints/vps/agregar_ssl.php", {
                    vps_id: detalleId,
                    dominio_id: dominio,
                    proveedor_id: proveedor,
                    ruta_almacenamiento: ruta,
                    archivo_paquete_path: up.data.ruta,
                    fecha_registro: $("#asFechaReg").val(),
                    // Fecha final automatica: registro + 1 año - 1 dia (no manipulable).
                    fecha_vencimiento: AX.calcularVigenciaAnual($("#asFechaReg").val()),
                    precio_compra: $("#asPrecioC").val() === "" ? 0 : $("#asPrecioC").val(),
                    precio_venta: $("#asPrecioV").val() === "" ? 0 : $("#asPrecioV").val()
                }, modalAddSsl, "#formAddSslError", $btn, "Certificado SSL agregado correctamente.");
            } else {
                AX.errorFormulario("#formAddSslError", (up && up.mensaje) || "No se pudo subir el paquete.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formAddSslError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo subir el paquete.");
            $btn.prop("disabled", false);
        });
    });

    // ===============================================================
    //  Tiempo real (Socket.IO)
    //  El listado se actualiza en vivo cuando se crea, edita o elimina un VPS
    //  (por cualquier usuario, incluido uno mismo), sin recargar la tabla ni
    //  volver a consultar la BD: el evento ya trae los datos de la fila. La
    //  escritura sigue yendo por HTTP a los endpoints; el socket solo REPARTE
    //  los cambios que PHP confirma tras guardar. Reutiliza el cliente Socket.IO
    //  ya cargado para la consola SSH del detalle (mismo server unificado).
    // ===============================================================

    // Localiza la fila del listado cuyo registro embebido tiene ese id.
    function filaPorId(id) {
        return $tbody.find("tr").filter(function () {
            var d = AX.datosFila(this);
            return d && d.id === id;
        });
    }

    // Alta: inserta la fila en su posicion alfabetica (el listado va ordenado
    // por referencia). Solo aplica en la primera pagina y sin busqueda activa;
    // en otro caso la fila aparecera de forma natural al navegar/filtrar.
    function socketVpsCreado(v) {
        if (!v || !v.id) { return; }
        if (estado.pagina !== 1 || estado.buscar !== "") { return; }
        if (filaPorId(v.id).length) { return; }              // evita duplicar
        $tbody.find(".tabla-vacia").closest("tr").remove();  // quita el placeholder "vacio"
        var $nueva = $(fila(v));
        var ref = v.referencia_vps || "";
        var insertado = false;
        $tbody.find("tr").each(function () {
            var d = AX.datosFila(this);
            if (d && ref.localeCompare(d.referencia_vps || "", "es", { sensitivity: "base" }) < 0) {
                $nueva.insertBefore(this);
                insertado = true;
                return false;
            }
        });
        if (!insertado) { $tbody.append($nueva); }
    }

    // Edicion: reemplaza la fila si esta en pantalla, fusionando sobre los datos
    // previos (conserva lo que el evento no traiga).
    function socketVpsActualizado(v) {
        if (!v || !v.id) { return; }
        var $fila = filaPorId(v.id);
        if (!$fila.length) { return; }
        var previo = AX.datosFila($fila[0]) || {};
        $fila.replaceWith(fila($.extend({}, previo, v)));
    }

    // Borrado: quita la fila; si la tabla queda vacia, muestra el placeholder.
    function socketVpsEliminado(payload) {
        var id = payload && payload.id;
        if (!id) { return; }
        var $fila = filaPorId(id);
        if (!$fila.length) { return; }
        $fila.remove();
        if (!$tbody.children().length) {
            $tbody.html(filaVacia("No hay VPS registrados."));
        }
    }

    // Fila de la pestaña "Dominios" del detalle (enlace cruzado al modulo
    // Dominios). La usan pintarDetalle y la insercion en vivo por socket; el
    // atributo data-dom-id permite localizarla para no duplicar. El payload
    // (helper fila_dominio_socket en PHP) trae proveedor/vencimiento resueltos.
    function filaDominioDetalle(x) {
        var f = x.fecha_vencimiento ? String(x.fecha_vencimiento).substring(0, 10) : "";
        return '<tr data-fecha="' + AX.escaparHtml(f) + '" data-dom-id="' + AX.escaparHtml(x.id) + '">' +
               "<td>" + AX.enlaceProducto("dominios", x.id, x.nombre_dominio) + "</td>" +
               "<td>" + AX.escaparHtml(x.proveedor) + "</td>" +
               "<td>" + AX.formatearFecha(x.fecha_vencimiento) + "</td>" +
               "<td>" + money(x.precio_venta) + "</td></tr>";
    }

    // Alta de dominio en vivo (pestaña Dominios del detalle). El evento llega a
    // toda la sala del modulo vps; solo aplica si el detalle abierto es el de
    // ese VPS. La fila ya viaja con los datos, asi que no se consulta la BD.
    function socketDominioVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detDominios");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-dom-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.append(filaDominioDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    // Fila de la pestaña "Certificados SSL" del detalle (enlace cruzado al modulo
    // SSL). La usan pintarDetalle y la insercion en vivo por socket; data-ssl-id
    // permite localizarla para no duplicar. El nombre del dominio llega como
    // 'nombre_dominio' desde ver.php y como 'dominio' desde el helper de socket
    // (fila_ssl_socket): se acepta cualquiera de los dos.
    function filaCertificadoDetalle(x) {
        var f = x.fecha_vencimiento ? String(x.fecha_vencimiento).substring(0, 10) : "";
        var dom = x.nombre_dominio || x.dominio;
        return '<tr data-fecha="' + AX.escaparHtml(f) + '" data-ssl-id="' + AX.escaparHtml(x.id) + '">' +
               "<td>" + AX.enlaceProducto("ssl", x.id, dom) + "</td>" +
               "<td>" + AX.escaparHtml(x.proveedor) + "</td>" +
               "<td>" + AX.formatearFecha(x.fecha_vencimiento) + "</td></tr>";
    }

    // Alta de certificado en vivo (pestaña Certificados SSL del detalle). Espejo
    // de socketDominioVpsCreado: el evento llega a toda la sala del modulo vps y
    // solo aplica si el detalle abierto es el de ese VPS.
    function socketSslVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detCertificados");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-ssl-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.append(filaCertificadoDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    // Fila de la pestaña "Inventario Lógico" (subtab Registros) del detalle. La
    // usan pintarDetalle y la insercion en vivo por socket; data-inv-id permite
    // localizarla para no duplicar. El payload (helper fila_inventario_socket)
    // trae el responsable ya resuelto. A diferencia de dominios/ssl, este
    // inventario NO tiene modulo propio: solo vive en el detalle del VPS.
    function filaInventarioDetalle(x) {
        var f = x.fecha ? String(x.fecha).substring(0, 10) : "";
        return '<tr data-fecha="' + AX.escaparHtml(f) + '" data-inv-id="' + AX.escaparHtml(x.id) + '">' +
               "<td>" + AX.formatearFecha(x.fecha) + "</td>" +
               "<td>" + AX.escaparHtml(x.tipo_evento) + "</td>" +
               "<td>" + AX.escaparHtml(x.software_componente) + "</td>" +
               "<td>" + AX.escaparHtml(x.version || "—") + "</td>" +
               "<td>" + AX.escaparHtml(x.responsable || "—") + "</td></tr>";
    }

    // Alta de inventario en vivo (subtab Registros del detalle). Espejo de los
    // anteriores; solo aplica si el detalle abierto es el de ese VPS. La tabla va
    // ordenada por fecha DESC (lo mas reciente arriba), asi que un alta nueva se
    // inserta al PRINCIPIO (a diferencia de dominios/ssl, que se agregan al final).
    function socketInventarioVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detHistorial");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-inv-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.prepend(filaInventarioDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    // Fila de la pestaña "Virtual Hosts" del detalle. La usan pintarDetalle y la
    // insercion en vivo por socket; data-vh-id permite localizarla para no
    // duplicar. El dominio (opcional) se enlaza al modulo Dominios si existe. Como
    // el inventario, el Virtual Host NO tiene modulo propio: solo vive aqui.
    function filaVirtualHostDetalle(x) {
        var estadoBadge = x.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
        var dom = x.dominio_id ? AX.enlaceProducto("dominios", x.dominio_id, x.dominio)
                               : AX.escaparHtml(x.dominio || "—");
        return '<tr data-vh-id="' + AX.escaparHtml(x.id) + '">' +
               "<td>" + AX.escaparHtml(x.aplicacion) + "</td>" +
               "<td>" + AX.escaparHtml(x.server_name) + "</td>" +
               "<td>" + dom + "</td>" +
               "<td>" + AX.escaparHtml(x.servidor_web) + "</td>" +
               "<td>" + AX.escaparHtml(x.puerto) + "</td>" +
               "<td>" + (x.ssl_habilitado ? '<i class="bi bi-lock-fill"></i> Sí' : 'No') + "</td>" +
               '<td><span class="badge-estado ' + estadoBadge + '">' + AX.escaparHtml(x.estado) + "</span></td></tr>";
    }

    // Alta de virtual host en vivo (pestaña Virtual Hosts del detalle). Espejo de
    // los anteriores; solo aplica si el detalle abierto es el de ese VPS.
    function socketVirtualHostVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detVirtualHosts");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-vh-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.append(filaVirtualHostDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    // Fila de la pestaña "Historial" del detalle (log de auditoria del VPS). La
    // usan pintarDetalle y la insercion en vivo por socket; data-log-id permite
    // localizarla para no duplicar. El payload (helper fila_historial_socket)
    // trae el usuario ya resuelto y el registro_id como 'vps_id'.
    function filaHistorialDetalle(x) {
        var f = x.fecha_evento ? String(x.fecha_evento).substring(0, 10) : "";
        return '<tr data-fecha="' + AX.escaparHtml(f) + '" data-log-id="' + AX.escaparHtml(x.id) + '">' +
               "<td>" + AX.formatearFecha(x.fecha_evento) + "</td>" +
               "<td>" + AX.escaparHtml(x.usuario || "—") + "</td>" +
               "<td>" + AX.escaparHtml(x.tipo_accion) + "</td>" +
               "<td>" + AX.escaparHtml(x.modulo_afectado) + "</td>" +
               "<td>" + AX.escaparHtml(x.descripcion) + "</td></tr>";
    }

    // Alta de log en vivo (pestaña Historial del detalle). Lo dispara CUALQUIER
    // "agregar_*" del detalle al auditar (via auditar_en_vps). Solo aplica si el
    // detalle abierto es el de ese VPS. La tabla va ordenada por fecha_evento DESC
    // (lo mas reciente arriba), asi que se inserta al PRINCIPIO (prepend).
    function socketHistorialVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detLogs");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-log-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.prepend(filaHistorialDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    // Fila de la pestaña "Notas" del detalle. La usan pintarDetalle y la insercion
    // en vivo por socket; data-nota-id permite localizarla para no duplicar. La
    // fila completa se tinta segun la criticidad (critClaseFila). Como el
    // inventario, la Nota NO tiene modulo propio: solo vive aqui.
    function filaNotaDetalle(x) {
        var f = x.fecha ? String(x.fecha).substring(0, 10) : "";
        var claseFila = critClaseFila(x.criticidad);
        return '<tr data-fecha="' + AX.escaparHtml(f) + '" data-nota-id="' + AX.escaparHtml(x.id) + '"' + claseFila + ">" +
               "<td>" + AX.formatearFecha(x.fecha) + "</td>" +
               "<td>" + critBadge(x.criticidad) + "</td>" +
               "<td>" + AX.escaparHtml(x.autor || "—") + "</td>" +
               "<td>" + AX.escaparHtml(x.nota) + "</td></tr>";
    }

    // Alta de nota en vivo (pestaña Notas del detalle). Espejo de los anteriores;
    // solo aplica si el detalle abierto es el de ese VPS. La tabla va ordenada por
    // fecha DESC (lo mas reciente arriba), asi que se inserta al PRINCIPIO (prepend).
    function socketNotaVpsCreado(payload) {
        if (!payload || !payload.id || !payload.vps_id) { return; }
        if (detalleId !== payload.vps_id) { return; }   // no es el VPS en pantalla
        var $cuerpo = $("#detNotas");
        // Evita duplicar: el propio actor tambien recibe el evento (y ya refresco).
        if ($cuerpo.find('tr[data-nota-id="' + payload.id + '"]').length) { return; }
        $cuerpo.find(".tabla-vacia").closest("tr").remove(); // quita el placeholder "vacio"
        $cuerpo.prepend(filaNotaDetalle(payload));
        aplicarFiltrosDetalle(); // respeta el filtro/busqueda vigente en la pestaña
    }

    function conectarSocketVps() {
        var url = $vistaListado.data("ws");
        // Sin URL o sin la libreria cargada: la app sigue funcionando (con recarga).
        if (!url || typeof io === "undefined") { return; }
        var socket = io(url, { transports: ["websocket", "polling"], withCredentials: true });
        socket.on("connect", function () {
            socketActivo = true;
            socket.emit("unirse", "vps"); // entra a la sala del modulo
        });
        socket.on("disconnect", function () { socketActivo = false; });
        socket.on("vps:creado", socketVpsCreado);
        socket.on("vps:actualizado", socketVpsActualizado);
        socket.on("vps:eliminado", socketVpsEliminado);
        socket.on("dominio_vps:creado", socketDominioVpsCreado);         // detalle: alta de dominio en vivo
        socket.on("ssl_vps:creado", socketSslVpsCreado);                 // detalle: alta de certificado en vivo
        socket.on("inventario_vps:creado", socketInventarioVpsCreado);   // detalle: alta de inventario en vivo
        socket.on("virtualhost_vps:creado", socketVirtualHostVpsCreado); // detalle: alta de virtual host en vivo
        socket.on("historial_vps:creado", socketHistorialVpsCreado);     // detalle: nuevo log de auditoria en vivo
        socket.on("nota_vps:creado", socketNotaVpsCreado);               // detalle: alta de nota en vivo
    }

    // ===============================================================
    //  Navegacion entre vistas (listado / formulario / detalle)
    // ===============================================================
    function mostrar($vista) {
        $vistaListado.addClass("d-none");
        $vistaDetalle.addClass("d-none");
        $vista.removeClass("d-none");
        // La toolbar (buscador + "Nuevo VPS") pertenece solo al listado;
        // se oculta en el detalle.
        $(".toolbar").toggleClass("d-none", $vista[0] !== $vistaListado[0]);
    }

    // Tras guardar en el modal: cerrarlo y refrescar la vista de fondo (el
    // detalle si estaba abierto; si no, el listado). Con el socket activo la
    // fila se pinta/actualiza sola por el evento, asi que se evita recargar el
    // listado (se recarga solo como respaldo si el tiempo real no esta activo).
    function trasGuardar() {
        modalForm.cerrar();
        if (detalleId) { abrirDetalle(detalleId); }
        else if (!socketActivo) { cargar(); }
    }

    function volverAlListado() {
        mostrar($vistaListado);
        detalleId = null;
        editandoId = null;
        cargar(); // recarga la lista y restaura el paginador en el footer
    }

    // ===============================================================
    //  Eventos
    // ===============================================================
    $("#btnNuevoVps").on("click", function () { abrirFormulario("crear"); });

    // Al cambiar de proveedor, se recargan sus referencias en el combobox.
    $("#fvProveedor").on("change", function () {
        if (cbRef) { cbRef.limpiar(); }
        ocultarResumenRef();
        cargarReferencias($(this).val());
    });

    // Boton "+": abre el modal anidado para crear una referencia nueva.
    $("#btnNuevaReferenciaVps").on("click", function () {
        var prov = $("#fvProveedor").val();
        if (!prov) { AX.error("Selecciona primero un proveedor."); return; }
        // Reset de todos los campos de la referencia (specs + costos + tipo).
        $("#frNombre").val("");
        $("#frDisco").val("");        $("#frDiscoUnidad").val("GB");
        $("#frRam").val("");          $("#frRamUnidad").val("GB");
        $("#frAnchoBanda").val("");   $("#frAnchoBandaUnidad").val("TB");
        $("#frPrecioCompra").val(""); $("#frMonedaCompra").val("COP");
        $("#frPrecioVenta").val("");  $("#frMonedaVenta").val("COP");
        $("#frTipoServidor").val("Compartido");
        $("#formReferenciaError").addClass("d-none").text("");
        $("#referenciaProveedorNombre").text("Proveedor: " + ($("#fvProveedor option:selected").text() || ""));
        $("#btnGuardarReferencia").prop("disabled", false);
        modalRef.abrir();
        $("#frNombre").trigger("focus");
    });

    // Guardar la nueva referencia (insercion sincrona vinculada al proveedor).
    // La referencia lleva TODAS las specs: hardware con unidad (GB/TB), costos
    // multimoneda y tipo de servidor. Se empaquetan con los helpers de general.js.
    $("#btnGuardarReferencia").on("click", function () {
        var prov   = $("#fvProveedor").val();
        var nombre = $.trim($("#frNombre").val());
        if (!prov)   { return AX.errorFormulario("#formReferenciaError", "Selecciona un proveedor en el formulario de VPS."); }
        if (!nombre) { return AX.errorFormulario("#formReferenciaError", "Escribe el nombre de la referencia."); }

        var disco = AX.leerMedida("#frDisco", "#frDiscoUnidad");
        var ram   = AX.leerMedida("#frRam", "#frRamUnidad");
        var ab    = AX.leerMedida("#frAnchoBanda", "#frAnchoBandaUnidad");
        if (!disco.valor || !ram.valor) {
            return AX.errorFormulario("#formReferenciaError", "La capacidad de disco y la memoria RAM son obligatorias.");
        }

        var datos = {
            proveedor_id:       prov,
            nombre:             nombre,
            disco_valor:        disco.valor,
            disco_unidad:       disco.unidad,
            ram_valor:          ram.valor,
            ram_unidad:         ram.unidad,
            ancho_banda_valor:  ab.valor,
            ancho_banda_unidad: ab.unidad,
            precio_compra:      $("#frPrecioCompra").val() === "" ? 0 : $("#frPrecioCompra").val(),
            moneda_compra:      $("#frMonedaCompra").val(),
            precio_venta:       $("#frPrecioVenta").val() === "" ? 0 : $("#frPrecioVenta").val(),
            moneda_venta:       $("#frMonedaVenta").val(),
            tipo_servidor:      $("#frTipoServidor").val()
        };

        var $btn = $("#btnGuardarReferencia").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/referencias_crear.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalRef.cerrar();
                AX.exito("La referencia se creó correctamente.");
                // Guardar sus specs y precargarla/seleccionarla en el combobox padre.
                refsSpec[res.data.id] = res.data;
                if (cbRef) { cbRef.agregar({ id: res.data.id, texto: res.data.nombre }, true); }
                refrescarResumenRef();
            } else {
                AX.errorFormulario("#formReferenciaError", (res && res.mensaje) || "No se pudo crear la referencia.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formReferenciaError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo crear la referencia.");
            $btn.prop("disabled", false);
        });
    });

    // Boton Guardar del modal (Cancelar lo maneja data-bs-dismiss).
    $(document).on("click", "#btnGuardarVps", enviarFormulario);
    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);

    // Eliminacion real del VPS: confirma, llama al endpoint y recarga.
    // El backend responde 409 si el VPS tiene hosting asociado.
    function eliminarRegistro(reg) {
        var nombre = reg.label || reg.referencia_vps || "este VPS";
        AX.confirmar({
            titulo: "Eliminar VPS",
            texto: 'Se eliminará "' + nombre + '" junto con su historial, notas, credenciales SSH y virtual hosts. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/vps/eliminar.php", { id: reg.id }).then(function (res) {
                if (res.ok) {
                    AX.exito(res.mensaje || "VPS eliminado.");
                    // Con el socket activo la fila se quita sola por el evento.
                    if (!socketActivo) { cargar(); }
                } else { AX.error(res.mensaje || "No se pudo eliminar el VPS."); }
            }).catch(function () { AX.error("No se pudo eliminar el VPS."); });
        });
    }

    // Clic en la tabla: los botones de accion mandan; el resto de la fila abre el detalle.
    $tbody.on("click", "tr", function (e) {
        // Si se pulso un enlace cruzado, dejar que navegue (no abrir el detalle propio).
        if (AX.esClicEnEnlace(e)) { return; }
        var $btn = $(e.target).closest("[data-accion]");
        var reg = AX.datosFila(this);
        if ($btn.length) {
            var accion = $btn.data("accion");
            if (accion === "ver") {
                if (reg) { abrirDetalle(reg.id); }
            } else if (accion === "editar") {
                if (reg) { abrirFormulario("editar", reg); }
                else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
            } else if (accion === "eliminar") {
                if (reg) { eliminarRegistro(reg); }
                else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
            }
            return;
        }
        // Clic en cualquier otra parte de la fila -> detalle.
        if (reg) { abrirDetalle(reg.id); }
    });

    // Combobox de referencia: al elegir/escribir se refresca el resumen de las
    // specs que la VPS heredara (o se oculta si es texto libre sin coincidencia).
    cbRef = AX.combobox("#cbReferenciaVps", {
        onSeleccion: refrescarResumenRef,
        onEscribir:  refrescarResumenRef
    });
    // La restriccion numerica ahora aplica a los campos de la REFERENCIA.
    AX.restringirNumerico("#frDisco, #frRam, #frAnchoBanda");
    // Vigencia anual de los modales relacionales: la fecha de vencimiento se
    // calcula sola (registro + 1 año - 1 dia) y se previsualiza de solo lectura.
    var vigAddDominio = AX.vincularVigencia("#adFechaReg", "#adFechaVen");
    var vigAddSsl     = AX.vincularVigencia("#asFechaReg", "#asFechaVen");
    inicializarFiltrosDetalle();
    cargar();
    conectarSocketVps(); // tiempo real: escucha altas/ediciones/borrados del listado

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
