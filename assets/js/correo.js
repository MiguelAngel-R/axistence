/* =====================================================================
   AXISTENCE - correo.js
   Modulo Cuentas de correo (producto). Listado + formulario reutilizable
   con selects de dominio, cliente y servidor (VPS), y vista de DETALLE
   (viewProducto) con pestañas + filtros. Espejo de assets/js/dominios.js.

   NOTA: la accion "Eliminar" queda solo maquetada; no se implementa borrado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyCorreo");
    var $vistaListado = $("#vistaListado");
    var $vistaDetalle = $("#vistaDetalle");
    var modalForm     = AX.modal("#modalCorreo");
    var modalCuenta   = AX.modal("#modalCuentaCorreo");
    var modalExtension = AX.modal("#modalExtension");
    var cuentaLic     = null;  // licencia activa en el drill-down (id, dominio, cupo)
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { dominios, clientes, vps }
    var dropFechas = null;
    var licencias = [];        // [{ tipo_licencia, cantidad_cuentas }] del formulario
    var licenciasDetalle = []; // licencias de la relacion abierta en el detalle (con id)

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
            url: "endpoints/correo/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar las cuentas.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay cuentas de correo registradas.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(cc) {
        // El servidor de correo (MX): externo (texto) o VPS del sistema (legado).
        var servidor = cc.servidor_correo_vps_id
            ? AX.enlaceProducto("vps", cc.servidor_correo_vps_id, cc.servidor_vps)
            : AX.escaparHtml(cc.servidor_correo_externo || "—");
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(cc)) + '">' +
            "<td>" + AX.enlaceProducto("dominios", cc.dominio_id, cc.dominio) + "</td>" +
            "<td>" + servidor + "</td>" +
            "<td>" + AX.escaparHtml(cc.cliente) + "</td>" +
            "<td>" + AX.escaparHtml(cc.cantidad_cuentas != null ? cc.cantidad_cuentas : "—") + "</td>" +
            "<td>" + AX.formatearFecha(cc.fecha_vencimiento) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="ver" data-id="' + AX.escaparHtml(cc.id) + '" title="Ver detalle"><i class="bi bi-eye"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(cc.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(cc.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarCorreo").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Opciones de los <select>
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#fcDominio").html('<option value="">Cargando…</option>');
        $("#fcCliente").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/correo/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { dominios: [], clientes: [], vps: [] };
                $("#fcDominio").html('<option value="">Seleccione…</option>' +
                    (opciones.dominios || []).map(function (d) {
                        return '<option value="' + AX.escaparHtml(d.id) + '">' + AX.escaparHtml(d.nombre_dominio) + "</option>";
                    }).join(""));
                $("#fcCliente").html('<option value="">Seleccione…</option>' +
                    (opciones.clientes || []).map(function (c) {
                        return '<option value="' + AX.escaparHtml(c.id) + '">' + AX.escaparHtml(c.nombre_razon_social) + "</option>";
                    }).join(""));
                if (despues) { despues(); }
            } else {
                $("#fcDominio").html('<option value="">No se pudieron cargar los datos</option>');
            }
        }).fail(function () { $("#fcDominio").html('<option value="">Error al cargar</option>'); });
    }

    // Carga en el select #fcMx los registros MX del dominio indicado. Si se pasa
    // 'preseleccionar' (id de un MX), lo deja elegido (para el modo edicion).
    function cargarMxDominio(dominioId, preseleccionar) {
        var $mx = $("#fcMx");
        if (!dominioId) {
            $mx.prop("disabled", true).html('<option value="">Selecciona primero un dominio…</option>');
            return;
        }
        $mx.prop("disabled", true).html('<option value="">Cargando MX…</option>');
        $.ajax({
            url: "endpoints/correo/mx_dominio.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { dominio_id: dominioId }
        }).done(function (res) {
            var lista = (res && res.ok && res.data) ? res.data : [];
            if (!lista.length) {
                $mx.prop("disabled", true).html('<option value="">Este dominio no tiene registros MX</option>');
                return;
            }
            $mx.prop("disabled", false).html('<option value="">Seleccione…</option>' +
                lista.map(function (m) {
                    var etiqueta = (m.prioridad != null && m.prioridad !== "" ? m.prioridad + " · " : "") + m.valor;
                    return '<option value="' + AX.escaparHtml(m.id) + '">' + AX.escaparHtml(etiqueta) + "</option>";
                }).join(""));
            if (preseleccionar) { $mx.val(preseleccionar); }
        }).fail(function () {
            $mx.prop("disabled", true).html('<option value="">Error al cargar los MX</option>');
        });
    }

    // ===============================================================
    //  Licencias del formulario (varias por relacion: tipo + cantidad)
    // ===============================================================
    function pintarLicencias() {
        var $lista = $("#fcLicenciasLista");
        if (!licencias.length) {
            $lista.html('<li class="lic-lista__vacio text-muted" data-rol="vacio">Aún no has agregado licencias.</li>');
            return;
        }
        var total = 0;
        var filas = licencias.map(function (l, i) {
            total += parseInt(l.cantidad_cuentas, 10) || 0;
            return '<li class="lic-lista__item" data-idx="' + i + '">' +
                       '<span class="lic-lista__cant">' + AX.escaparHtml(l.cantidad_cuentas) + '</span>' +
                       '<span class="lic-lista__tipo">' + AX.escaparHtml(l.tipo_licencia || "Sin tipo de licencia") + '</span>' +
                       '<button type="button" class="lic-lista__quitar" data-rol="quitar" ' +
                           'title="Quitar" aria-label="Quitar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
                   "</li>";
        }).join("");
        var pie = '<li class="lic-lista__total"><span class="lic-lista__cant">' + total +
                  '</span><span class="lic-lista__tipo">Total de cuentas</span></li>';
        $lista.html(filas + pie);
    }

    function agregarLicencia() {
        var cant = parseInt($("#fcLicCantidad").val(), 10);
        var tipo = $.trim($("#fcLicTipo").val());
        if (!cant || cant < 1) {
            return errorFormulario("La cantidad de cuentas de la licencia debe ser 1 o más.");
        }
        if (tipo.length > 100) {
            return errorFormulario("El tipo de licencia es demasiado largo (máximo 100 caracteres).");
        }
        licencias.push({ tipo_licencia: tipo, cantidad_cuentas: cant });
        AX.errorFormulario("#formCorreoError", "");   // limpia el error si lo habia
        $("#formCorreoError").addClass("d-none");
        pintarLicencias();
        $("#fcLicCantidad").val("");
        $("#fcLicTipo").val("");
        $("#fcLicCantidad").trigger("focus");
    }

    function quitarLicencia(idx) {
        if (idx >= 0 && idx < licencias.length) {
            licencias.splice(idx, 1);
            pintarLicencias();
        }
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, cc) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && cc) ? cc.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formCorreo", "#formCorreoError");
        $("#formCorreoTitulo").text(esEditar ? "Editar relación de correo" : "Nueva relación de correo");
        $("#btnGuardarCorreo").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");
        $("#fcLicCantidad").val("");
        $("#fcLicTipo").val("");
        licencias = [];
        pintarLicencias();
        cargarMxDominio("");   // sin dominio: MX deshabilitado

        cargarOpciones(function () {
            if (esEditar && cc) {
                $("#fcDominio").val(cc.dominio_id || "");
                $("#fcCliente").val(cc.cliente_id || "");
                $("#fcFechaRegistro").val((cc.fecha_registro || "").substring(0, 10));
                // MX del dominio, preseleccionando el guardado.
                cargarMxDominio(cc.dominio_id || "", cc.mx_registro_id || "");
                // El desglose de licencias no viene en la fila del listado: se
                // trae del detalle para poblar la lista del formulario.
                cargarLicenciasParaEdicion(cc.id);
            }
        });

        modalForm.abrir();
        $("#fcDominio").trigger("focus");
    }

    // Trae las licencias de la relacion (endpoint ver.php) y las carga en la
    // lista del formulario en modo edicion.
    function cargarLicenciasParaEdicion(id) {
        if (!id) { return; }
        $.ajax({
            url: "endpoints/correo/ver.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { id: id }
        }).done(function (res) {
            if (res && res.ok && res.data) {
                licencias = (res.data.licencias || []).map(function (l) {
                    return { tipo_licencia: l.tipo_licencia || "", cantidad_cuentas: parseInt(l.cantidad_cuentas, 10) || 0 };
                });
                pintarLicencias();
            }
        });
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formCorreoError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        // Los precios NO se envian: se configuran despues desde el detalle.
        var datos = {
            dominio_id:        $("#fcDominio").val(),
            cliente_id:        $("#fcCliente").val(),
            mx_registro_id:    $("#fcMx").val(),
            licencias:         licencias,
            fecha_registro:    $("#fcFechaRegistro").val(),
            // Fecha final automatica: registro + 1 año - 1 dia (no manipulable).
            fecha_vencimiento: AX.calcularVigenciaAnual($("#fcFechaRegistro").val())
        };

        if (!datos.dominio_id || !datos.cliente_id || !datos.mx_registro_id || !datos.fecha_registro) {
            return errorFormulario("Completa los campos obligatorios (dominio, cliente, MX y fecha de registro).");
        }
        if (!licencias.length) {
            return errorFormulario("Agrega al menos una licencia con su cantidad de cuentas.");
        }

        var url = esEditar ? "endpoints/correo/actualizar.php" : "endpoints/correo/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarCorreo").prop("disabled", true);
        $.ajax({
            url: url, method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                trasGuardar();
                AX.exito(esEditar ? "La cuenta se actualizó correctamente." : "La cuenta se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar la cuenta.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar la cuenta.");
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
            url: "endpoints/correo/ver.php", method: "GET", dataType: "json",
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
        var cc = d.cuenta || {};
        // El titulo de la relacion es el dominio.
        $("#detTitulo").text(cc.dominio || "—");
        $("#detDominio").html(AX.enlaceProducto("dominios", cc.dominio_id, cc.dominio));
        $("#detCliente").text(cc.cliente || "—");
        // Servidor de correo (MX): externo (texto) o VPS del sistema (legado).
        $("#detServidor").html(cc.servidor_correo_vps_id
            ? AX.enlaceProducto("vps", cc.servidor_correo_vps_id, cc.servidor_vps)
            : AX.escaparHtml(cc.servidor_correo_externo || "—"));
        $("#detCantidad").text(cc.cantidad_cuentas != null ? cc.cantidad_cuentas : "—");
        // Desglose de licencias (tipo + cantidad). Puede haber varias.
        var lics = d.licencias || [];
        if (!lics.length) {
            $("#detLicencia").text("—");
        } else {
            $("#detLicencia").html('<ul class="lic-detalle">' + lics.map(function (l) {
                return "<li><strong>" + AX.escaparHtml(l.cantidad_cuentas) + "</strong> · " +
                       AX.escaparHtml(l.tipo_licencia || "Sin tipo de licencia") + "</li>";
            }).join("") + "</ul>");
        }
        $("#detRegistrado").text(AX.formatearFecha(cc.fecha_registro));
        $("#detVence").text(AX.formatearFecha(cc.fecha_vencimiento));
        $("#detPrecioCosto").text(money(cc.precio_costo_cuenta));
        $("#detPrecioVenta").text(money(cc.precio_venta_cuenta));
        $("#detCreado").text(AX.formatearFecha(cc.created_at));
        $("#detActualizado").text(AX.formatearFecha(cc.updated_at));

        pintarSeccion($("#detExtensiones"), d.extensiones, 5, function (e) {
            var cuenta = $.trim((e.cuenta_nombre || "") + " " + (e.cuenta_apellidos || ""));
            var lic = e.cuenta_correo ? (e.tipo_licencia || "Sin tipo de licencia") : "—";
            return trFecha(e.fecha_adquisicion) +
                   "<td>" + AX.escaparHtml(cuenta || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(e.cuenta_correo || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(lic) + "</td>" +
                   "<td>" + AX.escaparHtml(e.gigas_adicionales) + " GB</td>" +
                   "<td>" + AX.formatearFecha(e.fecha_adquisicion) + "</td></tr>";
        });

        // Cada licencia es clicable: abre (en el mismo tab) la tabla de sus cuentas.
        licenciasDetalle = d.licencias || [];
        cerrarCuentasLicencia();
        renderLicenciasTabla();

        pintarSeccion($("#detNotas"), d.notas, 3, function (n) {
            return trFecha(n.fecha) + "<td>" + AX.formatearFecha(n.fecha) + "</td>" +
                   "<td>" + AX.escaparHtml(n.autor || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(n.nota) + "</td></tr>";
        });

        aplicarFiltrosDetalle();
    }

    // ===============================================================
    //  Cuentas de una licencia (drill-down dentro del tab "Licencias")
    // ===============================================================
    // Tabla de licencias (se re-renderiza al abrir el detalle y al crear una
    // cuenta, para mantener al dia la columna "Creadas").
    function renderLicenciasTabla() {
        pintarSeccion($("#detLicencias"), licenciasDetalle, 3, function (l) {
            var usadas = parseInt(l.cuentas_usadas, 10) || 0;
            var total  = parseInt(l.cantidad_cuentas, 10) || 0;
            return '<tr data-lic-id="' + AX.escaparHtml(l.id) + '" title="Ver / crear cuentas de esta licencia">' +
                   "<td>" + AX.escaparHtml(l.tipo_licencia || "Sin tipo de licencia") + "</td>" +
                   "<td>" + total + "</td>" +
                   "<td>" + usadas + " / " + total + "</td></tr>";
        });
    }

    // Abre las cuentas de una licencia: pide al servidor las ya creadas + el cupo.
    function abrirCuentasLicencia(licId) {
        $("#wrap-licencias").addClass("d-none");
        $("#licenciaCuentas").removeClass("d-none");
        $("#detLicCuentas").html('<tr><td colspan="5" class="tabla-vacia">Cargando…</td></tr>');
        $.ajax({
            url: "endpoints/correo/cuentas_listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { licencia_id: licId }
        }).done(function (res) {
            if (res && res.ok) { pintarCuentasLicencia(res.data); }
            else { $("#detLicCuentas").html('<tr><td colspan="5" class="tabla-vacia">No se pudieron cargar las cuentas.</td></tr>'); }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar las cuentas.";
            $("#detLicCuentas").html('<tr><td colspan="5" class="tabla-vacia">' + AX.escaparHtml(msg) + "</td></tr>");
        });
    }

    function pintarCuentasLicencia(data) {
        var lic = data.licencia || {};
        cuentaLic = {
            id:               lic.id,
            dominio:          lic.dominio || "",
            tipo_licencia:    lic.tipo_licencia || "",
            cantidad_cuentas: parseInt(lic.cantidad_cuentas, 10) || 0,
            usadas:           parseInt(data.usadas, 10) || 0,
            disponibles:      parseInt(data.disponibles, 10) || 0
        };

        var tipo = cuentaLic.tipo_licencia || "Sin tipo de licencia";
        $("#licCuentasTitulo").text(cuentaLic.usadas + " de " + cuentaLic.cantidad_cuentas + " cuentas · " + tipo);

        // Boton de crear: deshabilitado cuando la licencia esta completa.
        var lleno = cuentaLic.disponibles <= 0;
        $("#btnCrearCuenta").prop("disabled", lleno)
            .attr("title", lleno ? "Licencia completa: no quedan cuentas disponibles" : "");

        pintarSeccion($("#detLicCuentas"), data.cuentas, 5, function (c) {
            return trFecha(c.created_at) +
                   "<td>" + AX.escaparHtml(($.trim((c.nombre || "") + " " + (c.apellidos || ""))) || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(c.tipo_cuenta || "Usuario") + "</td>" +
                   "<td>" + AX.escaparHtml(c.correo) + "</td>" +
                   "<td>" + celdaClave(c.clave) + "</td>" +
                   "<td>" + AX.formatearFecha(c.created_at) + "</td></tr>";
        });

        // Mantiene al dia el contador de la tabla de licencias (que esta detras).
        for (var i = 0; i < licenciasDetalle.length; i++) {
            if (String(licenciasDetalle[i].id) === String(cuentaLic.id)) {
                licenciasDetalle[i].cuentas_usadas = cuentaLic.usadas;
                break;
            }
        }
        renderLicenciasTabla();
    }

    // Celda de contraseña con mostrar/ocultar (la clave se guarda en texto plano).
    function celdaClave(clave) {
        var val = clave || "";
        return '<span class="clave-celda">' +
                   '<span class="clave-celda__valor" data-clave="' + AX.escaparHtml(val) + '">••••••••</span>' +
                   '<button type="button" class="clave-celda__ver btn-icono" title="Mostrar u ocultar" ' +
                       'aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye" aria-hidden="true"></i></button>' +
               "</span>";
    }

    function cerrarCuentasLicencia() {
        $("#licenciaCuentas").addClass("d-none");
        $("#wrap-licencias").removeClass("d-none");
    }

    // --- Modal de alta de una cuenta ---------------------------------
    function abrirModalCuenta() {
        if (!cuentaLic || cuentaLic.disponibles <= 0) {
            return AX.toast("La licencia ya alcanzó su límite de cuentas.", "info");
        }
        AX.limpiarFormulario("#formCuentaCorreo", "#formCuentaError");
        $("#ccNombre, #ccApellidos, #ccUsuario, #ccClave").val("");
        $("#ccTipoCuenta").val("Usuario");
        $("#ccClave").attr("type", "password");
        $("#ccVerClave i").removeClass("bi-eye-slash").addClass("bi-eye");
        $("#ccDominioSufijo").text("@" + (cuentaLic.dominio || ""));
        $("#formCuentaInfo").text("Quedan " + cuentaLic.disponibles + " de " +
            cuentaLic.cantidad_cuentas + " cuentas por crear en esta licencia.");
        $("#btnGuardarCuenta").prop("disabled", false);
        modalCuenta.abrir();
        $("#ccNombre").trigger("focus");
    }

    function enviarCuenta() {
        if (!cuentaLic) { return; }
        var datos = {
            licencia_id:    cuentaLic.id,
            nombre:         $.trim($("#ccNombre").val()),
            apellidos:      $.trim($("#ccApellidos").val()),
            tipo_cuenta:    $("#ccTipoCuenta").val(),
            usuario_correo: $.trim($("#ccUsuario").val()),
            clave:          $("#ccClave").val()
        };
        if (!datos.nombre || !datos.apellidos || !datos.usuario_correo || !datos.clave) {
            return AX.errorFormulario("#formCuentaError", "Completa todos los campos (nombre, apellidos, correo y contraseña).");
        }

        var $btn = $("#btnGuardarCuenta").prop("disabled", true);
        $.ajax({
            url: "endpoints/correo/cuentas_crear.php", method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalCuenta.cerrar();
                AX.exito("La cuenta de correo se creó correctamente.");
                abrirCuentasLicencia(cuentaLic.id);   // refresca tabla + contador
            } else {
                AX.errorFormulario("#formCuentaError", (res && res.mensaje) || "No se pudo crear la cuenta.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formCuentaError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo crear la cuenta.");
            $btn.prop("disabled", false);
        });
    }

    // --- Modal de asignar extension de espacio -----------------------
    // Abre el modal con el select de licencia poblado (datos ya cargados del
    // detalle). Al elegir licencia se cargan sus cuentas; guardar hace el POST.
    function abrirModalExtension() {
        var opts = '<option value="">Seleccione…</option>';
        for (var i = 0; i < licenciasDetalle.length; i++) {
            var l = licenciasDetalle[i];
            var etq = (l.tipo_licencia || "Sin tipo de licencia") +
                      " (" + (parseInt(l.cantidad_cuentas, 10) || 0) + " cuentas)";
            opts += '<option value="' + AX.escaparHtml(l.id) + '">' + AX.escaparHtml(etq) + "</option>";
        }
        $("#exLicencia").html(opts);
        $("#exCuenta").prop("disabled", true).html('<option value="">Seleccione primero una licencia…</option>');
        $("#exCantidad").val("");
        $("#formExtensionError").addClass("d-none");
        $("#btnGuardarExtension").prop("disabled", false);
        modalExtension.abrir();
        $("#exLicencia").trigger("focus");
    }

    // Carga en #exCuenta las cuentas de la licencia elegida (reusa cuentas_listar).
    function cargarCuentasExtension(licId) {
        var $c = $("#exCuenta");
        if (!licId) {
            $c.prop("disabled", true).html('<option value="">Seleccione primero una licencia…</option>');
            return;
        }
        $c.prop("disabled", true).html('<option value="">Cargando cuentas…</option>');
        $.ajax({
            url: "endpoints/correo/cuentas_listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { licencia_id: licId }
        }).done(function (res) {
            var cuentas = (res && res.ok && res.data && res.data.cuentas) ? res.data.cuentas : [];
            if (!cuentas.length) {
                $c.prop("disabled", true).html('<option value="">Esta licencia no tiene cuentas creadas</option>');
                return;
            }
            $c.prop("disabled", false).html('<option value="">Seleccione…</option>' +
                cuentas.map(function (cu) {
                    var nom = $.trim((cu.nombre || "") + " " + (cu.apellidos || ""));
                    var etq = (cu.correo || "") + (nom ? " · " + nom : "");
                    return '<option value="' + AX.escaparHtml(cu.id) + '">' + AX.escaparHtml(etq) + "</option>";
                }).join(""));
        }).fail(function () {
            $c.prop("disabled", true).html('<option value="">Error al cargar las cuentas</option>');
        });
    }

    function enviarExtension() {
        var datos = {
            licencia_id:       $("#exLicencia").val(),
            cuenta_id:         $("#exCuenta").val(),
            gigas_adicionales: $("#exCantidad").val()
        };
        if (!datos.licencia_id || !datos.cuenta_id || !datos.gigas_adicionales) {
            return AX.errorFormulario("#formExtensionError", "Completa todos los campos (licencia, cuenta y cantidad).");
        }
        if ((parseInt(datos.gigas_adicionales, 10) || 0) < 1) {
            return AX.errorFormulario("#formExtensionError", "La cantidad de extensión debe ser 1 GB o más.");
        }

        var $btn = $("#btnGuardarExtension").prop("disabled", true);
        $.ajax({
            url: "endpoints/correo/extension_crear.php", method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalExtension.cerrar();
                AX.exito("La extensión de espacio se asignó correctamente.");
                if (detalleId) { abrirDetalle(detalleId); }   // refresca el detalle (queda en extensiones)
            } else {
                AX.errorFormulario("#formExtensionError", (res && res.mensaje) || "No se pudo asignar la extensión.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formExtensionError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo asignar la extensión.");
            $btn.prop("disabled", false);
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
    $("#btnNuevoCorreo").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarCorreo", enviarFormulario);
    // Al cambiar de dominio, se recargan sus registros MX.
    $("#fcDominio").on("change", function () { cargarMxDominio($(this).val()); });

    // Licencias: agregar (boton o Enter en los campos) y quitar de la lista.
    $(document).on("click", "#btnAgregarLicencia", agregarLicencia);
    $(document).on("keydown", "#fcLicCantidad, #fcLicTipo", function (e) {
        if (e.key === "Enter") { e.preventDefault(); agregarLicencia(); }
    });
    $(document).on("click", "#fcLicenciasLista [data-rol='quitar']", function () {
        quitarLicencia(parseInt($(this).closest(".lic-lista__item").attr("data-idx"), 10));
    });
    // Cuentas de una licencia: click en la fila abre sus cuentas; la flecha
    // vuelve al listado de licencias; el boton de crear queda maquetado.
    $(document).on("click", "#detLicencias tr[data-lic-id]", function () {
        abrirCuentasLicencia($(this).attr("data-lic-id"));
    });
    $(document).on("click", "#btnVolverLicencias", cerrarCuentasLicencia);
    $(document).on("click", "#btnCrearCuenta", abrirModalCuenta);
    $(document).on("click", "#btnGuardarCuenta", enviarCuenta);
    // Mostrar/ocultar contraseña en el modal.
    $(document).on("click", "#ccVerClave", function () {
        var $inp = $("#ccClave"), oculto = $inp.attr("type") === "password";
        $inp.attr("type", oculto ? "text" : "password");
        $(this).find("i").toggleClass("bi-eye", !oculto).toggleClass("bi-eye-slash", oculto);
    });
    // Enter en los campos del modal envia el alta.
    $(document).on("keydown", "#formCuentaCorreo input", function (e) {
        if (e.key === "Enter") { e.preventDefault(); enviarCuenta(); }
    });
    // Mostrar/ocultar contraseña en la tabla de cuentas.
    $(document).on("click", ".clave-celda__ver", function () {
        var $val = $(this).siblings(".clave-celda__valor"), $i = $(this).find("i");
        if ($val.hasClass("is-visible")) {
            $val.removeClass("is-visible").text("••••••••");
            $i.removeClass("bi-eye-slash").addClass("bi-eye");
        } else {
            $val.addClass("is-visible").text($val.attr("data-clave") || "");
            $i.removeClass("bi-eye").addClass("bi-eye-slash");
        }
    });
    // Extensiones de espacio: el boton abre el modal; al elegir licencia se
    // cargan sus cuentas; guardar asigna la extension.
    $(document).on("click", "#btnAsignarExtension", abrirModalExtension);
    $(document).on("change", "#exLicencia", function () { cargarCuentasExtension($(this).val()); });
    $(document).on("click", "#btnGuardarExtension", enviarExtension);
    // Al cambiar de pestaña se vuelve siempre al listado de licencias.
    $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", cerrarCuentasLicencia);

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
            } else { AX.toast("Eliminación de cuentas: disponible próximamente.", "info"); }
            return;
        }
        if (reg) { abrirDetalle(reg.id); }
    });

    inicializarFiltrosDetalle();
    cargar();

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
