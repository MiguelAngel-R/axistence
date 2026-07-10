/* =====================================================================
   AXISTENCE - dominios.js
   Modulo Dominios (producto). Listado + modal de alta/edicion (con Tipo de
   administración PROPIA/TERCEROS y combobox de cuenta + modal anidado para
   crear cuentas de acceso con 2FA) + vista de DETALLE (viewProducto) con
   dashboard y pestañas: Información · DNS · A/AAAA · MX · TXT · CNAME ·
   Historial · Notas. Las pestañas de tipo son vistas filtradas de la unica
   tabla de registros DNS. Reutiliza los helpers de general.js.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyDominios");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm = AX.modal("#modalDominio");
    var modalCuenta = AX.modal("#modalNuevaCuenta");
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { proveedores, vps, clientes, usuarios }
    var dropFechas = null;
    var cbCuenta = null;       // combobox de cuenta (general.js)
    var VPS_EXTERNO = "__externo__";   // valor centinela de la opcion "Servidor externo"

    function money(v) {
        var n = parseFloat(v);
        if (isNaN(n)) { return "—"; }
        return "$ " + n.toLocaleString("es-CO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' +
               AX.escaparHtml(mensaje) + "</td></tr>";
    }

    function pintarSeccion($cuerpo, items, columnas, filaFn) {
        if (!$cuerpo.length) { return; }
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

    // --- Criticidad de las notas (badge + tinte de fila), igual que en VPS ---
    var CRIT_CLASES = { "Información": "informacion", "Advertencia": "advertencia", "Importante": "importante", "Crítica": "critica" };

    function critBadge(criticidad) {
        var cls = CRIT_CLASES[criticidad] || "informacion";
        return '<span class="crit-badge crit-badge--' + cls + '">' + AX.escaparHtml(criticidad || "Información") + "</span>";
    }

    // Atributo class para la fila segun criticidad ("" para Información).
    function critClaseFila(criticidad) {
        var cls = CRIT_CLASES[criticidad] || "informacion";
        return cls === "informacion" ? "" : ' class="nota-row--' + cls + '"';
    }

    // ===============================================================
    //  Listado
    // ===============================================================
    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/dominios/listar.php",
            method: "GET", dataType: "json", xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los dominios.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay dominios registrados.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(d) {
        var servidorHtml = d.vps_id ? AX.enlaceProducto("vps", d.vps_id, d.vps)
                                    : AX.escaparHtml(d.vps_externa || "—");
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(d)) + '">' +
            "<td>" + AX.escaparHtml(d.nombre_dominio) + "</td>" +
            "<td>" + AX.escaparHtml(d.proveedor) + "</td>" +
            "<td>" + servidorHtml + "</td>" +
            "<td>" + AX.formatearFecha(d.fecha_vencimiento) + "</td>" +
            '<td class="col-precio">' + money(d.precio_venta) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(d.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(d.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(d.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarDominio").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Opciones y cuentas del formulario
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#fdProveedor").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/dominios/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { proveedores: [], vps: [], clientes: [], usuarios: [] };
                $("#fdProveedor").html('<option value="">Seleccione…</option>' +
                    (opciones.proveedores || []).map(function (p) {
                        return '<option value="' + AX.escaparHtml(p.id) + '">' + AX.escaparHtml(p.nombre_proveedor) + "</option>";
                    }).join(""));
                // Los servidores dependen del proveedor: se cargan al elegirlo.
                cargarVpsDeProveedor("");
                // Cliente unico del dominio (opcional).
                $("#fdCliente").html('<option value="">— Sin cliente —</option>' +
                    (opciones.clientes || []).map(function (c) {
                        return '<option value="' + AX.escaparHtml(c.id) + '">' + AX.escaparHtml(c.nombre_razon_social) + "</option>";
                    }).join(""));
                if (despues) { despues(); }
            } else {
                $("#fdProveedor").html('<option value="">No se pudieron cargar los proveedores</option>');
            }
        }).fail(function () { $("#fdProveedor").html('<option value="">Error al cargar</option>'); });
    }

    // Carga en el combobox las cuentas del proveedor indicado.
    function cargarCuentas(proveedorId, despues) {
        if (!cbCuenta) { if (despues) { despues(); } return; }
        if (!proveedorId) { cbCuenta.opciones([]); cbCuenta.limpiar(); if (despues) { despues(); } return; }
        $.ajax({
            url: "endpoints/cuentas/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { proveedor_id: proveedorId }
        }).done(function (res) {
            var lista = (res && res.ok && res.data) ? res.data : [];
            cbCuenta.opciones(lista.map(function (c) { return { id: c.id, texto: c.alias }; }));
            if (despues) { despues(); }
        }).fail(function () { cbCuenta.opciones([]); if (despues) { despues(); } });
    }

    // Rellena el <select> de servidores con los VPS del proveedor indicado y,
    // como ultima opcion, "Servidor externo". Sin proveedor, solo deja el externo.
    function cargarVpsDeProveedor(proveedorId) {
        var lista = ((opciones && opciones.vps) || []).filter(function (v) {
            return proveedorId && String(v.proveedor_id) === String(proveedorId);
        });
        var html = '<option value="">— Selecciona —</option>' +
            lista.map(function (v) {
                var etiqueta = v.referencia_vps + (v.label ? " · " + v.label : "");
                return '<option value="' + AX.escaparHtml(v.id) + '">' + AX.escaparHtml(etiqueta) + "</option>";
            }).join("") +
            '<option value="' + VPS_EXTERNO + '">Servidor externo</option>';
        $("#fdVps").html(html);
    }

    // Muta el formulario segun el servidor elegido:
    //   - VPS del sistema  -> administracion propia: aparece la cuenta de acceso.
    //   - "Servidor externo" -> terceros: aparece el campo del servidor externo.
    //   - nada seleccionado -> ambos ocultos.
    function aplicarServidor() {
        var val = $("#fdVps").val() || "";
        var externo = (val === VPS_EXTERNO);
        var propio  = (val !== "" && !externo);
        $(".grupo-vps-externa").toggleClass("d-none", !externo);
        $(".grupo-cuenta").toggleClass("d-none", !propio);
        if (!externo) { $("#fdVpsExterna").val(""); }
        if (!propio && cbCuenta) { cbCuenta.limpiar(); }
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, dominio) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && dominio) ? dominio.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formDominio", "#formDominioError");
        $("#formDominioTitulo").text(esEditar ? "Editar dominio" : "Nuevo dominio");
        $("#btnGuardarDominio").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        cargarOpciones(function () {
            if (esEditar && dominio) {
                $("#fdNombre").val(dominio.nombre_dominio || "");
                $("#fdProveedor").val(dominio.proveedor_id || "");
                $("#fdFechaRegistro").val((dominio.fecha_registro || "").substring(0, 10));
                $("#fdPrecioCompra").val(dominio.precio_compra != null ? dominio.precio_compra : "");
                $("#fdPrecioVenta").val(dominio.precio_venta != null ? dominio.precio_venta : "");
                // Cliente unico: se toma el primero de la relacion (si lo hay).
                $("#fdCliente").val(((dominio.clientes || [])[0] || {}).id || "");

                // Servidor: los VPS del proveedor del dominio + la opcion externa.
                cargarVpsDeProveedor(dominio.proveedor_id);
                if (dominio.vps_id) {
                    $("#fdVps").val(dominio.vps_id);
                } else if (dominio.vps_externa) {
                    $("#fdVps").val(VPS_EXTERNO);
                    $("#fdVpsExterna").val(dominio.vps_externa);
                } else {
                    $("#fdVps").val("");
                }
                aplicarServidor();

                // Cuenta de acceso (solo si administracion propia = hay VPS).
                cargarCuentas(dominio.proveedor_id, function () {
                    if (cbCuenta && dominio.cuenta_id) {
                        // Preselecciona por id: busca el alias en las opciones.
                        cbCuenta.agregar({ id: dominio.cuenta_id, texto: dominio.cuenta_alias || "cuenta" });
                        cbCuenta.seleccionarTexto(dominio.cuenta_alias || "");
                    }
                });
            } else {
                cargarVpsDeProveedor("");
                aplicarServidor();
                if (cbCuenta) { cbCuenta.opciones([]); cbCuenta.limpiar(); }
                $("#fdCliente").val("");
            }
        });

        modalForm.abrir();
        $("#fdProveedor").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formDominioError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var servidor = $("#fdVps").val() || "";
        var externo  = (servidor === VPS_EXTERNO);
        var propio   = (servidor !== "" && !externo);
        var cuenta   = cbCuenta ? cbCuenta.valor() : { texto: "", id: null };

        // El tipo de administracion se deriva del servidor: propio => PROPIA,
        // externo => TERCEROS. El vencimiento lo calcula el backend.
        var datos = {
            nombre_dominio:      $.trim($("#fdNombre").val()),
            proveedor_id:        $("#fdProveedor").val(),
            vps_id:              propio ? servidor : "",
            vps_externa:         externo ? $.trim($("#fdVpsExterna").val()) : "",
            tipo_administracion: propio ? "PROPIA" : "TERCEROS",
            cuenta_id:           propio ? cuenta.id : "",
            fecha_registro:      $("#fdFechaRegistro").val(),
            precio_compra:       $("#fdPrecioCompra").val() === "" ? 0 : $("#fdPrecioCompra").val(),
            precio_venta:        $("#fdPrecioVenta").val() === "" ? 0 : $("#fdPrecioVenta").val(),
            cliente_id:          $("#fdCliente").val() || ""
        };

        if (!datos.nombre_dominio || !datos.proveedor_id || !datos.fecha_registro) {
            return errorFormulario("Completa los campos obligatorios (dominio, proveedor y fecha de registro).");
        }
        if (servidor === "") {
            return errorFormulario("Selecciona un servidor o «Servidor externo».");
        }
        if (externo && !datos.vps_externa) {
            return errorFormulario("Indica el nombre del servidor externo.");
        }
        if (propio && !datos.cuenta_id) {
            return errorFormulario("Selecciona la cuenta de acceso (o créala con +).");
        }

        var url = esEditar ? "endpoints/dominios/actualizar.php" : "endpoints/dominios/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarDominio").prop("disabled", true);
        $.ajax({
            url: url, method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "El dominio se actualizó correctamente." : "El dominio se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el dominio.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el dominio.");
            $btn.prop("disabled", false);
        });
    }

    // Al cambiar el servidor elegido, se ajusta el formulario (cuenta / externo).
    $("#fdVps").on("change", aplicarServidor);
    // Al cambiar de proveedor, se recargan sus servidores y sus cuentas.
    $("#fdProveedor").on("change", function () {
        var prov = $(this).val();
        cargarVpsDeProveedor(prov);
        aplicarServidor();
        if (cbCuenta) { cbCuenta.limpiar(); }
        cargarCuentas(prov);
    });

    // ===============================================================
    //  Modal anidado: nueva cuenta de acceso (con editor de 2FA)
    // ===============================================================
    function usuariosOpciones(seleccionado) {
        return '<option value="">— Responsable —</option>' +
            ((opciones && opciones.usuarios) || []).map(function (u) {
                var sel = String(u.id) === String(seleccionado || "") ? " selected" : "";
                return '<option value="' + AX.escaparHtml(u.id) + '"' + sel + '>' + AX.escaparHtml(u.nombre_completo) + "</option>";
            }).join("");
    }

    function fila2fa(app, respId) {
        return '<div class="subfila-2fa">' +
            '<div class="subfila">' +
                '<input class="form-control" data-rol="app" maxlength="100" placeholder="App 2FA (Authy, Google Authenticator…)" value="' + AX.escaparHtml(app || "") + '">' +
                '<select class="form-select" data-rol="resp">' + usuariosOpciones(respId) + '</select>' +
                '<button type="button" class="btn-icono btn-icono--peligro subfila-quitar" data-rol="quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '<textarea class="form-control subfila-2fa__llaves" data-rol="llaves" rows="3" ' +
                'placeholder="Llaves de recuperación (una por línea)"></textarea>' +
        '</div>';
    }

    function leer2fa() {
        return $("#dc2faRows .subfila-2fa").map(function () {
            var app  = $.trim($(this).find("[data-rol='app']").val());
            var resp = $(this).find("[data-rol='resp']").val();
            // Llaves: una por linea; se limpian los vacios.
            var llaves = String($(this).find("[data-rol='llaves']").val() || "")
                .split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
            return app ? { aplicacion: app, responsable_id: resp || "", llaves: llaves } : null;
        }).get();
    }

    $("#btnAgregar2fa").on("click", function () {
        $("#dc2faRows").append(fila2fa("", ""));
    });
    $("#dc2faRows").on("click", "[data-rol='quitar']", function () {
        $(this).closest(".subfila-2fa").remove();
    });

    // Boton "+": abre el modal anidado de nueva cuenta.
    $("#btnNuevaCuenta").on("click", function () {
        var prov = $("#fdProveedor").val();
        if (!prov) { AX.error("Selecciona primero un proveedor."); return; }
        document.getElementById("formNuevaCuenta").reset();
        $("#dc2faRows").empty();
        $("#formCuentaError").addClass("d-none").text("");
        $("#cuentaProveedorNombre").text("Proveedor: " + ($("#fdProveedor option:selected").text() || ""));
        $("#btnGuardarCuenta").prop("disabled", false);
        modalCuenta.abrir();
        $("#fcaAlias").trigger("focus");
    });

    // Guardar la nueva cuenta (con sus 2FA) y precargarla en el combobox.
    $("#btnGuardarCuenta").on("click", function () {
        var prov  = $("#fdProveedor").val();
        var alias = $.trim($("#fcaAlias").val());
        if (!prov)  { return AX.errorFormulario("#formCuentaError", "Selecciona un proveedor en el formulario de dominio."); }
        if (!alias) { return AX.errorFormulario("#formCuentaError", "El alias de la cuenta es obligatorio."); }

        var datos = {
            proveedor_id:          prov,
            alias:                 alias,
            url_acceso:            $.trim($("#fcaUrl").val()),
            usuario:               $.trim($("#fcaUsuario").val()),
            clave:                 $("#fcaClave").val(),
            correo_recuperacion:   $.trim($("#fcaCorreoRec").val()),
            telefono_recuperacion: $.trim($("#fcaTelRec").val()),
            notas:                 $.trim($("#fcaNotas").val()),
            dos_fa:                leer2fa()
        };

        var $btn = $("#btnGuardarCuenta").prop("disabled", true);
        AX.enviarJSON("endpoints/cuentas/crear.php", datos).then(function (res) {
            if (res.ok) {
                modalCuenta.cerrar();
                AX.exito("La cuenta se creó correctamente.");
                if (cbCuenta) { cbCuenta.agregar({ id: res.data.id, texto: res.data.alias }, true); }
            } else {
                AX.errorFormulario("#formCuentaError", res.mensaje || "No se pudo crear la cuenta.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formCuentaError", "Error de conexión.");
            $btn.prop("disabled", false);
        });
    });

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
        // Sin footer en el detalle: la flecha "Volver" (junto al titulo) es la
        // unica accion de retorno.
        AX.limpiarFooter();
        window.scrollTo({ top: 0, behavior: "smooth" });

        $.ajax({
            url: "endpoints/dominios/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: id }
        }).done(function (res) {
            if (res && res.ok) { pintarDetalle(res.data); }
            else { AX.error((res && res.mensaje) || "No se pudo cargar el detalle."); volverAlListado(); }
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo cargar el detalle.");
            volverAlListado();
        });
    }

    function refrescarDetalle() {
        if (!detalleId) { return; }
        $.ajax({
            url: "endpoints/dominios/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: detalleId }
        }).done(function (res) { if (res && res.ok) { pintarDetalle(res.data); } });
    }

    function pintarDashboard(x) {
        $("#detTitulo").text(x.nombre_dominio || "—");
        var propia = x.tipo_administracion === "PROPIA";
        $("#detAdminBadge").text(propia ? "Administración propia" : "Terceros")
            .attr("class", "tipo-badge " + (propia ? "tipo-badge--dedicado" : "tipo-badge--compartido"));

        $("#dashVence").text(x.fecha_vencimiento ? AX.formatearFecha(x.fecha_vencimiento) : "Sin fecha");
        $("#dashActualizado").text(AX.formatearFecha(x.updated_at) || "—");
        $("#dashAdmin").text(propia ? "Propia" : "Terceros");
        $("#dashProveedor").text(x.proveedor || "—");

        var $dias = $("#dashDias").attr("class", "dash-badge");
        if (!x.fecha_vencimiento) { $dias.addClass("dash-badge--none").text("Sin fecha"); return; }
        var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        var vence = new Date(String(x.fecha_vencimiento).substring(0, 10) + "T00:00:00");
        var dias = Math.round((vence - hoy) / 86400000);
        var clase, texto;
        if (dias < 0)        { clase = "dash-badge--danger"; texto = "Vencido hace " + Math.abs(dias) + " día(s)"; }
        else if (dias === 0) { clase = "dash-badge--danger"; texto = "Vence hoy"; }
        else if (dias <= 7)  { clase = "dash-badge--danger"; texto = dias + " día(s)"; }
        else if (dias <= 30) { clase = "dash-badge--warn";   texto = dias + " día(s)"; }
        else                 { clase = "dash-badge--ok";     texto = dias + " día(s)"; }
        $dias.addClass(clase).text(texto);
    }

    function pintarDetalle(d) {
        var x = d.dominio || {};

        pintarDashboard(x);

        // Tab Información: datos generales.
        $("#detProveedor").text(x.proveedor || "—");
        $("#detVps").html(x.vps_id ? AX.enlaceProducto("vps", x.vps_id, x.vps) : AX.escaparHtml(x.vps_externa || "—"));
        $("#detRegistrado").text(AX.formatearFecha(x.fecha_registro));
        $("#detVence").text(AX.formatearFecha(x.fecha_vencimiento));
        $("#detPrecioCompra").text(money(x.precio_compra));
        $("#detPrecioVenta").text(money(x.precio_venta));
        $("#detCreado").text(AX.formatearFecha(x.created_at));
        $("#detActualizado").text(AX.formatearFecha(x.updated_at));

        // Administracion + cuenta.
        var propia = x.tipo_administracion === "PROPIA";
        $("#detTipoAdmin").text(propia ? "Propia" : "Terceros");
        $("#detCuentaAlias").text(propia ? (x.cuenta_alias || "—") : "—");
        $("#detCuentaUrl").text(propia && x.cuenta_url ? x.cuenta_url : "—");
        $("#detCuentaUsuario").text(propia && x.cuenta_usuario ? x.cuenta_usuario : "—");
        $("#detCuentaClave").text(propia && x.cuenta_clave ? x.cuenta_clave : "—");
        $("#detCuentaCorreoRec").text(propia && x.cuenta_correo_rec ? x.cuenta_correo_rec : "—");
        $("#detCuentaTelRec").text(propia && x.cuenta_tel_rec ? x.cuenta_tel_rec : "—");

        // 2FA de la cuenta (con acceso a sus llaves de recuperacion).
        pintarSeccion($("#det2fa"), d.dos_fa, 4, function (f) {
            var llavesCell = f.tiene_llaves
                ? '<button type="button" class="btn btn-outline-secondary btn-sm btn-ver-llaves" ' +
                      'data-id="' + AX.escaparHtml(f.id) + '" data-app="' + AX.escaparHtml(f.aplicacion) + '">' +
                      '<i class="bi bi-key"></i> Ver llaves</button>'
                : '<span class="text-muted">—</span>';
            return "<tr><td>" + AX.escaparHtml(f.aplicacion) + "</td>" +
                   "<td>" + AX.escaparHtml(f.responsable || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(f.notas || "—") + "</td>" +
                   "<td>" + llavesCell + "</td></tr>";
        });

        // --- Registros DNS: una sola lista, repartida por tipo en cada tab ---
        var dns = d.dns || [];
        function porTipo(tipos) { return dns.filter(function (r) { return tipos.indexOf(r.tipo_registro) !== -1; }); }

        pintarSeccion($("#detDns"), dns, 5, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.tipo_registro) + "</td>" +
                   "<td>" + AX.escaparHtml(r.nombre) + "</td>" +
                   "<td>" + AX.escaparHtml(r.valor) + "</td>" +
                   "<td>" + AX.escaparHtml(r.ttl) + "</td>" +
                   "<td>" + AX.escaparHtml(r.prioridad != null ? r.prioridad : "—") + "</td></tr>";
        });
        pintarSeccion($("#detDnsA"), porTipo(["A", "AAAA"]), 4, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.tipo_registro) + "</td>" +
                   "<td>" + AX.escaparHtml(r.nombre) + "</td>" +
                   "<td>" + AX.escaparHtml(r.valor) + "</td>" +
                   "<td>" + AX.escaparHtml(r.ttl) + "</td></tr>";
        });
        pintarSeccion($("#detDnsMx"), porTipo(["MX"]), 4, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.prioridad != null ? r.prioridad : "—") + "</td>" +
                   "<td>" + AX.escaparHtml(r.nombre) + "</td>" +
                   "<td>" + AX.escaparHtml(r.valor) + "</td>" +
                   "<td>" + AX.escaparHtml(r.ttl) + "</td></tr>";
        });
        pintarSeccion($("#detDnsTxt"), porTipo(["TXT"]), 3, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.nombre) + "</td>" +
                   "<td>" + AX.escaparHtml(r.valor) + "</td>" +
                   "<td>" + AX.escaparHtml(r.ttl) + "</td></tr>";
        });
        pintarSeccion($("#detDnsCname"), porTipo(["CNAME"]), 3, function (r) {
            return "<tr><td>" + AX.escaparHtml(r.nombre) + "</td>" +
                   "<td>" + AX.escaparHtml(r.valor) + "</td>" +
                   "<td>" + AX.escaparHtml(r.ttl) + "</td></tr>";
        });

        // Historial (logs de auditoria del dominio).
        pintarSeccion($("#detLogs"), d.logs, 5, function (r) {
            return trFecha(r.fecha_evento) + "<td>" + AX.formatearFecha(r.fecha_evento) + "</td>" +
                   "<td>" + AX.escaparHtml(r.usuario || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(r.tipo_accion) + "</td>" +
                   "<td>" + AX.escaparHtml(r.modulo_afectado) + "</td>" +
                   "<td>" + AX.escaparHtml(r.descripcion) + "</td></tr>";
        });

        // Notas (con criticidad visual): la fila se tinta segun la prioridad.
        pintarSeccion($("#detNotas"), d.notas, 4, function (r) {
            var f = r.fecha ? String(r.fecha).substring(0, 10) : "";
            return '<tr data-fecha="' + AX.escaparHtml(f) + '"' + critClaseFila(r.criticidad) + ">" +
                   "<td>" + AX.formatearFecha(r.fecha) + "</td>" +
                   "<td>" + critBadge(r.criticidad) + "</td>" +
                   "<td>" + AX.escaparHtml(r.autor || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(r.nota) + "</td></tr>";
        });

        aplicarFiltrosDetalle();
    }

    // ===============================================================
    //  Modales "Agregar" del detalle (DNS + nota)
    // ===============================================================
    var modalAddDns    = AX.modal("#modalAddDns");
    var modalAddNota   = AX.modal("#modalAddNotaDom");
    var modalLlaves2fa = AX.modal("#modalLlaves2fa");
    var dnsTiposHtml = $("#dnTipo").html();   // opciones completas (pestaña DNS general)

    // Consultar (solo lectura) las llaves de recuperacion de un 2FA.
    $(document).on("click", ".btn-ver-llaves", function () {
        var id  = $(this).data("id");
        var app = $(this).data("app") || "";
        $("#llaves2faApp").text(app ? "2FA: " + app : "");
        $("#llaves2faLista").empty();
        $("#llaves2faVacio").addClass("d-none");
        modalLlaves2fa.abrir();
        $.ajax({
            url: "endpoints/cuentas/llaves_2fa.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: id }
        }).done(function (res) {
            var llaves = (res && res.ok && res.data && res.data.llaves) || [];
            if (!llaves.length) {
                $("#llaves2faVacio").removeClass("d-none");
                return;
            }
            $("#llaves2faLista").html(llaves.map(function (k) {
                return "<li><code>" + AX.escaparHtml(k) + "</code></li>";
            }).join(""));
        }).fail(function (xhr) {
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudieron cargar las llaves.");
            modalLlaves2fa.cerrar();
        });
    });

    // Muestra/oculta el campo prioridad segun el tipo elegido (MX).
    function togglePrioridadDns() {
        $(".grupo-prioridad").toggleClass("d-none", $("#dnTipo").val() !== "MX");
    }
    $("#dnTipo").on("change", togglePrioridadDns);

    // Restringe el select de tipo a los tipos permitidos por la pestaña:
    //   ''        -> todos (pestaña DNS general), editable.
    //   'MX'      -> un solo tipo, BLOQUEADO (MX / TXT / CNAME).
    //   'A,AAAA'  -> solo esos, editable (pestaña A/AAAA).
    function prepararTipoDns(tiposCsv) {
        var permitidos = String(tiposCsv || "").split(",")
            .map(function (s) { return s.trim(); }).filter(Boolean);
        if (!permitidos.length) {
            $("#dnTipo").html(dnsTiposHtml).prop("disabled", false);
        } else {
            $("#dnTipo").html(permitidos.map(function (t) {
                return '<option value="' + t + '">' + t + "</option>";
            }).join("")).val(permitidos[0]).prop("disabled", permitidos.length === 1);
        }
        togglePrioridadDns();
    }

    // Botones "Agregar registro" (uno general y uno por tab de tipo).
    $("#btnAddDns, #btnAddDnsA, #btnAddDnsMx, #btnAddDnsTxt, #btnAddDnsCname").on("click", function () {
        AX.limpiarFormulario("#formAddDns", "#formAddDnsError");
        $("#dnTtl").val(3600);
        prepararTipoDns($(this).data("tipos"));
        $("#btnGuardarAddDns").prop("disabled", false);
        modalAddDns.abrir();
        $("#dnNombre").trigger("focus");
    });

    $("#btnGuardarAddDns").on("click", function () {
        var nombre = $.trim($("#dnNombre").val());
        var valor  = $.trim($("#dnValor").val());
        var tipo   = $("#dnTipo").val();
        if (!nombre || !valor) { return AX.errorFormulario("#formAddDnsError", "El nombre y el valor son obligatorios."); }
        if (tipo === "MX" && $("#dnPrioridad").val() === "") {
            return AX.errorFormulario("#formAddDnsError", "La prioridad es obligatoria para un registro MX.");
        }
        var datos = {
            dominio_id: detalleId,
            tipo_registro: tipo,
            nombre: nombre,
            valor: valor,
            ttl: $("#dnTtl").val() === "" ? 3600 : $("#dnTtl").val(),
            prioridad: $("#dnPrioridad").val()
        };
        var $btn = $("#btnGuardarAddDns").prop("disabled", true);
        AX.enviarJSON("endpoints/dominios/agregar_dns.php", datos).then(function (res) {
            if (res.ok) {
                modalAddDns.cerrar();
                AX.exito("Registro DNS agregado correctamente.");
                refrescarDetalle();
            } else {
                AX.errorFormulario("#formAddDnsError", res.mensaje || "No se pudo agregar el registro.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formAddDnsError", "Error de conexión.");
            $btn.prop("disabled", false);
        });
    });

    $("#btnAddNotaDom").on("click", function () {
        AX.limpiarFormulario("#formAddNotaDom", "#formAddNotaDomError");
        $("#btnGuardarAddNotaDom").prop("disabled", false);
        modalAddNota.abrir();
        $("#ndNota").trigger("focus");
    });

    $("#btnGuardarAddNotaDom").on("click", function () {
        var nota = $.trim($("#ndNota").val());
        if (!nota) { return AX.errorFormulario("#formAddNotaDomError", "Escribe la nota."); }
        var $btn = $("#btnGuardarAddNotaDom").prop("disabled", true);
        AX.enviarJSON("endpoints/dominios/agregar_nota.php", {
            dominio_id: detalleId, nota: nota, criticidad: $("#ndCriticidad").val()
        }).then(function (res) {
            if (res.ok) {
                modalAddNota.cerrar();
                AX.exito("Nota agregada correctamente.");
                refrescarDetalle();
            } else {
                AX.errorFormulario("#formAddNotaDomError", res.mensaje || "No se pudo agregar la nota.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formAddNotaDomError", "Error de conexión.");
            $btn.prop("disabled", false);
        });
    });

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
    $("#btnNuevoDominio").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarDominio", enviarFormulario);
    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);

    // Eliminacion real del dominio: confirma (advirtiendo del borrado en
    // cascada de sus certificados SSL y relaciones de correo) y recarga.
    function eliminarRegistro(reg) {
        AX.confirmar({
            titulo: "Eliminar dominio",
            texto: 'Se eliminará "' + (reg.nombre_dominio || "este dominio") +
                   '" junto con sus registros DNS, notas, certificados SSL y relaciones de correo. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/dominios/eliminar.php", { id: reg.id }).then(function (res) {
                if (res.ok) { AX.exito(res.mensaje || "Dominio eliminado."); cargar(); }
                else { AX.error(res.mensaje || "No se pudo eliminar el dominio."); }
            }).catch(function () { AX.error("No se pudo eliminar el dominio."); });
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

    cbCuenta = AX.combobox("#cbCuentaDominio");
    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
