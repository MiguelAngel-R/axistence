/* =====================================================================
   AXISTENCE - proyectos.js
   Modulo Proyectos / Desarrollos. Listado + formulario modal reutilizable
   (info general + cliente opcional + equipo N:N + recursos N:N: dominios,
   vps, ssl, hosting) y vista de DETALLE (viewProyecto) con pestañas +
   filtros. El tablero Kanban se implementara despues.
   Espejo de assets/js/hosting.js.

   NOTA: la accion "Eliminar" queda solo maquetada; no se implementa borrado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyProyecto");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm         = AX.modal("#modalProyecto");
    var modalTarea        = AX.modal("#modalTarea");
    var modalTareaDetalle = AX.modal("#modalTareaDetalle");
    var modalArchivadas   = AX.modal("#modalArchivadas");
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { clientes, usuarios, dominios, vps, ssl, hosting }
    var dropFechas = null;
    var msEquipo = null, msDominios = null, msHosting = null;

    // Estado del tablero Kanban (viewProyecto).
    var kanbanSortables = [];  // instancias Sortable vivas (se destruyen al repintar)
    var kanbanColDestino = null; // columna destino al crear una tarjeta desde el "+"
    var kanbanDragFin = 0;     // marca de tiempo del ultimo arrastre (para no abrir el detalle tras soltar)
    var dtTareaActual = null;  // id de la tarea abierta en el modal de detalle (para comentar)
    var dtEquipo = [];         // equipo del proyecto (candidatos a responsables) [{id, nombre_completo}]
    var dtResponsablesIds = []; // ids de los responsables actuales de la tarea abierta
    var dtDescripcion = "";    // descripcion vigente de la tarea abierta (para el editor)

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

    function badgeEstado(est) {
        return '<span class="badge-estado badge-estado--proyecto">' + AX.escaparHtml(est || "—") + "</span>";
    }

    // ===============================================================
    //  Listado
    // ===============================================================
    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/proyectos/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los proyectos.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay proyectos registrados.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(p) {
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(p)) + '">' +
            "<td>" + AX.escaparHtml(p.nombre_proyecto) + "</td>" +
            "<td>" + (p.cliente ? AX.escaparHtml(p.cliente) : '<span class="text-muted">Interno</span>') + "</td>" +
            "<td>" + badgeEstado(p.estado) + "</td>" +
            "<td>" + AX.formatearFecha(p.fecha_inicio) + "</td>" +
            "<td>" + AX.formatearFecha(p.fecha_entrega_estimada) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(p.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(p.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(p.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarProyecto").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Opciones de los <select> / multiselect
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#fpCliente").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/proyectos/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || {};
                $("#fpCliente").html('<option value="">Sin cliente (proyecto interno)</option>' +
                    (opciones.clientes || []).map(function (c) {
                        return '<option value="' + AX.escaparHtml(c.id) + '">' + AX.escaparHtml(c.nombre_razon_social) + "</option>";
                    }).join(""));
                // Servidor / VPS: seleccion unica (un proyecto = un servidor).
                $("#fpVps").html('<option value="">Sin servidor</option>' +
                    (opciones.vps || []).map(function (v) {
                        return '<option value="' + AX.escaparHtml(v.id) + '">' + AX.escaparHtml(v.referencia_vps) + "</option>";
                    }).join(""));
                if (msEquipo) {
                    msEquipo.opciones((opciones.usuarios || []).map(function (u) {
                        return { id: u.id, texto: u.nombre_completo };
                    }));
                }
                if (msDominios) {
                    msDominios.opciones((opciones.dominios || []).map(function (d) {
                        return { id: d.id, texto: d.nombre_dominio };
                    }));
                }
                if (msHosting) {
                    msHosting.opciones((opciones.hosting || []).map(function (h) {
                        return { id: h.id, texto: h.referencia_vps + " · " + h.espacio_asignado };
                    }));
                }
                if (despues) { despues(); }
            } else {
                $("#fpCliente").html('<option value="">No se pudieron cargar las opciones</option>');
            }
        }).fail(function () { $("#fpCliente").html('<option value="">Error al cargar</option>'); });
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, proy) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && proy) ? proy.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formProyecto", "#formProyectoError");
        $("#fpEstado").val("Planificación");
        $("#formProyectoTitulo").text(esEditar ? "Editar proyecto" : "Nuevo proyecto");
        $("#btnGuardarProyecto").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        cargarOpciones(function () {
            if (esEditar && proy) {
                $("#fpNombre").val(proy.nombre_proyecto || "");
                $("#fpCliente").val(proy.cliente_id || "");
                $("#fpEstado").val(proy.estado || "Planificación");
                $("#fpInicio").val((proy.fecha_inicio || "").substring(0, 10));
                $("#fpEntrega").val((proy.fecha_entrega_estimada || "").substring(0, 10));
                $("#fpDescripcion").val(proy.descripcion || "");
                $("#fpVps").val((proy.vps && proy.vps[0]) ? proy.vps[0].id : "");
                if (msEquipo)   { msEquipo.seleccion((proy.equipo   || []).map(function (x) { return x.id; })); }
                if (msDominios) { msDominios.seleccion((proy.dominios || []).map(function (x) { return x.id; })); }
                if (msHosting)  { msHosting.seleccion((proy.hosting || []).map(function (x) { return x.id; })); }
            } else {
                if (msEquipo)   { msEquipo.seleccion([]); }
                if (msDominios) { msDominios.seleccion([]); }
                if (msHosting)  { msHosting.seleccion([]); }
            }
        });

        modalForm.abrir();
        $("#fpNombre").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formProyectoError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var datos = {
            nombre_proyecto:        $.trim($("#fpNombre").val()),
            cliente_id:             $("#fpCliente").val(),
            estado:                 $("#fpEstado").val(),
            fecha_inicio:           $("#fpInicio").val(),
            fecha_entrega_estimada: $("#fpEntrega").val(),
            descripcion:            $.trim($("#fpDescripcion").val()),
            vps_id:                 $("#fpVps").val(),
            equipo:                 msEquipo   ? msEquipo.valores()   : [],
            dominios:               msDominios ? msDominios.valores() : [],
            hosting:                msHosting  ? msHosting.valores()  : []
        };

        if (!datos.nombre_proyecto || !datos.fecha_inicio) {
            return errorFormulario("Completa los campos obligatorios (nombre y fecha de inicio).");
        }

        var url = esEditar ? "endpoints/proyectos/actualizar.php" : "endpoints/proyectos/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarProyecto").prop("disabled", true);
        $.ajax({
            url: url, method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "El proyecto se actualizó correctamente." : "El proyecto se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el proyecto.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el proyecto.");
            $btn.prop("disabled", false);
        });
    }

    // ===============================================================
    //  Detalle (viewProyecto)
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
        actualizarBotonArchivadas();
    }

    function inicializarFiltrosDetalle() {
        var t;
        $("#detBuscar").on("input", function () { clearTimeout(t); t = setTimeout(aplicarFiltrosDetalle, 200); });
        dropFechas = AX.dropdownFechas("#detFechas", { onAplicar: aplicarFiltrosDetalle, onLimpiar: aplicarFiltrosDetalle });
        $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", function () {
            aplicarFiltrosDetalle();
            actualizarBotonArchivadas();   // el boton "Archivadas" solo en el tablero
        });
    }

    function abrirDetalle(id) {
        detalleId = id;
        mostrar($vistaDetalle);
        reiniciarFiltrosDetalle();
        AX.limpiarFooter();
        window.scrollTo({ top: 0, behavior: "smooth" });

        $.ajax({
            url: "endpoints/proyectos/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: id }
        }).done(function (res) {
            if (res && res.ok) { pintarDetalle(res.data); }
            else { AX.error((res && res.mensaje) || "No se pudo cargar el detalle."); volverAlListado(); }
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el detalle.");
            volverAlListado();
        });

        // El tablero se carga en paralelo (endpoint propio del Kanban).
        cargarTablero(id);
    }

    function pintarDetalle(d) {
        var p = d.proyecto || {};
        $("#detTitulo").text(p.nombre_proyecto || "Detalle del proyecto");
        $("#detCliente").html(p.cliente ? AX.escaparHtml(p.cliente) : '<span class="text-muted">Proyecto interno</span>');
        $("#detEstado").html(badgeEstado(p.estado));
        $("#detInicio").text(AX.formatearFecha(p.fecha_inicio));
        $("#detEntrega").text(AX.formatearFecha(p.fecha_entrega_estimada));
        $("#detDescripcion").text(p.descripcion || "—");
        $("#detCreado").text(AX.formatearFecha(p.created_at));
        $("#detActualizado").text(AX.formatearFecha(p.updated_at));
        // Servidor / VPS: unico, se muestra en la informacion general.
        var srv = (d.vps && d.vps[0]) ? d.vps[0] : null;
        $("#detServidor").html(srv ? AX.enlaceProducto("vps", srv.id, srv.referencia_vps) : "—");

        pintarSeccion($("#detEquipo"), d.equipo, 4, function (u) {
            var badge = u.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
            return "<tr><td>" + AX.escaparHtml(u.nombre_completo) + "</td>" +
                   "<td>" + AX.escaparHtml(u.correo || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(u.rol_en_proyecto || "—") + "</td>" +
                   '<td><span class="badge-estado ' + badge + '">' + AX.escaparHtml(u.estado) + "</span></td></tr>";
        });

        pintarSeccion($("#detDominios"), d.dominios, 4, function (x) {
            return trFecha(x.fecha_vencimiento) + "<td>" + AX.enlaceProducto("dominios", x.id, x.nombre_dominio) + "</td>" +
                   "<td>" + AX.escaparHtml(x.proveedor) + "</td>" +
                   "<td>" + AX.formatearFecha(x.fecha_vencimiento) + "</td>" +
                   "<td>" + AX.escaparHtml(x.descripcion_uso || "—") + "</td></tr>";
        });

        pintarSeccion($("#detHosting"), d.hosting, 3, function (x) {
            return "<tr><td>" + AX.enlaceProducto("hosting", x.id, x.vps) + "</td>" +
                   "<td>" + AX.escaparHtml(x.espacio_asignado || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(x.descripcion_uso || "—") + "</td></tr>";
        });

        pintarSeccion($("#detNotas"), d.notas, 3, function (n) {
            return trFecha(n.fecha) + "<td>" + AX.formatearFecha(n.fecha) + "</td>" +
                   "<td>" + AX.escaparHtml(n.autor || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(n.nota) + "</td></tr>";
        });

        aplicarFiltrosDetalle();
    }

    // ===============================================================
    //  Tablero Kanban (Sortable.js) - dentro de viewProyecto
    // ===============================================================

    // Clase CSS del chip de prioridad segun su valor.
    function claseParaPrioridad(prioridad) {
        switch (prioridad) {
            case "Crítica": return "kanban__prioridad--critica";
            case "Alta":    return "kanban__prioridad--alta";
            case "Baja":    return "kanban__prioridad--baja";
            default:        return "kanban__prioridad--media";
        }
    }

    // HTML de una tarjeta (tarea). Todo dato de la BD va escapado (anti-XSS).
    function tarjetaHtml(t) {
        var completada = !!t.fecha_finalizacion_real;
        var desc = t.descripcion
            ? '<p class="kanban__card-desc">' + AX.escaparHtml(t.descripcion) + "</p>"
            : "";
        var responsables = t.responsables
            ? '<span class="kanban__card-resp"><i class="bi bi-person" aria-hidden="true"></i> ' +
              AX.escaparHtml(t.responsables) + "</span>"
            : "";
        var check = '<button type="button" class="kanban__check' + (completada ? " is-completada" : "") +
                    '" data-rol="completar" title="' + (completada ? "Completada" : "Marcar como completada") + '">' +
                    '<i class="bi ' + (completada ? "bi-check-circle-fill" : "bi-check-circle") + '" aria-hidden="true"></i>' +
                    "</button>";
        return '<article class="kanban__card' + (completada ? " kanban__card--completada" : "") +
                   '" data-tarea-id="' + AX.escaparHtml(t.id) + '">' +
                   '<div class="kanban__card-top">' +
                       '<span class="kanban__prioridad ' + claseParaPrioridad(t.prioridad) + '">' +
                           AX.escaparHtml(t.prioridad || "Media") + "</span>" +
                       check +
                   "</div>" +
                   '<h4 class="kanban__card-titulo">' + AX.escaparHtml(t.titulo) + "</h4>" +
                   desc +
                   (responsables ? '<div class="kanban__card-pie">' + responsables + "</div>" : "") +
               "</article>";
    }

    // HTML de una columna con su cabecera (nombre + contador + acciones) y su
    // lista arrastrable. Las acciones (agregar tarea / renombrar / eliminar)
    // leen el id y el nombre desde el <section> contenedor.
    function columnaHtml(col) {
        var tareas = col.tareas || [];
        var cards = tareas.length ? tareas.map(tarjetaHtml).join("") : "";
        var idEsc = AX.escaparHtml(col.id);
        var nomEsc = AX.escaparHtml(col.nombre);
        return '<section class="kanban__col" data-columna-id="' + idEsc +
                   '" data-columna-nombre="' + nomEsc + '">' +
                   '<header class="kanban__col-head">' +
                       '<i class="bi bi-grip-vertical kanban__col-grip" aria-hidden="true" title="Arrastra para reordenar"></i>' +
                       '<span class="kanban__col-nombre" title="' + nomEsc + '">' + nomEsc + "</span>" +
                       '<span class="kanban__col-conteo" data-rol="conteo">' + tareas.length + "</span>" +
                       '<div class="kanban__col-acciones">' +
                           '<button type="button" class="kanban__colbtn" data-rol="add-tarea" title="Agregar tarea">' +
                               '<i class="bi bi-plus-lg" aria-hidden="true"></i></button>' +
                           '<button type="button" class="kanban__colbtn" data-rol="renombrar" title="Renombrar columna">' +
                               '<i class="bi bi-pencil" aria-hidden="true"></i></button>' +
                           '<button type="button" class="kanban__colbtn kanban__colbtn--peligro" data-rol="eliminar-col" title="Eliminar columna">' +
                               '<i class="bi bi-trash" aria-hidden="true"></i></button>' +
                       "</div>" +
                   "</header>" +
                   '<div class="kanban__lista" data-columna-id="' + idEsc + '">' +
                       cards +
                   "</div>" +
               "</section>";
    }

    // Destruye las instancias Sortable activas antes de repintar el tablero.
    function destruirSortables() {
        kanbanSortables.forEach(function (s) { try { s.destroy(); } catch (e) {} });
        kanbanSortables = [];
    }

    // Inicializa los Sortable del tablero:
    //   1) Uno por columna (listas de tarjetas), con "group" comun para poder
    //      arrastrar tarjetas entre columnas.
    //   2) Uno sobre el contenedor de columnas (#kanbanCols) para REORDENAR las
    //      columnas horizontalmente. Se arrastra por la cabecera (handle) y se
    //      excluyen los botones de accion para no interferir con sus clics.
    function iniciarSortables() {
        if (typeof Sortable === "undefined") { return; }
        destruirSortables();

        document.querySelectorAll("#kanbanTablero .kanban__lista").forEach(function (lista) {
            kanbanSortables.push(new Sortable(lista, {
                group: "kanban",
                animation: 150,
                ghostClass: "kanban__card--fantasma",
                dragClass: "kanban__card--arrastrando",
                onEnd: persistirMovimiento
            }));
        });

        var cols = document.getElementById("kanbanCols");
        if (cols) {
            kanbanSortables.push(new Sortable(cols, {
                draggable: ".kanban__col",
                handle: ".kanban__col-head",
                filter: ".kanban__colbtn",
                preventOnFilter: false,
                animation: 150,
                ghostClass: "kanban__col--fantasma",
                onEnd: persistirOrdenColumnas
            }));
        }
    }

    // Recalcula los contadores de cada columna a partir del DOM.
    function refrescarConteos() {
        document.querySelectorAll("#kanbanTablero .kanban__col").forEach(function (col) {
            var n = col.querySelectorAll(".kanban__lista .kanban__card").length;
            var badge = col.querySelector('[data-rol="conteo"]');
            if (badge) { badge.textContent = n; }
        });
    }

    // Persistencia REACTIVA: al soltar una tarjeta, envia en segundo plano la
    // columna destino y el nuevo orden de esa columna (sincronizacion 2D).
    function persistirMovimiento(evt) {
        kanbanDragFin = Date.now();   // marca: hubo arrastre (evita abrir el detalle al soltar)
        refrescarConteos();
        var listaDestino = evt.to;
        var tareaId  = evt.item.getAttribute("data-tarea-id");
        var columnaId = listaDestino.getAttribute("data-columna-id");
        var orden = Array.prototype.map.call(
            listaDestino.querySelectorAll(".kanban__card"),
            function (c) { return c.getAttribute("data-tarea-id"); }
        );

        AX.enviarJSON("endpoints/kanban/mover_tarea.php", {
            proyecto_id: detalleId,
            tarea_id: tareaId,
            columna_id: columnaId,
            orden: orden
        }).then(function (res) {
            if (!res.ok) {
                AX.toast(res.mensaje || "No se pudo guardar el movimiento.", "error");
                cargarTablero(detalleId); // recarga => revierte al estado real
            }
        }).catch(function () {
            AX.toast("No se pudo guardar el movimiento.", "error");
            cargarTablero(detalleId);
        });
    }

    // Persistencia del REORDEN de columnas: al soltar, envia el nuevo orden de
    // ids de columna. Si falla, recarga el tablero para volver al estado real.
    function persistirOrdenColumnas() {
        var cols = document.getElementById("kanbanCols");
        if (!cols) { return; }
        var orden = Array.prototype.map.call(
            cols.querySelectorAll(".kanban__col"),
            function (c) { return c.getAttribute("data-columna-id"); }
        );
        AX.enviarJSON("endpoints/kanban/reordenar_columnas.php", {
            proyecto_id: detalleId,
            orden: orden
        }).then(function (res) {
            if (!res.ok) {
                AX.toast(res.mensaje || "No se pudo guardar el orden de las columnas.", "error");
                cargarTablero(detalleId);
            }
        }).catch(function () {
            AX.toast("No se pudo guardar el orden de las columnas.", "error");
            cargarTablero(detalleId);
        });
    }

    // Marca/desmarca una tarjeta como completada (check verde) y actualiza el
    // boton y el estilo de la tarjeta en el sitio, sin recargar el tablero.
    function completarTarea(tareaId, completar, $btn) {
        if (!detalleId || !tareaId) { return; }
        $btn.prop("disabled", true);
        AX.enviarJSON("endpoints/kanban/completar_tarea.php", {
            proyecto_id: detalleId, tarea_id: tareaId, completada: completar
        }).then(function (res) {
            if (res.ok && res.data) {
                var comp = !!res.data.completada;
                $btn.toggleClass("is-completada", comp)
                    .attr("title", comp ? "Completada" : "Marcar como completada");
                $btn.find("i").attr("class", "bi " + (comp ? "bi-check-circle-fill" : "bi-check-circle"));
                $btn.closest(".kanban__card").toggleClass("kanban__card--completada", comp);
            } else {
                AX.toast(res.mensaje || "No se pudo actualizar la tarea.", "error");
            }
            $btn.prop("disabled", false);
        }).catch(function () {
            AX.toast("No se pudo actualizar la tarea.", "error");
            $btn.prop("disabled", false);
        });
    }

    // Pinta el tablero completo y (re)inicializa el arrastre.
    function pintarTablero(data) {
        var $cont = $("#kanbanTablero");
        var columnas = (data && data.columnas) || [];
        var botonAgregar =
            '<button type="button" class="kanban__add-col" id="kanbanAddCol">' +
                '<i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar columna</button>';
        if (!columnas.length) {
            $cont.html('<p class="kanban__estado">Este tablero aún no tiene columnas.</p>' + botonAgregar);
            return;
        }
        // Las columnas van en su propio contenedor arrastrable (#kanbanCols);
        // el boton "Agregar columna" queda fuera para no entrar en el reorden.
        $cont.html('<div class="kanban__cols" id="kanbanCols">' +
                       columnas.map(columnaHtml).join("") +
                   "</div>" + botonAgregar);
        iniciarSortables();
    }

    // Carga el tablero del proyecto desde el backend.
    function cargarTablero(proyectoId) {
        if (!proyectoId) { return; }
        var $cont = $("#kanbanTablero").attr("data-proyecto-id", proyectoId);
        $cont.html('<p class="kanban__estado">Cargando tablero…</p>');
        $.ajax({
            url: "endpoints/kanban/tablero.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { proyecto_id: proyectoId }
        }).done(function (res) {
            if (res && res.ok) { pintarTablero(res.data); }
            else { $cont.html('<p class="kanban__estado">No se pudo cargar el tablero.</p>'); }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el tablero.";
            $cont.html('<p class="kanban__estado">' + AX.escaparHtml(msg) + "</p>");
        });
    }

    // --- Alta de tarjeta (modal) -----------------------------------
    function abrirModalTarea(columnaId, columnaNombre) {
        if (!modalTarea) { return; }
        kanbanColDestino = columnaId;
        AX.limpiarFormulario("#formTarea", "#formTareaError");
        $("#ftColumnaId").val(columnaId);
        $("#ftPrioridad").val("Media");
        $("#ftColumnaNombre").text(columnaNombre ? "Se agregará en: " + columnaNombre : "");
        $("#btnGuardarTarea").prop("disabled", false);
        modalTarea.abrir();
        $("#ftTitulo").trigger("focus");
    }

    function guardarTarea() {
        var titulo = $.trim($("#ftTitulo").val());
        if (!titulo) {
            return AX.errorFormulario("#formTareaError", "El título de la tarea es obligatorio.");
        }
        var datos = {
            proyecto_id: detalleId,
            columna_id:  kanbanColDestino || $("#ftColumnaId").val(),
            titulo:      titulo,
            descripcion: $.trim($("#ftDescripcion").val()),
            prioridad:   $("#ftPrioridad").val()
        };

        var $btn = $("#btnGuardarTarea").prop("disabled", true);
        AX.enviarJSON("endpoints/kanban/crear_tarea.php", datos).then(function (res) {
            if (res.ok) {
                modalTarea.cerrar();
                cargarTablero(detalleId);   // recarga con la tarjeta ya posicionada
                AX.toast("Tarea creada.", "exito");
            } else {
                AX.errorFormulario("#formTareaError", res.mensaje || "No se pudo crear la tarea.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formTareaError", "No se pudo crear la tarea.");
            $btn.prop("disabled", false);
        });
    }

    // --- Detalle de una tarjeta (modal de solo lectura) ------------
    function abrirDetalleTarea(tareaId) {
        if (!modalTareaDetalle || !detalleId) { return; }
        dtTareaActual = tareaId;
        $("#dtTitulo").text("—");
        $("#dtContenido").addClass("d-none");
        $("#dtCargando").removeClass("d-none").text("Cargando…");
        // Reinicia los formularios de comentario y de adjunto.
        $("#dtNuevoComentario").val("");
        $("#dtComentarioError").addClass("d-none").text("");
        $("#btnComentar").prop("disabled", false);
        if ($("#formAdjunto").length) { $("#formAdjunto")[0].reset(); }
        $("#dtAdjuntoError").addClass("d-none").text("");
        $("#btnSubirAdjunto").prop("disabled", false);
        modalTareaDetalle.abrir();

        $.ajax({
            url: "endpoints/kanban/ver_tarea.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { proyecto_id: detalleId, tarea_id: tareaId }
        }).done(function (res) {
            if (res && res.ok) { pintarDetalleTarea(res.data); }
            else { $("#dtCargando").text((res && res.mensaje) || "No se pudo cargar la tarea."); }
        }).fail(function (xhr) {
            $("#dtCargando").text((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar la tarea.");
        });
    }

    function pintarDetalleTarea(d) {
        var t = d.tarea || {};
        $("#dtTitulo").text(t.titulo || "—");
        $("#dtColumna").html('<span class="badge-estado badge-estado--proyecto">' +
            AX.escaparHtml(t.columna || "—") + "</span>");
        $("#dtPrioridad").html('<span class="kanban__prioridad ' + claseParaPrioridad(t.prioridad) + '">' +
            AX.escaparHtml(t.prioridad || "Media") + "</span>");
        $("#dtInicio").text(t.fecha_inicio ? AX.formatearFecha(t.fecha_inicio) : "—");
        $("#dtLimite").text(t.fecha_limite ? AX.formatearFecha(t.fecha_limite) : "—");
        $("#dtFinReal").text(t.fecha_finalizacion_real ? AX.formatearFecha(t.fecha_finalizacion_real) : "—");
        $("#dtCreada").text(AX.formatearFecha(t.created_at));
        $("#dtActualizada").text(AX.formatearFecha(t.updated_at));
        pintarDescripcion(t.descripcion || "");

        dtEquipo = d.equipo || [];
        var resp = d.responsables || [];
        dtResponsablesIds = resp.map(function (u) { return u.id; });
        pintarResponsables(resp);

        pintarComentarios(d.comentarios || []);
        pintarAdjuntos(d.adjuntos || []);

        $("#dtCargando").addClass("d-none");
        $("#dtContenido").removeClass("d-none");
    }

    // --- Adjuntos (subida + descarga) ------------------------------
    // Formatea un tamaño en bytes de forma legible (B / KB / MB).
    function formatearTamano(bytes) {
        var n = parseInt(bytes, 10);
        if (isNaN(n) || n <= 0) { return ""; }
        if (n < 1024) { return n + " B"; }
        if (n < 1048576) { return (n / 1024).toFixed(1) + " KB"; }
        return (n / 1048576).toFixed(1) + " MB";
    }

    // HTML de un adjunto: enlace de descarga (endpoint seguro) + metadatos.
    function adjuntoHtml(a) {
        var url = "endpoints/kanban/descargar_adjunto.php?proyecto_id=" +
                  encodeURIComponent(detalleId) + "&adjunto_id=" + encodeURIComponent(a.id);
        var tam = a.tamano_bytes ? formatearTamano(a.tamano_bytes) : "";
        return '<li>' +
                   '<a class="ax-enlace" href="' + AX.escaparHtml(url) + '" title="Descargar">' +
                       '<i class="bi bi-paperclip" aria-hidden="true"></i> ' +
                       AX.escaparHtml(a.nombre_archivo) + "</a>" +
                   (tam ? ' <span class="kanban-detalle__fecha">· ' + tam + "</span>" : "") +
                   (a.subido_por ? " · " + AX.escaparHtml(a.subido_por) : "") +
                   '<span class="kanban-detalle__fecha"> · ' + AX.formatearFechaHora(a.fecha_subida) + "</span>" +
               "</li>";
    }

    function pintarAdjuntos(adj) {
        $("#dtAdjuntos").html(adj && adj.length
            ? adj.map(adjuntoHtml).join("")
            : '<li class="kanban-adj__vacio text-muted">Sin adjuntos.</li>');
    }

    function subirAdjunto() {
        var input = document.getElementById("dtArchivo");
        var archivo = input && input.files && input.files[0];
        if (!archivo) { return AX.errorFormulario("#dtAdjuntoError", "Elige un archivo para adjuntar."); }
        if (!detalleId || !dtTareaActual) { return; }
        if (archivo.size > 26214400) {
            return AX.errorFormulario("#dtAdjuntoError", "El archivo es demasiado grande (máximo 25 MB).");
        }

        var fd = new FormData();
        fd.append("proyecto_id", detalleId);
        fd.append("tarea_id", dtTareaActual);
        fd.append("archivo", archivo);

        var $btn = $("#btnSubirAdjunto").prop("disabled", true);
        $btn.find("[data-rol='texto']").text("Subiendo…");
        fetch("endpoints/kanban/subir_adjunto.php", {
            method: "POST", credentials: "include", body: fd
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (cuerpo) {
                return { ok: resp.ok && cuerpo && cuerpo.ok === true, mensaje: (cuerpo && cuerpo.mensaje) || "", data: (cuerpo && cuerpo.data) || null };
            });
        }).then(function (res) {
            if (res.ok && res.data) {
                $("#dtAdjuntos").find(".kanban-adj__vacio").remove();
                $("#dtAdjuntos").prepend(adjuntoHtml(res.data));  // orden: mas reciente primero
                $("#formAdjunto")[0].reset();
                $("#dtAdjuntoError").addClass("d-none").text("");
            } else {
                AX.errorFormulario("#dtAdjuntoError", res.mensaje || "No se pudo subir el archivo.");
            }
        }).catch(function () {
            AX.errorFormulario("#dtAdjuntoError", "No se pudo subir el archivo.");
        }).then(function () {
            $btn.prop("disabled", false).find("[data-rol='texto']").text("Adjuntar");
        });
    }

    // --- Descripcion (editable en el sitio) ------------------------
    function pintarDescripcion(texto) {
        dtDescripcion = texto || "";
        $("#dtDescVista").text(dtDescripcion || "Sin descripción.")
            .toggleClass("text-muted", !dtDescripcion);
        // Siempre se vuelve al modo lectura al (re)pintar.
        $("#dtDescEditor").addClass("d-none");
        $("#dtDescVista").removeClass("d-none");
        $("#dtDescError").addClass("d-none").text("");
        $("#btnEditarDesc").removeClass("d-none");
    }

    function abrirEditorDescripcion() {
        $("#dtDescInput").val(dtDescripcion);
        $("#dtDescError").addClass("d-none").text("");
        $("#dtDescVista").addClass("d-none");
        $("#btnEditarDesc").addClass("d-none");
        $("#dtDescEditor").removeClass("d-none");
        $("#btnGuardarDesc").prop("disabled", false);
        $("#dtDescInput").trigger("focus");
    }

    function cancelarEditorDescripcion() {
        pintarDescripcion(dtDescripcion);   // descarta cambios
    }

    function guardarDescripcion() {
        if (!detalleId || !dtTareaActual) { return; }
        var texto = $("#dtDescInput").val();
        var $btn = $("#btnGuardarDesc").prop("disabled", true);
        AX.enviarJSON("endpoints/kanban/editar_descripcion.php", {
            proyecto_id: detalleId, tarea_id: dtTareaActual, descripcion: texto
        }).then(function (res) {
            if (res.ok && res.data) {
                pintarDescripcion(res.data.descripcion || "");
                if (res.data.updated_at) { $("#dtActualizada").text(AX.formatearFecha(res.data.updated_at)); }
            } else {
                AX.errorFormulario("#dtDescError", res.mensaje || "No se pudo guardar la descripción.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#dtDescError", "No se pudo guardar la descripción.");
            $btn.prop("disabled", false);
        });
    }

    // --- Responsables (asignados como chips + select para asignar) --
    // Candidatos = equipo del proyecto + cualquier responsable que ya no
    // este en el equipo (para poder quitarlo).
    function candidatosResponsables(resp) {
        var pool = dtEquipo.slice();
        (resp || []).forEach(function (r) {
            if (!pool.some(function (u) { return u.id === r.id; })) { pool.push(r); }
        });
        return pool;
    }

    // Pinta los responsables asignados (chips con boton quitar) y un <select>
    // con los integrantes del equipo aun NO asignados.
    function pintarResponsables(resp) {
        var pool = candidatosResponsables(resp);
        var $c = $("#dtResponsables");
        if (!pool.length) {
            $c.html('<span class="text-muted">El proyecto no tiene equipo asignado. ' +
                    'Agrega integrantes al proyecto para poder asignar responsables.</span>');
            return;
        }

        function nombreDe(id) {
            for (var i = 0; i < pool.length; i++) {
                if (pool[i].id === id) { return pool[i].nombre_completo; }
            }
            return id;
        }

        // Chips de los ya asignados, cada uno con boton para quitar.
        var asignados = dtResponsablesIds.length
            ? '<ul class="kanban-resp__asignados">' + dtResponsablesIds.map(function (id) {
                return '<li class="kanban-resp__chip"><i class="bi bi-person" aria-hidden="true"></i> ' +
                       AX.escaparHtml(nombreDe(id)) +
                       '<button type="button" class="kanban-resp__quitar" data-usuario-id="' + AX.escaparHtml(id) +
                       '" title="Quitar" aria-label="Quitar"><i class="bi bi-x-lg" aria-hidden="true"></i></button></li>';
            }).join("") + "</ul>"
            : '<p class="kanban-resp__vacio text-muted">Sin responsables asignados.</p>';

        // Select con los integrantes NO asignados.
        var libres = pool.filter(function (u) { return dtResponsablesIds.indexOf(u.id) === -1; });
        var select = '<select class="form-select form-select-sm kanban-resp__select"' +
                     (libres.length ? "" : " disabled") + '>' +
                     '<option value="">' + (libres.length ? "Asignar a…" : "Todos asignados") + "</option>" +
                     libres.map(function (u) {
                         return '<option value="' + AX.escaparHtml(u.id) + '">' + AX.escaparHtml(u.nombre_completo) + "</option>";
                     }).join("") + "</select>";

        $c.html('<div class="kanban-resp">' + asignados + select + "</div>");
    }

    // Asigna o quita un responsable y sincroniza con el backend.
    //   accion: "asignar" | "quitar"
    function cambiarResponsable(usuarioId, accion) {
        if (!detalleId || !dtTareaActual || !usuarioId) { return; }
        var nuevo = dtResponsablesIds.slice();
        var idx = nuevo.indexOf(usuarioId);
        if (accion === "asignar" && idx === -1) { nuevo.push(usuarioId); }
        else if (accion === "quitar" && idx !== -1) { nuevo.splice(idx, 1); }
        else { return; }

        $("#dtResponsables").find("select, button").prop("disabled", true);
        AX.enviarJSON("endpoints/kanban/asignar_responsables.php", {
            proyecto_id: detalleId, tarea_id: dtTareaActual, usuarios: nuevo
        }).then(function (res) {
            if (res.ok && res.data) {
                var lista = res.data.responsables || [];
                dtResponsablesIds = lista.map(function (u) { return u.id; });
                pintarResponsables(lista);
            } else {
                AX.toast(res.mensaje || "No se pudo actualizar los responsables.", "error");
                $("#dtResponsables").find("select, button").prop("disabled", false);
            }
        }).catch(function () {
            AX.toast("No se pudo actualizar los responsables.", "error");
            $("#dtResponsables").find("select, button").prop("disabled", false);
        });
    }

    // --- Comentarios (panel lateral del detalle) -------------------
    // HTML de un comentario: autor + fecha/hora + texto (todo escapado).
    function comentarioHtml(c) {
        return '<li class="kanban-coment__item">' +
                   '<div class="kanban-coment__meta">' +
                       '<span class="kanban-coment__autor">' + AX.escaparHtml(c.autor || "—") + "</span>" +
                       '<span class="kanban-coment__fecha">' + AX.formatearFechaHora(c.fecha) + "</span>" +
                   "</div>" +
                   '<p class="kanban-coment__texto">' + AX.escaparHtml(c.comentario) + "</p>" +
               "</li>";
    }

    function pintarComentarios(coms) {
        $("#dtComentarios").html(coms && coms.length
            ? coms.map(comentarioHtml).join("")
            : '<li class="kanban-coment__vacio text-muted">Aún no hay comentarios.</li>');
    }

    function enviarComentario() {
        var texto = $.trim($("#dtNuevoComentario").val());
        if (!texto) { return AX.errorFormulario("#dtComentarioError", "Escribe un comentario."); }
        if (!detalleId || !dtTareaActual) { return; }

        var $btn = $("#btnComentar").prop("disabled", true);
        AX.enviarJSON("endpoints/kanban/crear_comentario.php", {
            proyecto_id: detalleId, tarea_id: dtTareaActual, comentario: texto
        }).then(function (res) {
            if (res.ok && res.data) {
                // Se agrega arriba (la lista va de mas reciente a mas antiguo).
                $("#dtComentarios").find(".kanban-coment__vacio").remove();
                $("#dtComentarios").prepend(comentarioHtml(res.data));
                $("#dtNuevoComentario").val("");
                $("#dtComentarioError").addClass("d-none").text("");
            } else {
                AX.errorFormulario("#dtComentarioError", res.mensaje || "No se pudo agregar el comentario.");
            }
            $btn.prop("disabled", false);
        }).catch(function () {
            AX.errorFormulario("#dtComentarioError", "No se pudo agregar el comentario.");
            $btn.prop("disabled", false);
        });
    }

    // --- Gestion de columnas (crear / renombrar / eliminar) --------
    // Lee id y nombre de la columna desde el <section> que contiene el boton.
    function datosColumna(el) {
        var $col = $(el).closest(".kanban__col");
        return { id: $col.attr("data-columna-id"), nombre: $col.attr("data-columna-nombre") };
    }

    // Validador comun para el nombre de columna en los prompts de Swal.
    function validarNombreColumna(v) {
        if (!v || !v.trim()) { return "Escribe un nombre para la columna."; }
        if (v.trim().length > 100) { return "El nombre es demasiado largo (máximo 100)."; }
    }

    // Reutiliza la respuesta de un endpoint de columna: recarga o avisa el error.
    function trasCambioColumna(res, mensajeExito, mensajeError) {
        if (res.ok) {
            cargarTablero(detalleId);
            AX.toast(mensajeExito, "exito");
        } else {
            AX.error(res.mensaje || mensajeError);
        }
    }

    function crearColumna() {
        if (!detalleId || typeof Swal === "undefined") { return; }
        Swal.fire({
            title: "Nueva columna",
            input: "text",
            inputLabel: "Nombre de la columna",
            inputPlaceholder: "Ej: En pruebas",
            showCancelButton: true,
            confirmButtonText: "Crear",
            cancelButtonText: "Cancelar",
            inputValidator: validarNombreColumna
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/kanban/crear_columna.php", {
                proyecto_id: detalleId, nombre: r.value.trim()
            }).then(function (res) {
                trasCambioColumna(res, "Columna creada.", "No se pudo crear la columna.");
            }).catch(function () { AX.error("No se pudo crear la columna."); });
        });
    }

    function renombrarColumna(id, nombreActual) {
        if (!detalleId || typeof Swal === "undefined") { return; }
        Swal.fire({
            title: "Renombrar columna",
            input: "text",
            inputLabel: "Nuevo nombre",
            inputValue: nombreActual || "",
            showCancelButton: true,
            confirmButtonText: "Guardar",
            cancelButtonText: "Cancelar",
            inputValidator: validarNombreColumna
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/kanban/renombrar_columna.php", {
                proyecto_id: detalleId, columna_id: id, nombre: r.value.trim()
            }).then(function (res) {
                trasCambioColumna(res, "Columna renombrada.", "No se pudo renombrar la columna.");
            }).catch(function () { AX.error("No se pudo renombrar la columna."); });
        });
    }

    function eliminarColumna(id, nombre) {
        if (!detalleId) { return; }
        AX.confirmar({
            titulo: "Eliminar columna",
            texto: 'Se eliminará la columna "' + nombre + '". Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/kanban/eliminar_columna.php", {
                proyecto_id: detalleId, columna_id: id
            }).then(function (res) {
                trasCambioColumna(res, "Columna eliminada.", "No se pudo eliminar la columna.");
            }).catch(function () { AX.error("No se pudo eliminar la columna."); });
        });
    }

    // ===============================================================
    //  Archivar tarjetas (fuera del tablero, sin borrarlas)
    // ===============================================================

    // Muestra/oculta el boton "Archivadas": solo tiene sentido en el tablero.
    function actualizarBotonArchivadas() {
        var enTablero = $("#tabbtn-kanban").hasClass("active");
        $("#btnVerArchivadas").toggleClass("d-none", !enTablero);
    }

    // Archiva (o restaura) una tarjeta y refresca lo que corresponda.
    function archivarTarea(tareaId, archivar) {
        if (!detalleId || !tareaId) { return; }
        return AX.enviarJSON("endpoints/kanban/archivar_tarea.php", {
            proyecto_id: detalleId, tarea_id: tareaId, archivar: !!archivar
        }).then(function (res) {
            if (res.ok) {
                cargarTablero(detalleId);              // el tablero refleja el cambio
                AX.toast(archivar ? "Tarea archivada." : "Tarea restaurada.", "exito");
            } else {
                AX.toast(res.mensaje || "No se pudo actualizar la tarea.", "error");
            }
            return res;
        }).catch(function () {
            AX.toast("No se pudo actualizar la tarea.", "error");
            return { ok: false };
        });
    }

    // Archiva la tarea abierta en el modal de detalle y cierra ese modal.
    function archivarTareaActual() {
        if (!dtTareaActual) { return; }
        var $btn = $("#btnArchivarTarea").prop("disabled", true);
        archivarTarea(dtTareaActual, true).then(function (res) {
            $btn.prop("disabled", false);
            if (res && res.ok) { modalTareaDetalle.cerrar(); }
        });
    }

    // HTML de una fila del panel de archivadas.
    function archivadaHtml(t) {
        var resp = t.responsables
            ? '<span class="kanban-archivadas__resp"><i class="bi bi-person" aria-hidden="true"></i> ' +
              AX.escaparHtml(t.responsables) + "</span>"
            : "";
        return '<li class="kanban-archivadas__item" data-tarea-id="' + AX.escaparHtml(t.id) + '">' +
                   '<div class="kanban-archivadas__info">' +
                       '<span class="kanban__prioridad ' + claseParaPrioridad(t.prioridad) + '">' +
                           AX.escaparHtml(t.prioridad || "Media") + "</span>" +
                       '<span class="kanban-archivadas__titulo">' + AX.escaparHtml(t.titulo) + "</span>" +
                       '<span class="kanban-archivadas__meta">' +
                           AX.escaparHtml(t.columna || "—") +
                           " · Archivada el " + AX.formatearFecha(t.fecha_archivado) +
                       "</span>" +
                       resp +
                   "</div>" +
                   '<button type="button" class="btn btn-outline-secondary btn-sm" data-rol="restaurar">' +
                       '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar</button>' +
               "</li>";
    }

    function pintarArchivadas(tareas) {
        $("#listaArchivadas").html(tareas && tareas.length
            ? tareas.map(archivadaHtml).join("")
            : '<li class="kanban-archivadas__vacio text-muted">No hay tarjetas archivadas.</li>');
    }

    // Carga y abre el panel de archivadas del proyecto.
    function abrirArchivadas() {
        if (!detalleId || !modalArchivadas) { return; }
        $("#listaArchivadas").html('<li class="kanban-archivadas__vacio text-muted">Cargando…</li>');
        modalArchivadas.abrir();
        $.ajax({
            url: "endpoints/kanban/tareas_archivadas.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { proyecto_id: detalleId }
        }).done(function (res) {
            if (res && res.ok) { pintarArchivadas(res.data.tareas || []); }
            else { $("#listaArchivadas").html('<li class="kanban-archivadas__vacio text-muted">No se pudo cargar la lista.</li>'); }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar la lista.";
            $("#listaArchivadas").html('<li class="kanban-archivadas__vacio text-muted">' + AX.escaparHtml(msg) + "</li>");
        });
    }

    // Restaura una tarjeta desde el panel de archivadas (la quita de la lista).
    function restaurarArchivada(tareaId, $item) {
        $item.find("button").prop("disabled", true);
        archivarTarea(tareaId, false).then(function (res) {
            if (res && res.ok) {
                $item.remove();
                if (!$("#listaArchivadas").children(".kanban-archivadas__item").length) {
                    pintarArchivadas([]);
                }
            } else {
                $item.find("button").prop("disabled", false);
            }
        });
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
    $("#btnNuevoProyecto").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarProyecto", enviarFormulario);
    AX.vincularVolver(volverAlListado);

    // Kanban: acciones de columna (delegadas por data-rol).
    $(document).on("click", "#kanbanTablero [data-rol='add-tarea']", function () {
        var c = datosColumna(this); abrirModalTarea(c.id, c.nombre);
    });
    $(document).on("click", "#kanbanTablero [data-rol='renombrar']", function () {
        var c = datosColumna(this); renombrarColumna(c.id, c.nombre);
    });
    $(document).on("click", "#kanbanTablero [data-rol='eliminar-col']", function () {
        var c = datosColumna(this); eliminarColumna(c.id, c.nombre);
    });
    $(document).on("click", "#kanbanAddCol", crearColumna);
    $(document).on("click", "#btnGuardarTarea", guardarTarea);

    // Archivar / restaurar tarjetas.
    $(document).on("click", "#btnArchivarTarea", archivarTareaActual);
    $(document).on("click", "#btnVerArchivadas", abrirArchivadas);
    $(document).on("click", "#listaArchivadas [data-rol='restaurar']", function () {
        var $item = $(this).closest(".kanban-archivadas__item");
        restaurarArchivada($item.attr("data-tarea-id"), $item);
    });

    // Check de la tarjeta -> marca/desmarca como completada (no abre el detalle).
    $(document).on("click", "#kanbanTablero [data-rol='completar']", function (e) {
        e.stopPropagation();
        var $card = $(this).closest(".kanban__card");
        completarTarea($card.attr("data-tarea-id"), !$(this).hasClass("is-completada"), $(this));
    });

    // Clic en una tarjeta -> abre su detalle. Se ignora el "clic" sintetico que
    // dispara el navegador justo despues de soltar un arrastre (Sortable) y el
    // clic sobre el check de completado.
    $(document).on("click", "#kanbanTablero .kanban__card", function (e) {
        if (Date.now() - kanbanDragFin < 250) { return; }
        if ($(e.target).closest("[data-rol='completar']").length) { return; }
        var id = this.getAttribute("data-tarea-id");
        if (id) { abrirDetalleTarea(id); }
    });

    // Enviar un comentario del detalle de la tarea.
    $(document).on("submit", "#formComentario", function (e) {
        e.preventDefault();
        enviarComentario();
    });

    // Subir un adjunto del detalle de la tarea.
    $(document).on("submit", "#formAdjunto", function (e) {
        e.preventDefault();
        subirAdjunto();
    });

    // Al cerrar el detalle, refresca el tablero para reflejar cambios de la
    // tarjeta (responsables asignados, prioridad, descripción, etc.).
    $("#modalTareaDetalle").on("hidden.bs.modal", function () {
        if (detalleId) { cargarTablero(detalleId); }
    });

    // Edición de la descripción (editar / guardar / cancelar).
    $(document).on("click", "#btnEditarDesc", abrirEditorDescripcion);
    $(document).on("click", "#btnCancelarDesc", cancelarEditorDescripcion);
    $(document).on("click", "#btnGuardarDesc", guardarDescripcion);

    // Asignar un responsable eligiéndolo del select.
    $(document).on("change", "#dtResponsables .kanban-resp__select", function () {
        var id = this.value;
        if (id) { cambiarResponsable(id, "asignar"); }
    });
    // Quitar un responsable con la "×" de su chip.
    $(document).on("click", "#dtResponsables .kanban-resp__quitar", function () {
        cambiarResponsable($(this).attr("data-usuario-id"), "quitar");
    });

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
            } else { AX.toast("Eliminación de proyectos: disponible próximamente.", "info"); }
            return;
        }
        if (reg) { abrirDetalle(reg.id); }
    });

    msEquipo   = AX.multiselect("#fpEquipo");
    msDominios = AX.multiselect("#fpDominios", { vacio: "Sin dominio" });
    msHosting  = AX.multiselect("#fpHosting", { vacio: "Sin hosting" });
    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
