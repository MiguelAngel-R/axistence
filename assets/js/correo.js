/* =====================================================================
   AXISTENCE - correo.js
   Modulo Cuentas de correo (producto). Listado + formulario reutilizable
   con selects de dominio, cliente y servidor (VPS), y vista de DETALLE
   (viewProducto) con pestañas + filtros. Espejo de assets/js/dominios.js.

   NOTA: la accion "Eliminar" ejecuta un borrado real (confirmacion + endpoint
   eliminar.php + auditoria ELIMINAR).
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
    var modalCompCuentas = AX.modal("#modalComplementoCuentas");
    var cuentaLic     = null;  // licencia activa en el drill-down (id, dominio, cupo)
    var compActivo    = null;  // complemento abierto en el modal de asignacion (id, cupo, usadas)
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var detalleId = null;
    var opciones = null;       // { dominios, clientes, vps }
    var licencias = [];        // [{ tipo_licencia, cantidad_cuentas }] del formulario
    var licenciasDetalle = []; // licencias de la relacion abierta en el detalle (con id)
    var socketActivo = false;   // true cuando el socket de tiempo real esta conectado

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
    //  Tiempo real (Socket.IO)
    //  El listado se actualiza en vivo (insertar/actualizar/quitar la fila)
    //  cuando se crea, edita o elimina una relacion de correo (por cualquier
    //  usuario, incluido uno mismo), sin recargar ni volver a consultar la BD:
    //  el evento ya trae la fila con los nombres de dominio, cliente y servidor
    //  resueltos. La escritura sigue yendo por HTTP; el socket solo REPARTE.
    // ===============================================================

    // Localiza la fila del listado cuyo registro embebido tiene ese id.
    function filaPorId(id) {
        return $tbody.find("tr").filter(function () {
            var d = AX.datosFila(this);
            return d && d.id === id;
        });
    }

    // Alta: inserta la fila en su posicion alfabetica (el listado va ordenado por
    // nombre de dominio). Solo aplica en la primera pagina y sin busqueda activa.
    function socketCorreoCreado(cc) {
        if (!cc || !cc.id) { return; }
        if (estado.pagina !== 1 || estado.buscar !== "") { return; }
        if (filaPorId(cc.id).length) { return; }             // evita duplicar
        $tbody.find(".tabla-vacia").closest("tr").remove();  // quita el placeholder "vacio"
        var $nueva = $(fila(cc));
        var nombre = cc.dominio || "";
        var insertado = false;
        $tbody.find("tr").each(function () {
            var d = AX.datosFila(this);
            if (d && nombre.localeCompare(d.dominio || "", "es", { sensitivity: "base" }) < 0) {
                $nueva.insertBefore(this);
                insertado = true;
                return false;
            }
        });
        if (!insertado) { $tbody.append($nueva); }
    }

    // Edicion: reemplaza la fila si esta en pantalla. El evento trae la fila
    // completa (misma forma que el listado), asi que se reemplaza sin fusionar.
    function socketCorreoActualizado(cc) {
        if (!cc || !cc.id) { return; }
        var $fila = filaPorId(cc.id);
        if (!$fila.length) { return; }
        $fila.replaceWith(fila(cc));
    }

    // Borrado: quita la fila; si la tabla queda vacia, muestra el placeholder.
    function socketCorreoEliminado(payload) {
        var id = payload && payload.id;
        if (!id) { return; }
        var $fila = filaPorId(id);
        if (!$fila.length) { return; }
        $fila.remove();
        if (!$tbody.children().length) {
            $tbody.html(filaVacia("No hay cuentas de correo registradas."));
        }
    }

    // Alta de cuenta (buzon) en vivo, dentro del drill-down de una licencia. El
    // evento llega a toda la sala; solo aplica si el detalle abierto es el de esa
    // relacion (cuenta_correo_id === detalleId). Actualiza el contador de la
    // licencia en la tabla y, si el drill-down de esa licencia esta abierto,
    // agrega la fila y actualiza usadas/total y el boton de crear.
    function socketCuentaCreada(payload) {
        if (!payload || !payload.id || !payload.cuenta_correo_id || !payload.licencia_id) { return; }
        if (detalleId !== payload.cuenta_correo_id) { return; } // no es la relacion en pantalla

        // 1) Contador de la licencia en la tabla de licencias (+1).
        for (var i = 0; i < licenciasDetalle.length; i++) {
            if (String(licenciasDetalle[i].id) === String(payload.licencia_id)) {
                licenciasDetalle[i].cuentas_usadas = (parseInt(licenciasDetalle[i].cuentas_usadas, 10) || 0) + 1;
                break;
            }
        }

        // 2) Si el drill-down de ESA licencia esta abierto, agrega la fila y
        //    actualiza el contador/titulo/boton (dedup: el actor tambien recibe).
        if (cuentaLic && String(cuentaLic.id) === String(payload.licencia_id)) {
            var $cuerpo = $("#detLicCuentas");
            if (!$cuerpo.find('tr[data-id="' + payload.id + '"]').length) {
                $cuerpo.find(".tabla-vacia").closest("tr").remove();
                $cuerpo.append(filaCuentaLicencia(payload));
                cuentaLic.usadas = (parseInt(cuentaLic.usadas, 10) || 0) + 1;
                cuentaLic.disponibles = Math.max(0, cuentaLic.cantidad_cuentas - cuentaLic.usadas);
                // Si la cuenta que llego es administradora, la licencia ya no admite otra.
                if (payload.tipo_cuenta === "Administrador") { cuentaLic.adminExistente = true; }
                actualizarCabeceraCuentas();
            }
        }

        // 3) Repinta la tabla de licencias para reflejar el contador (queda detras
        //    del drill-down si esta abierto; visible si no).
        renderLicenciasTabla();
    }

    // Alta de complemento en vivo (tab "Complementos" del detalle). El evento
    // llega a toda la sala; solo aplica si el detalle abierto es el de esa
    // relacion. Se antepone porque la tabla va ordenada por fecha DESC.
    function socketComplementoCreado(payload) {
        if (!payload || !payload.id || !payload.cuenta_correo_id) { return; }
        if (detalleId !== payload.cuenta_correo_id) { return; } // no es la relacion en pantalla
        var $cuerpo = $("#detExtensiones");
        if ($cuerpo.find('tr[data-id="' + payload.id + '"]').length) { return; } // evita duplicar
        $cuerpo.find(".tabla-vacia").closest("tr").remove();                     // quita placeholder
        $cuerpo.prepend(filaComplemento(payload));
    }

    // Asignacion de una cuenta a un complemento en vivo: actualiza la columna
    // "Disponibles" de la fila del complemento (cupo restante) para todos los
    // que tienen abierto el detalle de esa relacion. El evento llega a toda la
    // sala; solo aplica si el detalle abierto es el de esa relacion.
    function socketComplementoCuentaAsignada(payload) {
        if (!payload || !payload.complemento_id || !payload.cuenta_correo_id) { return; }
        if (detalleId !== payload.cuenta_correo_id) { return; } // no es la relacion en pantalla
        actualizarDisponiblesFila(payload.complemento_id, payload.usadas, payload.cantidad_cuentas);
    }

    function conectarSocketCorreo() {
        var url = $vistaListado.data("ws");
        // Sin URL o sin la libreria cargada: la app sigue funcionando (con recarga).
        if (!url || typeof io === "undefined") { return; }
        var socket = io(url, { transports: ["websocket", "polling"], withCredentials: true });
        socket.on("connect", function () {
            socketActivo = true;
            socket.emit("unirse", "correo"); // entra a la sala del modulo
        });
        socket.on("disconnect", function () { socketActivo = false; });
        socket.on("correo:creado", socketCorreoCreado);
        socket.on("correo:actualizado", socketCorreoActualizado);
        socket.on("correo:eliminado", socketCorreoEliminado);
        socket.on("cuenta:creada", socketCuentaCreada);           // detalle: alta de cuenta en una licencia
        socket.on("complemento:creado", socketComplementoCreado); // detalle: alta de complemento
        socket.on("complemento_cuenta:asignada", socketComplementoCuentaAsignada); // detalle: cupo del complemento
    }

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
    // Al abrir un detalle, vuelve siempre a la primera pestaña.
    function reiniciarPestanaDetalle() {
        var primera = document.querySelector('#detTabs [data-bs-toggle="tab"]');
        if (primera && window.bootstrap && bootstrap.Tab) { bootstrap.Tab.getOrCreateInstance(primera).show(); }
    }

    function abrirDetalle(id) {
        detalleId = id;
        mostrar($vistaDetalle);
        reiniciarPestanaDetalle();
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

    // Fila de la tabla de complementos del detalle. Embebe data-id (dedup en
    // vivo), data-fecha (filtro) y data-registro (el complemento completo, para
    // abrir el modal de asignacion al click). La usan el pintado inicial y el
    // alta de complemento en tiempo real.
    function filaComplemento(e) {
        var lic = e.tipo_licencia || "Sin tipo de licencia";
        var f = e.fecha_inicio ? String(e.fecha_inicio).substring(0, 10) : "";
        var total = parseInt(e.cantidad_cuentas, 10) || 0;
        var usadas = parseInt(e.usadas, 10) || 0;               // 0 en complementos recien creados
        var disponibles = Math.max(0, total - usadas);
        return '<tr data-id="' + AX.escaparHtml(e.id) + '" data-fecha="' + AX.escaparHtml(f) + '"' +
               ' data-registro="' + AX.escaparHtml(JSON.stringify(e)) + '">' +
               "<td>" + AX.escaparHtml(lic) + "</td>" +
               "<td>" + AX.escaparHtml(e.nombre || "—") + "</td>" +
               "<td>" + AX.escaparHtml(e.cantidad_cuentas != null ? e.cantidad_cuentas : "—") + "</td>" +
               '<td class="js-comp-disp">' + disponibles + " / " + total + "</td>" +
               "<td>" + money(e.valor) + "</td>" +
               "<td>" + AX.formatearFecha(e.fecha_inicio) + "</td></tr>";
    }

    // Actualiza en la fila del complemento la celda "Disponibles" (cupo restante)
    // a partir del cupo recalculado (usadas / total). La usan el socket de
    // asignacion y el respaldo local cuando no hay socket.
    function actualizarDisponiblesFila(complementoId, usadas, total) {
        var $fila = $("#detExtensiones tr[data-id='" + complementoId + "']");
        if (!$fila.length) { return; }
        var disponibles = Math.max(0, (parseInt(total, 10) || 0) - (parseInt(usadas, 10) || 0));
        $fila.find(".js-comp-disp").text(disponibles + " / " + (parseInt(total, 10) || 0));
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

        pintarSeccion($("#detExtensiones"), d.complementos, 6, filaComplemento);

        // Cada licencia es clicable: abre (en el mismo tab) la tabla de sus cuentas.
        licenciasDetalle = d.licencias || [];
        cerrarCuentasLicencia();
        renderLicenciasTabla();

        pintarSeccion($("#detNotas"), d.notas, 3, function (n) {
            return trFecha(n.fecha) + "<td>" + AX.formatearFecha(n.fecha) + "</td>" +
                   "<td>" + AX.escaparHtml(n.autor || "—") + "</td>" +
                   "<td>" + AX.escaparHtml(n.nota) + "</td></tr>";
        });
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

    // Fila de la tabla de cuentas del drill-down de una licencia. Embebe data-id
    // (dedup en vivo) y data-fecha (filtro). La usan el pintado inicial y el alta
    // de cuenta en tiempo real.
    function filaCuentaLicencia(c) {
        var f = c.created_at ? String(c.created_at).substring(0, 10) : "";
        return '<tr data-id="' + AX.escaparHtml(c.id) + '" data-fecha="' + AX.escaparHtml(f) + '">' +
               "<td>" + AX.escaparHtml(($.trim((c.nombre || "") + " " + (c.apellidos || ""))) || "—") + "</td>" +
               "<td>" + AX.escaparHtml(c.tipo_cuenta || "Usuario") + "</td>" +
               "<td>" + AX.escaparHtml(c.correo) + "</td>" +
               "<td>" + celdaClave(c.clave) + "</td>" +
               "<td>" + AX.formatearFecha(c.created_at) + "</td></tr>";
    }

    // Cabecera del drill-down (titulo usadas/total + boton de crear). Se recalcula
    // desde 'cuentaLic', tanto al abrir como al llegar una cuenta por socket.
    function actualizarCabeceraCuentas() {
        if (!cuentaLic) { return; }
        var tipo = cuentaLic.tipo_licencia || "Sin tipo de licencia";
        $("#licCuentasTitulo").text(cuentaLic.usadas + " de " + cuentaLic.cantidad_cuentas + " cuentas · " + tipo);
        // Boton de crear: deshabilitado cuando la licencia esta completa.
        var lleno = cuentaLic.disponibles <= 0;
        $("#btnCrearCuenta").prop("disabled", lleno)
            .attr("title", lleno ? "Licencia completa: no quedan cuentas disponibles" : "");
    }

    function pintarCuentasLicencia(data) {
        var lic = data.licencia || {};
        // ¿La licencia ya tiene una cuenta administradora? Solo puede haber una,
        // asi que si existe se ocultara el check "administrador" al crear nuevas.
        var cuentas = data.cuentas || [];
        var adminExistente = false;
        for (var j = 0; j < cuentas.length; j++) {
            if (cuentas[j].tipo_cuenta === "Administrador") { adminExistente = true; break; }
        }
        cuentaLic = {
            id:               lic.id,
            dominio:          lic.dominio || "",
            tipo_licencia:    lic.tipo_licencia || "",
            cantidad_cuentas: parseInt(lic.cantidad_cuentas, 10) || 0,
            usadas:           parseInt(data.usadas, 10) || 0,
            disponibles:      parseInt(data.disponibles, 10) || 0,
            adminExistente:   adminExistente
        };

        actualizarCabeceraCuentas();
        pintarSeccion($("#detLicCuentas"), data.cuentas, 5, filaCuentaLicencia);

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
        // Todas las cuentas se crean como "Usuario"; el check solo se ofrece si la
        // licencia aun no tiene una cuenta administradora (solo puede haber una).
        $("#ccEsAdmin").prop("checked", false);
        $("#ccAdminWrap").toggleClass("d-none", !!(cuentaLic && cuentaLic.adminExistente));
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
            tipo_cuenta:    $("#ccEsAdmin").is(":checked") ? "Administrador" : "Usuario",
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
                // La fila aparece en vivo por socket (el propio actor la recibe) y
                // actualiza el contador; solo se recarga el drill-down como respaldo
                // si el socket no esta activo.
                if (!socketActivo) { abrirCuentasLicencia(cuentaLic.id); }
            } else {
                AX.errorFormulario("#formCuentaError", (res && res.mensaje) || "No se pudo crear la cuenta.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formCuentaError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo crear la cuenta.");
            $btn.prop("disabled", false);
        });
    }

    // --- Modal de asignar complemento --------------------------------
    // Abre el modal con el select de licencia poblado (datos ya cargados del
    // detalle). El complemento cuelga de la licencia; guardar hace el POST.
    function abrirModalExtension() {
        var opts = '<option value="">Seleccione…</option>';
        for (var i = 0; i < licenciasDetalle.length; i++) {
            var l = licenciasDetalle[i];
            var etq = (l.tipo_licencia || "Sin tipo de licencia") +
                      " (" + (parseInt(l.cantidad_cuentas, 10) || 0) + " cuentas)";
            opts += '<option value="' + AX.escaparHtml(l.id) + '">' + AX.escaparHtml(etq) + "</option>";
        }
        $("#exLicencia").html(opts);
        $("#exNombre").val("");
        $("#exCantidad").val("");
        $("#exValor").val("");
        $("#exFechaInicio").val("");
        $("#formExtensionError").addClass("d-none");
        $("#btnGuardarExtension").prop("disabled", false);
        modalExtension.abrir();
        $("#exLicencia").trigger("focus");
    }

    function enviarExtension() {
        var datos = {
            licencia_id:      $("#exLicencia").val(),
            nombre:           $.trim($("#exNombre").val()),
            cantidad_cuentas: $("#exCantidad").val(),
            valor:            $("#exValor").val(),
            fecha_inicio:     $("#exFechaInicio").val()
        };
        if (!datos.licencia_id || !datos.nombre || !datos.cantidad_cuentas ||
            datos.valor === "" || !datos.fecha_inicio) {
            return AX.errorFormulario("#formExtensionError",
                "Completa todos los campos (licencia, tipo de complemento, cantidad, valor y fecha de inicio).");
        }
        if ((parseInt(datos.cantidad_cuentas, 10) || 0) < 1) {
            return AX.errorFormulario("#formExtensionError", "La cantidad de cuentas debe ser 1 o más.");
        }
        if ((parseFloat(datos.valor) || 0) < 0) {
            return AX.errorFormulario("#formExtensionError", "El valor no puede ser negativo.");
        }

        var $btn = $("#btnGuardarExtension").prop("disabled", true);
        $.ajax({
            url: "endpoints/correo/extension_crear.php", method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalExtension.cerrar();
                AX.exito("El complemento se asignó correctamente.");
                // La fila aparece en vivo por socket (el propio actor la recibe);
                // solo se refresca el detalle como respaldo si no hay socket.
                if (!socketActivo && detalleId) { abrirDetalle(detalleId); }
            } else {
                AX.errorFormulario("#formExtensionError", (res && res.mensaje) || "No se pudo asignar el complemento.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formExtensionError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo asignar el complemento.");
            $btn.prop("disabled", false);
        });
    }

    // ===============================================================
    //  Cuentas de un complemento (modal de asignacion)
    //  Se abre al hacer click en una fila del tab "Complementos". Permite
    //  asignar el complemento a las cuentas de su licencia (con cupo) y lista
    //  las que ya lo tienen.
    // ===============================================================
    var compCuentasLic = [];  // cuentas de la licencia del complemento abierto

    // Cabecera del modal (cupo usadas/total + estado del boton/select segun cupo).
    function actualizarCabeceraComp() {
        if (!compActivo) { return; }
        $("#ccuCupo").text(compActivo.usadas + " / " + compActivo.cantidad_cuentas);
        var lleno = compActivo.usadas >= compActivo.cantidad_cuentas;
        $("#ccuInfo").text(lleno
            ? "El complemento ya alcanzó su límite de cuentas."
            : "Quedan " + (compActivo.cantidad_cuentas - compActivo.usadas) + " de " +
              compActivo.cantidad_cuentas + " cuenta(s) por asignar.");
        $("#btnAsignarComplementoCuenta").prop("disabled", lleno);
    }

    // Repuebla el select con las cuentas de la licencia que AÚN no tienen el
    // complemento asignado (se calcula desde compActivo.asignadasIds).
    function pintarSelectCuentasComp() {
        var $sel = $("#ccuCuenta");
        var disponibles = compCuentasLic.filter(function (cu) {
            return compActivo.asignadasIds.indexOf(String(cu.id)) === -1;
        });
        if (!compCuentasLic.length) {
            $sel.prop("disabled", true).html('<option value="">Esta licencia no tiene cuentas creadas</option>');
            return;
        }
        if (!disponibles.length) {
            $sel.prop("disabled", true).html('<option value="">Todas las cuentas ya tienen el complemento</option>');
            return;
        }
        $sel.prop("disabled", false).html('<option value="">Seleccione…</option>' +
            disponibles.map(function (cu) {
                var nom = $.trim((cu.nombre || "") + " " + (cu.apellidos || ""));
                var etq = (cu.correo || "") + (nom ? " · " + nom : "");
                return '<option value="' + AX.escaparHtml(cu.id) + '">' + AX.escaparHtml(etq) + "</option>";
            }).join(""));
    }

    // Fila de la tabla de cuentas ya asignadas (dedup por data-id).
    function filaAsignada(a) {
        var nom = $.trim((a.nombre || "") + " " + (a.apellidos || ""));
        return '<tr data-id="' + AX.escaparHtml(a.cuenta_id) + '">' +
               "<td>" + AX.escaparHtml(nom || "—") + "</td>" +
               "<td>" + AX.escaparHtml(a.correo || "—") + "</td>" +
               "<td>" + AX.escaparHtml(a.tipo_cuenta || "Usuario") + "</td>" +
               "<td>" + AX.formatearFecha(a.created_at) + "</td></tr>";
    }

    // Abre el modal con la info del complemento (de la fila) y carga sus cuentas.
    function abrirModalComplementoCuentas(comp) {
        if (!comp || !comp.id) { return; }
        compActivo = null;
        compCuentasLic = [];
        $("#formComplementoCuentaError").addClass("d-none");
        $("#ccuTitulo").text("Complemento: " + (comp.nombre || "—"));
        $("#ccuLicencia").text(comp.tipo_licencia || "Sin tipo de licencia");
        $("#ccuValor").text(money(comp.valor));
        $("#ccuFecha").text(AX.formatearFecha(comp.fecha_inicio));
        $("#ccuCupo").text("—");
        $("#ccuInfo").text("");
        $("#ccuCuenta").prop("disabled", true).html('<option value="">Cargando…</option>');
        $("#ccuAsignadas").html('<tr><td colspan="4" class="tabla-vacia">Cargando…</td></tr>');
        $("#btnAsignarComplementoCuenta").prop("disabled", true);
        modalCompCuentas.abrir();

        $.ajax({
            url: "endpoints/correo/complemento_cuentas_listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }, data: { complemento_id: comp.id }
        }).done(function (res) {
            if (!res || !res.ok) {
                $("#ccuAsignadas").html('<tr><td colspan="4" class="tabla-vacia">No se pudieron cargar las cuentas.</td></tr>');
                return;
            }
            pintarComplementoCuentas(res.data, comp);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar las cuentas.";
            $("#ccuAsignadas").html('<tr><td colspan="4" class="tabla-vacia">' + AX.escaparHtml(msg) + "</td></tr>");
        });
    }

    function pintarComplementoCuentas(data, comp) {
        var c = data.complemento || {};
        var asignadas = data.asignadas || [];
        compCuentasLic = data.cuentas || [];
        compActivo = {
            id:               comp.id,
            cantidad_cuentas: parseInt(c.cantidad_cuentas != null ? c.cantidad_cuentas : comp.cantidad_cuentas, 10) || 0,
            usadas:           parseInt(data.usadas, 10) || 0,
            asignadasIds:     asignadas.map(function (a) { return String(a.cuenta_id); })
        };
        // Datos autoritativos del endpoint (por si cambiaron desde el pintado).
        $("#ccuLicencia").text(c.tipo_licencia || "Sin tipo de licencia");
        $("#ccuValor").text(money(c.valor));
        $("#ccuFecha").text(AX.formatearFecha(c.fecha_inicio));

        pintarSeccion($("#ccuAsignadas"), asignadas, 4, filaAsignada);
        pintarSelectCuentasComp();
        actualizarCabeceraComp();
    }

    function asignarComplementoCuenta() {
        if (!compActivo) { return; }
        var cuentaId = $("#ccuCuenta").val();
        if (!cuentaId) {
            return AX.errorFormulario("#formComplementoCuentaError", "Selecciona una cuenta de la licencia.");
        }
        if (compActivo.usadas >= compActivo.cantidad_cuentas) {
            return AX.errorFormulario("#formComplementoCuentaError", "El complemento ya alcanzó su límite de cuentas.");
        }
        $("#formComplementoCuentaError").addClass("d-none");

        var $btn = $("#btnAsignarComplementoCuenta").prop("disabled", true);
        $.ajax({
            url: "endpoints/correo/complemento_cuenta_asignar.php", method: "POST", contentType: "application/json",
            dataType: "json", xhrFields: { withCredentials: true },
            data: JSON.stringify({ complemento_id: compActivo.id, cuenta_id: cuentaId })
        }).done(function (res) {
            if (res && res.ok && res.data && res.data.asignacion) {
                var a = res.data.asignacion;
                $("#ccuAsignadas").find(".tabla-vacia").closest("tr").remove();
                $("#ccuAsignadas").append(filaAsignada(a));
                compActivo.usadas += 1;
                compActivo.asignadasIds.push(String(a.cuenta_id));
                pintarSelectCuentasComp();
                actualizarCabeceraComp();
                // "Disponibles" en la tabla de fondo se actualiza en vivo por
                // socket (el propio actor lo recibe); respaldo local sin socket.
                if (!socketActivo) {
                    actualizarDisponiblesFila(compActivo.id, compActivo.usadas, compActivo.cantidad_cuentas);
                }
                AX.toast("Complemento asignado a la cuenta.", "success");
            } else {
                AX.errorFormulario("#formComplementoCuentaError", (res && res.mensaje) || "No se pudo asignar el complemento.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            AX.errorFormulario("#formComplementoCuentaError", (xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo asignar el complemento.");
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
        // Si se guardo desde el detalle, se refresca el detalle: el socket solo
        // actualiza la fila del listado, no la vista de detalle.
        if (detalleId) { abrirDetalle(detalleId); return; }
        // En el listado: alta y edicion aparecen en vivo por socket (el propio
        // actor recibe el evento); solo se recarga como respaldo si no hay socket.
        if (!socketActivo) { cargar(); }
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
    // Complementos: el boton abre el modal; guardar asigna el complemento a la
    // licencia elegida.
    $(document).on("click", "#btnAsignarExtension", abrirModalExtension);
    $(document).on("click", "#btnGuardarExtension", enviarExtension);
    // Click en una fila de complementos: abre el modal para asignarlo a las
    // cuentas de su licencia.
    $(document).on("click", "#detExtensiones tr[data-registro]", function () {
        var comp = AX.datosFila(this);
        if (comp) { abrirModalComplementoCuentas(comp); }
    });
    $(document).on("click", "#btnAsignarComplementoCuenta", asignarComplementoCuenta);
    // Al cambiar de pestaña se vuelve siempre al listado de licencias.
    $('#detTabs [data-bs-toggle="tab"]').on("shown.bs.tab", cerrarCuentasLicencia);

    // La flecha "Volver" del detalle (data-ax-volver) regresa al listado.
    AX.vincularVolver(volverAlListado);

    // Eliminacion real de la relacion de correo: confirma (advirtiendo del
    // borrado de sus licencias/buzones) y recarga.
    function eliminarRegistro(reg) {
        AX.confirmar({
            titulo: "Eliminar correo",
            texto: 'Se eliminará la relación de correo del dominio "' + (reg.dominio || "seleccionado") +
                   '" junto con sus licencias, buzones, extensiones y notas. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/correo/eliminar.php", { id: reg.id }).then(function (res) {
                if (res.ok) {
                    AX.exito(res.mensaje || "Correo eliminado.");
                    // La fila se quita en vivo por socket; recarga de respaldo si no hay socket.
                    if (!socketActivo) { cargar(); }
                }
                else { AX.error(res.mensaje || "No se pudo eliminar el correo."); }
            }).catch(function () { AX.error("No se pudo eliminar el correo."); });
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

    cargar();
    conectarSocketCorreo(); // tiempo real: escucha altas/ediciones/borrados

    // Deep linking: si se llego con ?detalle=<uuid>, abrir ese detalle.
    var detallePedido = AX.detalleSolicitado();
    if (detallePedido) { abrirDetalle(detallePedido); }
});
