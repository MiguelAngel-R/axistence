/* =====================================================================
   AXISTENCE - clientes.js
   Logica del modulo Clientes. Carga el listado por AJAX, pinta la tabla
   y delega el paginador en general.js (AX). El alta y la edicion se
   resuelven en un MODAL dinamico (mismo modal para ambos), cuya apertura,
   cierre, limpieza y transferencia de datos usan las utilidades genericas
   de general.js (AX.modal / AX.poblarFormulario / AX.leerFormulario).

   El formulario MUTA segun el tipo de cliente:
     - Persona Natural  -> Nombres + Apellidos + Tipo de identificacion.
     - Persona Juridica -> Razon social + Tipo de identificacion fijo (NIT).

   NOTA: la accion "Eliminar" queda solo maquetada (boton en la fila);
   por regla de negocio de esta fase NO se implementa borrado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyClientes");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var COLUMNAS = 7;
    var modo = "crear";      // "crear" | "editar"
    var editandoId = null;   // id del cliente en edicion
    var detalleId = null;    // id del cliente mostrado en el detalle
    var dropFechas = null;   // controlador del dropdown de fechas (general.js)
    var modalCliente = AX.modal("#modalCliente");
    var modalProyectoCli = AX.modal("#modalProyectoCliente");
    var modalContacto = AX.modal("#modalContacto");
    var modoContacto = "crear";     // "crear" | "editar" (mismo modal para ambos)
    var contactoEditandoId = null;  // id del contacto en edicion (null en alta)
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

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

    // Abre un <tr> con data-fecha (aaaa-mm-dd) para el filtro por rango.
    function trFecha(valor) {
        var f = valor ? String(valor).substring(0, 10) : "";
        return '<tr data-fecha="' + AX.escaparHtml(f) + '">';
    }

    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/clientes/listar.php",
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
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los clientes.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];

        if (!items.length) {
            $tbody.html(filaVacia("No hay clientes registrados."));
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
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    // Identificacion mostrada: "NIT 900.123 " -> tipo + numero.
    function identificacion(c) {
        var tipo = c.tipo_identificacion ? c.tipo_identificacion + " " : "";
        return AX.escaparHtml(tipo + (c.numero_identificacion || ""));
    }

    function fila(c) {
        var claseEstado = c.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
        // Los datos de la fila quedan embebidos para editar sin consultar la BD.
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(c)) + '">' +
            "<td>" + AX.escaparHtml(c.nombre_razon_social) + "</td>" +
            "<td>" + identificacion(c) + "</td>" +
            "<td>" + AX.escaparHtml(c.email_general || "—") + "</td>" +
            "<td>" + AX.escaparHtml(c.telefono_principal || "—") + "</td>" +
            '<td><span class="badge-estado ' + claseEstado + '">' + AX.escaparHtml(c.estado) + "</span></td>" +
            "<td>" + AX.formatearFecha(c.created_at) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(c.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(c.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(c.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    // --- Busqueda con retardo (debounce) ---------------------------
    var temporizador;
    $("#buscarCliente").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            estado.buscar = valor.trim();
            estado.pagina = 1;
            cargar();
        }, 350);
    });

    // =================================================================
    //  Modal de alta / edicion (el mismo modal se reutiliza)
    // =================================================================

    // Aplica la mutacion del formulario segun el tipo de cliente elegido:
    // muestra/oculta los grupos de campos, ajusta su obligatoriedad y, para
    // persona juridica, fija el tipo de identificacion en NIT.
    function aplicarTipoCliente(tipo) {
        var esNatural  = (tipo === "Persona Natural");
        var esJuridica = (tipo === "Persona Jurídica");

        $(".grupo-natural").toggleClass("d-none", !esNatural);
        $(".grupo-juridica").toggleClass("d-none", !esJuridica);

        $("#fcNombres, #fcApellidos").prop("required", esNatural);
        $("#fcRazonSocial").prop("required", esJuridica);

        var $tipoIdent = $("#fcTipoIdent");
        if (esJuridica) {
            // Persona juridica: identificacion fija en NIT (no editable).
            $tipoIdent.val("NIT").prop("disabled", true);
        } else {
            $tipoIdent.prop("disabled", false);
        }
    }

    // cliente: objeto de la fila cuando es edicion; null cuando es alta.
    function abrirModal(nuevoModo, cliente) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && cliente) ? cliente.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formCliente", "#formClienteError");
        $("#btnGuardarCliente").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");
        $("#formClienteTitulo").text(esEditar ? "Editar cliente" : "Nuevo cliente");

        if (esEditar && cliente) {
            // Rellena por atributo name (incluye el radio tipo_cliente y los
            // selects/inputs). Luego se aplica la mutacion del tipo elegido.
            AX.poblarFormulario("#formCliente", cliente);
            aplicarTipoCliente(cliente.tipo_cliente);
        } else {
            // Alta: por defecto Persona Natural (el usuario puede cambiarlo).
            $("#fcTipoNatural").prop("checked", true);
            $("#fcEstado").val("Activo");
            aplicarTipoCliente("Persona Natural");
        }

        modalCliente.abrir();
        $("#fcNumIdent").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formClienteError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var lectura  = AX.leerFormulario("#formCliente");
        var tipo     = lectura.tipo_cliente;

        if (tipo !== "Persona Natural" && tipo !== "Persona Jurídica") {
            return errorFormulario("Selecciona el tipo de cliente.");
        }

        // Campos comunes.
        var datos = {
            tipo_cliente:          tipo,
            numero_identificacion: $.trim(lectura.numero_identificacion || ""),
            email_general:         $.trim(lectura.email_general || ""),
            telefono_principal:    $.trim(lectura.telefono_principal || ""),
            direccion:             $.trim(lectura.direccion || ""),
            estado:                lectura.estado || "Activo"
        };

        // Campos condicionales segun el tipo.
        if (tipo === "Persona Natural") {
            datos.nombres             = $.trim(lectura.nombres || "");
            datos.apellidos           = $.trim(lectura.apellidos || "");
            datos.razon_social        = "";
            datos.tipo_identificacion = lectura.tipo_identificacion || "Cédula";
            if (!datos.nombres || !datos.apellidos) {
                return errorFormulario("Los nombres y apellidos son obligatorios.");
            }
        } else {
            datos.razon_social        = $.trim(lectura.razon_social || "");
            datos.nombres             = "";
            datos.apellidos           = "";
            datos.tipo_identificacion = "NIT"; // fijo para persona juridica
            if (!datos.razon_social) {
                return errorFormulario("La razon social es obligatoria.");
            }
        }

        if (!datos.numero_identificacion) {
            return errorFormulario("El numero de identificacion es obligatorio.");
        }
        if (datos.email_general && !EMAIL_RE.test(datos.email_general)) {
            return errorFormulario("El correo no tiene un formato valido.");
        }

        var url = esEditar ? "endpoints/clientes/actualizar.php"
                           : "endpoints/clientes/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarCliente").prop("disabled", true);
        $.ajax({
            url: url,
            method: "POST",
            contentType: "application/json",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalCliente.cerrar();
                cargar(); // recarga la lista
                AX.exito(esEditar ? "El cliente se actualizó correctamente." : "El cliente se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el cliente.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el cliente.");
            $btn.prop("disabled", false);
        });
    }

    // Cambio de tipo de cliente dentro del formulario -> muta los campos.
    $("#formCliente").on("change", 'input[name="tipo_cliente"]', function () {
        aplicarTipoCliente(this.value);
    });

    // Abrir el modal de alta.
    $("#btnNuevoCliente").on("click", function () { abrirModal("crear"); });

    // Boton Guardar del modal (Cancelar/cerrar los maneja data-bs-dismiss).
    $("#btnGuardarCliente").on("click", enviarFormulario);

    // Clic en la tabla: los botones de accion mandan; el resto de la fila abre
    // el detalle. Editar reutiliza los datos ya cargados en la fila; eliminar
    // queda solo maquetado en esta fase (sin logica de borrado).
    $tbody.on("click", "tr", function (e) {
        var $btn = $(e.target).closest("[data-accion]");
        var cliente = AX.datosFila(this);
        if ($btn.length) {
            var accion = $btn.data("accion");
            if (accion === "ver") {
                if (cliente) { abrirDetalle(cliente.id); }
            } else if (accion === "editar") {
                if (cliente) { abrirModal("editar", cliente); }
                else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
            } else { // eliminar (solo maquetado)
                AX.toast("Eliminación de clientes: disponible próximamente.", "info");
            }
            return;
        }
        // Clic en cualquier otra parte de la fila -> detalle.
        if (cliente) { abrirDetalle(cliente.id); }
    });

    // =================================================================
    //  Vista de DETALLE consolidada (viewClientes)
    // =================================================================

    // Alterna listado <-> detalle y oculta la toolbar cuando esta el detalle.
    function mostrar($vista) {
        $vistaListado.addClass("d-none");
        $vistaDetalle.addClass("d-none");
        $vista.removeClass("d-none");
        $(".toolbar").toggleClass("d-none", $vista[0] !== $vistaListado[0]);
    }

    // <tbody> de la tabla de la pestana activa (para aplicar el filtro).
    function tbodyActivo() {
        return $("#detTabsContent .tab-pane.active tbody:visible");
    }

    // Aplica el buscador dinamico + rango de fechas a la tabla activa.
    function aplicarFiltrosDetalle() {
        var f = dropFechas ? dropFechas.valores() : { desde: "", hasta: "" };
        AX.filtrarTabla(tbodyActivo(), { texto: $("#detBuscar").val(), desde: f.desde, hasta: f.hasta });
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

    // Cablea (una sola vez) el buscador, el dropdown de fechas y el cambio de
    // pestana. Reutiliza las utilidades de general.js.
    function inicializarFiltrosDetalle() {
        var t;
        $("#detBuscar").on("input", function () { clearTimeout(t); t = setTimeout(aplicarFiltrosDetalle, 200); });
        dropFechas = AX.dropdownFechas("#detFechas", { onAplicar: aplicarFiltrosDetalle, onLimpiar: aplicarFiltrosDetalle });
        $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", aplicarFiltrosDetalle);
    }

    // Pide el detalle del cliente activo (detalleId) y lo entrega a onOk. Se usa
    // tanto para la apertura como para refrescar tras un alta (p. ej. contacto).
    function solicitarDetalle(onOk) {
        $.ajax({
            url: "endpoints/clientes/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: detalleId }
        }).done(function (res) {
            if (res && res.ok) { onOk(res.data); }
            else { AX.error((res && res.mensaje) || "No se pudo cargar el detalle del cliente."); volverAlListado(); }
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el detalle del cliente.");
            volverAlListado();
        });
    }

    function abrirDetalle(id) {
        detalleId = id;
        mostrar($vistaDetalle);
        reiniciarFiltrosDetalle();
        AX.limpiarFooter(); // sin footer en el detalle; la flecha "Volver" retorna
        window.scrollTo({ top: 0, behavior: "smooth" });
        solicitarDetalle(pintarDetalle);
    }

    // Resumen breve de recursos de un proyecto (columna "Recursos").
    function resumenRecursos(p) {
        var partes = [];
        var nd = (p.dominios || []).length;
        var nh = (p.hosting || []).length;
        if (nd) { partes.push(nd + (nd === 1 ? " dominio" : " dominios")); }
        if (nh) { partes.push(nh + " hosting"); }
        return partes.length ? partes.join(" · ") : "—";
    }

    // Rellena una <ul> del modal de proyecto; vacio -> item atenuado.
    function llenarLista($ul, items, fn) {
        if (!items || !items.length) { $ul.html('<li class="mpc-vacio">Sin registros.</li>'); return; }
        $ul.html(items.map(fn).join(""));
    }

    // Abre el modal con toda la informacion + recursos del proyecto. 'p' ya trae
    // los recursos embebidos desde endpoints/clientes/ver.php (sin 2ª llamada).
    function abrirModalProyecto(p) {
        if (!p) { return; }
        $("#mpcTitulo").text(p.nombre_proyecto || "—");
        $("#mpcEstado").text(p.estado || "—");
        var srv = (p.vps && p.vps[0]) ? p.vps[0] : null;
        $("#mpcServidor").html(srv ? AX.enlaceProducto("vps", srv.id, srv.nombre) : "—");
        $("#mpcInicio").text(p.fecha_inicio ? AX.formatearFecha(p.fecha_inicio) : "—");
        $("#mpcEntrega").text(p.fecha_entrega_estimada ? AX.formatearFecha(p.fecha_entrega_estimada) : "—");
        $("#mpcDescripcion").text(p.descripcion || "—");

        llenarLista($("#mpcEquipo"), p.equipo, function (u) {
            var rol = u.rol ? ' <span class="mpc-rol">· ' + AX.escaparHtml(u.rol) + "</span>" : "";
            return "<li>" + AX.escaparHtml(u.nombre) + rol + "</li>";
        });
        llenarLista($("#mpcDominios"), p.dominios, function (x) {
            return "<li>" + AX.enlaceProducto("dominios", x.id, x.nombre) + "</li>";
        });
        llenarLista($("#mpcHosting"), p.hosting, function (x) {
            var etiqueta = x.nombre + (x.espacio ? " · " + x.espacio : "");
            return "<li>" + AX.enlaceProducto("hosting", x.id, etiqueta) + "</li>";
        });
        llenarLista($("#mpcNotas"), p.notas, function (n) {
            return '<li><span class="mpc-nota-fecha">' + AX.formatearFecha(n.fecha) + "</span> " +
                   AX.escaparHtml(n.nota) +
                   (n.autor ? ' <span class="mpc-rol">— ' + AX.escaparHtml(n.autor) + "</span>" : "") + "</li>";
        });

        $("#mpcAbrir").attr("href", "index.php?vista=proyectos&detalle=" + encodeURIComponent(p.id));
        modalProyectoCli.abrir();
    }

    function pintarDetalle(d) {
        var c = d.cliente || {};

        // Encabezado + estado.
        $("#detTitulo").text(c.nombre_razon_social || "—");
        var claseEstado = c.estado === "Activo" ? "badge-estado--activo" : "badge-estado--inactivo";
        $("#detEstado").text(c.estado || "—").attr("class", "badge-estado " + claseEstado);

        // Dashboard superior (resumen).
        var nProy = (d.proyectos || []).length;
        var nProd = (d.vps || []).length + (d.dominios || []).length + (d.correos || []).length +
                    (d.hosting || []).length + (d.otros || []).length;
        $("#dashTipo").text(c.tipo_cliente || "—");
        $("#dashIdent").text(((c.tipo_identificacion ? c.tipo_identificacion + " " : "") + (c.numero_identificacion || "")) || "—");
        $("#dashProyectos").text(nProy);
        $("#dashProductos").text(nProd);

        // Informacion general.
        $("#detTipo").text(c.tipo_cliente || "—");
        $("#detRazon").text(c.razon_social || "—");
        $("#detNombres").text(c.nombres || "—");
        $("#detApellidos").text(c.apellidos || "—");
        $("#detTipoIdent").text(c.tipo_identificacion || "—");
        $("#detNumIdent").text(c.numero_identificacion || "—");
        $("#detCorreo").text(c.email_general || "—");
        $("#detTelefono").text(c.telefono_principal || "—");
        $("#detDireccion").text(c.direccion || "—");
        $("#detEstadoInfo").text(c.estado || "—");
        $("#detCreado").text(AX.formatearFecha(c.created_at));
        $("#detActualizado").text(AX.formatearFecha(c.updated_at));

        // Proyectos asociados (pestaña unificada con productos). Cada fila es
        // clicable y abre el modal con toda la info + recursos del proyecto.
        pintarSeccion($("#detProyectos"), d.proyectos, 5, function (p) {
            var srv = (p.vps && p.vps[0]) ? p.vps[0].nombre : "—";
            return '<tr class="fila-proyecto" data-registro="' + AX.escaparHtml(JSON.stringify(p)) +
                '" data-fecha="' + AX.escaparHtml(p.fecha_inicio ? String(p.fecha_inicio).substring(0, 10) : "") + '">' +
                '<td class="fila-proyecto__nombre">' + AX.escaparHtml(p.nombre_proyecto) +
                    ' <i class="bi bi-box-arrow-up-right fila-proyecto__ico" aria-hidden="true"></i></td>' +
                "<td>" + AX.escaparHtml(p.estado) + "</td>" +
                "<td>" + AX.escaparHtml(srv) + "</td>" +
                "<td>" + AX.escaparHtml(resumenRecursos(p)) + "</td>" +
                "<td>" + (p.fecha_inicio ? AX.formatearFecha(p.fecha_inicio) : "—") + "</td></tr>";
        });

        // Personas de contacto. Cada fila embebe sus datos (data-registro) para
        // que "Actualizar" pueble el formulario sin volver a consultar la BD.
        pintarSeccion($("#detContactos"), d.contactos, 6, function (x) {
            var principal = x.es_contacto_principal ? '<i class="bi bi-star-fill"></i> Sí' : "No";
            return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(x)) + '">' +
                "<td>" + AX.escaparHtml(x.nombre_completo) + "</td>" +
                "<td>" + AX.escaparHtml(x.cargo_puesto || "—") + "</td>" +
                "<td>" + AX.escaparHtml(x.email || "—") + "</td>" +
                "<td>" + AX.escaparHtml(x.telefono_movil || x.telefono_fijo || "—") + "</td>" +
                "<td>" + principal + "</td>" +
                '<td class="tabla-acciones">' +
                    '<button type="button" class="btn-icono" data-accion="editar-contacto" title="Actualizar"><i class="bi bi-pencil"></i></button>' +
                "</td></tr>";
        });

        // Notas (con autor).
        pintarSeccion($("#detNotas"), d.notas, 3, function (x) {
            var f = x.fecha ? String(x.fecha).substring(0, 10) : "";
            return '<tr data-fecha="' + AX.escaparHtml(f) + '">' +
                "<td>" + AX.formatearFecha(x.fecha) + "</td>" +
                "<td>" + AX.escaparHtml(x.autor || "—") + "</td>" +
                "<td>" + AX.escaparHtml(x.nota) + "</td></tr>";
        });

        // Historial de auditoria del cliente.
        pintarSeccion($("#detLogs"), d.logs, 5, function (x) {
            return trFecha(x.fecha_evento) +
                "<td>" + AX.formatearFecha(x.fecha_evento) + "</td>" +
                "<td>" + AX.escaparHtml(x.usuario || "—") + "</td>" +
                "<td>" + AX.escaparHtml(x.tipo_accion) + "</td>" +
                "<td>" + AX.escaparHtml(x.modulo_afectado) + "</td>" +
                "<td>" + AX.escaparHtml(x.descripcion) + "</td></tr>";
        });

        // Tras (re)pintar, se aplican los filtros vigentes a la tabla activa.
        aplicarFiltrosDetalle();
    }

    function volverAlListado() {
        mostrar($vistaListado);
        detalleId = null;
        cargar(); // recarga la lista y restaura el paginador en el footer
    }

    // =================================================================
    //  Alta de persona de contacto (pestaña Contactos del detalle)
    // =================================================================

    // contacto: objeto de la fila cuando es edicion; null/undefined cuando es alta.
    // El mismo modal sirve para ambos; en edicion se puebla desde la fila.
    function abrirModalContacto(contacto) {
        if (!detalleId) { return; } // solo con un detalle abierto
        modoContacto = contacto ? "editar" : "crear";
        contactoEditandoId = contacto ? contacto.id : null;
        var esEditar = (modoContacto === "editar");

        AX.limpiarFormulario("#formContacto", "#formContactoError");
        $("#btnGuardarContacto").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Agregar");
        $("#formContactoTitulo").text(esEditar ? "Editar contacto" : "Nuevo contacto");

        // En edicion se pueblan los campos por atributo name (incluye el check
        // "es_contacto_principal"). Reutiliza el helper generico de general.js.
        if (esEditar) { AX.poblarFormulario("#formContacto", contacto); }

        modalContacto.abrir();
        $("#fkNombres").trigger("focus");
    }

    function enviarContacto() {
        var lectura   = AX.leerFormulario("#formContacto");
        var nombres   = $.trim(lectura.nombres || "");
        var apellidos = $.trim(lectura.apellidos || "");
        var email     = $.trim(lectura.email || "");

        if (!nombres || !apellidos) { return AX.errorFormulario("#formContactoError", "El nombre y el apellido del contacto son obligatorios."); }
        if (!email)  { return AX.errorFormulario("#formContactoError", "El correo del contacto es obligatorio."); }
        if (!EMAIL_RE.test(email)) { return AX.errorFormulario("#formContactoError", "El correo no tiene un formato valido."); }

        // El mismo modal sirve para alta y edicion: el modo decide el endpoint,
        // el identificador que se envia y los mensajes de exito/error.
        var esEditar = (modoContacto === "editar");

        var datos = {
            nombres:               nombres,
            apellidos:             apellidos,
            cargo_puesto:          $.trim(lectura.cargo_puesto || ""),
            email:                 email,
            telefono_movil:        $.trim(lectura.telefono_movil || ""),
            telefono_fijo:         $.trim(lectura.telefono_fijo || ""),
            es_contacto_principal: !!lectura.es_contacto_principal
        };
        if (esEditar) { datos.contacto_id = contactoEditandoId; }
        else          { datos.cliente_id  = detalleId; }

        var url       = esEditar ? "endpoints/clientes/contacto_actualizar.php"
                                 : "endpoints/clientes/contacto_crear.php";
        var msgExito  = esEditar ? "El contacto se actualizó correctamente."
                                 : "El contacto se agregó correctamente.";
        var msgError  = esEditar ? "No se pudo actualizar el contacto."
                                 : "No se pudo agregar el contacto.";

        var $btn = $("#btnGuardarContacto").prop("disabled", true);
        $.ajax({
            url: url,
            method: "POST",
            contentType: "application/json",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalContacto.cerrar();
                solicitarDetalle(pintarDetalle); // refresca la tabla sin salir de la pestaña
                AX.exito(msgExito);
            } else {
                AX.errorFormulario("#formContactoError", (res && res.mensaje) || msgError);
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formContactoError", (xhr.responseJSON && xhr.responseJSON.mensaje) || msgError);
            $btn.prop("disabled", false);
        });
    }

    // El botón vive dentro del detalle (estático en el DOM); enlace directo.
    $("#btnNuevoContacto").on("click", function () { abrirModalContacto(null); });
    $("#btnGuardarContacto").on("click", enviarContacto);

    // Clic en "Actualizar" de una fila de Contactos -> puebla el formulario con
    // los datos embebidos en la fila (sin consultar la BD) y abre el modal.
    $(document).on("click", '#detContactos [data-accion="editar-contacto"]', function () {
        var contacto = AX.datosFila(this);
        if (contacto) { abrirModalContacto(contacto); }
        else { AX.toast("No se pudieron leer los datos del contacto.", "error"); }
    });

    // Clic en una fila de la pestaña Proyectos -> modal con la info del proyecto.
    $(document).on("click", "#detProyectos tr.fila-proyecto", function (e) {
        if (AX.esClicEnEnlace && AX.esClicEnEnlace(e)) { return; }
        abrirModalProyecto(AX.datosFila(this));
    });

    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);
    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
