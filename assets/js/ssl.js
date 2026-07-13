/* =====================================================================
   AXISTENCE - ssl.js
   Modulo Certificados SSL (producto). Listado + formulario reutilizable
   (alta/edicion) con selects de dominio/proveedor/servidor y SUBIDA SEGURA
   del paquete de certificados. Desde el listado se puede DESCARGAR el
   paquete (.zip/.rar) de cada certificado.

   Este modulo NO tiene vista de detalle (viewProducto): toda la operacion
   ocurre en el listado y su modal.

   Flujo de guardado: si hay archivo, primero se sube (multipart) a
   endpoints/ssl/subir.php; con la ruta devuelta se envia el resto por JSON.

   NOTA: la accion "Eliminar" ejecuta un borrado real (confirmacion + endpoint
   eliminar.php + auditoria ELIMINAR).
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodySsl");
    var $vistaListado = $("#vistaListado");
    var modalForm  = AX.modal("#modalSsl");
    var COLUMNAS = 6;
    var modo = "crear";
    var editandoId = null;
    var opciones = null;       // { dominios, proveedores, vps }
    var socketActivo = false;   // true cuando el socket de tiempo real esta conectado

    function money(v) {
        var n = parseFloat(v);
        if (isNaN(n)) { return "—"; }
        return "$" + n.toLocaleString("es-CO", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' + AX.escaparHtml(mensaje) + "</td></tr>";
    }

    // ===============================================================
    //  Listado
    // ===============================================================
    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/ssl/listar.php", method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) { $tbody.html(filaVacia("No se pudo cargar la lista.")); return; }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los certificados.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        if (!items.length) { $tbody.html(filaVacia("No hay certificados registrados.")); }
        else { $tbody.html(items.map(fila).join("")); }

        AX.renderPaginador({
            pagina: data.pagina, totalPaginas: data.total_paginas,
            total: data.total, porPagina: data.por_pagina,
            onCambio: function (p) { estado.pagina = p; cargar(); window.scrollTo({ top: 0, behavior: "smooth" }); },
            onPorPagina: function (n) { estado.porPagina = n; estado.pagina = 1; cargar(); }
        });
    }

    function fila(s) {
        var servidorHtml = s.vps_id ? AX.enlaceProducto("vps", s.vps_id, s.vps)
                                    : AX.escaparHtml(s.vps_externa || "—");
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(s)) + '">' +
            "<td>" + AX.enlaceProducto("dominios", s.dominio_id, s.dominio) + "</td>" +
            "<td>" + AX.escaparHtml(s.proveedor) + "</td>" +
            "<td>" + servidorHtml + "</td>" +
            "<td>" + AX.formatearFecha(s.fecha_vencimiento) + "</td>" +
            '<td class="col-precio">' + money(s.precio_venta) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="descargar" data-id="' + AX.escaparHtml(s.id) + '" title="Descargar paquete (.zip/.rar)"><i class="bi bi-download"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(s.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(s.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    var temporizador;
    $("#buscarSsl").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { estado.buscar = valor.trim(); estado.pagina = 1; cargar(); }, 350);
    });

    // ===============================================================
    //  Tiempo real (Socket.IO)
    //  El listado inserta en vivo la fila cuando se registra un certificado (por
    //  cualquier usuario, incluido uno mismo), sin recargar ni volver a
    //  consultar la BD: el evento ya trae la fila con los nombres de dominio,
    //  proveedor y VPS resueltos. La escritura sigue yendo por HTTP al endpoint;
    //  el socket solo REPARTE lo que PHP confirma tras guardar.
    // ===============================================================

    // Localiza la fila del listado cuyo registro embebido tiene ese id.
    function filaPorId(id) {
        return $tbody.find("tr").filter(function () {
            var d = AX.datosFila(this);
            return d && d.id === id;
        });
    }

    // Alta: inserta la fila en su posicion alfabetica (el listado va ordenado por
    // nombre de dominio). Solo aplica en la primera pagina y sin busqueda activa;
    // en otro caso la fila aparecera al navegar/filtrar.
    function socketSslCreado(ssl) {
        if (!ssl || !ssl.id) { return; }
        if (estado.pagina !== 1 || estado.buscar !== "") { return; }
        if (filaPorId(ssl.id).length) { return; }            // evita duplicar
        $tbody.find(".tabla-vacia").closest("tr").remove();  // quita el placeholder "vacio"
        var $nueva = $(fila(ssl));
        var nombre = ssl.dominio || "";
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
    // completa (misma forma que el listado, con dominio/proveedor/VPS resueltos),
    // asi que se reemplaza tal cual sin fusionar.
    function socketSslActualizado(ssl) {
        if (!ssl || !ssl.id) { return; }
        var $fila = filaPorId(ssl.id);
        if (!$fila.length) { return; }
        $fila.replaceWith(fila(ssl));
    }

    // Borrado: quita la fila; si la tabla queda vacia, muestra el placeholder.
    function socketSslEliminado(payload) {
        var id = payload && payload.id;
        if (!id) { return; }
        var $fila = filaPorId(id);
        if (!$fila.length) { return; }
        $fila.remove();
        if (!$tbody.children().length) {
            $tbody.html(filaVacia("No hay certificados registrados."));
        }
    }

    function conectarSocketSsl() {
        var url = $vistaListado.data("ws");
        // Sin URL o sin la libreria cargada: la app sigue funcionando (con recarga).
        if (!url || typeof io === "undefined") { return; }
        var socket = io(url, { transports: ["websocket", "polling"], withCredentials: true });
        socket.on("connect", function () {
            socketActivo = true;
            socket.emit("unirse", "ssl"); // entra a la sala del modulo
        });
        socket.on("disconnect", function () { socketActivo = false; });
        socket.on("ssl:creado", socketSslCreado);
        socket.on("ssl:actualizado", socketSslActualizado);
        socket.on("ssl:eliminado", socketSslEliminado);
    }

    // ===============================================================
    //  Opciones de los <select>
    // ===============================================================
    function cargarOpciones(despues) {
        if (opciones) { if (despues) { despues(); } return; }
        $("#fsDominio").html('<option value="">Cargando…</option>');
        $("#fsProveedor").html('<option value="">Cargando…</option>');
        $.ajax({
            url: "endpoints/ssl/opciones.php", method: "GET", dataType: "json", xhrFields: { withCredentials: true }
        }).done(function (res) {
            if (res && res.ok) {
                opciones = res.data || { dominios: [], proveedores: [], vps: [] };
                $("#fsDominio").html('<option value="">Seleccione…</option>' +
                    (opciones.dominios || []).map(function (d) {
                        return '<option value="' + AX.escaparHtml(d.id) + '">' + AX.escaparHtml(d.nombre_dominio) + "</option>";
                    }).join(""));
                $("#fsProveedor").html('<option value="">Seleccione…</option>' +
                    (opciones.proveedores || []).map(function (p) {
                        return '<option value="' + AX.escaparHtml(p.id) + '">' + AX.escaparHtml(p.nombre_proveedor) + "</option>";
                    }).join(""));
                $("#fsVps").html('<option value="">— Ninguno —</option>' +
                    (opciones.vps || []).map(function (v) {
                        return '<option value="' + AX.escaparHtml(v.id) + '">' + AX.escaparHtml(v.referencia_vps) + "</option>";
                    }).join(""));
                if (despues) { despues(); }
            } else {
                $("#fsDominio").html('<option value="">No se pudieron cargar los datos</option>');
            }
        }).fail(function () { $("#fsDominio").html('<option value="">Error al cargar</option>'); });
    }

    // ===============================================================
    //  Formulario (alta / edicion)
    // ===============================================================
    function abrirFormulario(nuevoModo, ssl) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && ssl) ? ssl.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formSsl", "#formSslError");
        vigSsl.refrescar();   // limpia la previsualizacion de la fecha final
        $("#formSslTitulo").text(esEditar ? "Editar certificado" : "Nuevo certificado");
        $("#btnGuardarSsl").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        // En edicion el archivo es opcional (se conserva el actual si no se sube otro).
        $("#fsArchivoObl").toggleClass("d-none", esEditar);
        $("#fsArchivoAyuda").text(esEditar
            ? "Deja vacío para conservar el paquete actual; sube uno para reemplazarlo."
            : "Se almacena de forma segura; solo se descarga con permisos.");

        cargarOpciones(function () {
            if (esEditar && ssl) {
                $("#fsDominio").val(ssl.dominio_id || "");
                $("#fsProveedor").val(ssl.proveedor_id || "");
                $("#fsVps").val(ssl.vps_id || "");
                $("#fsVpsExterna").val(ssl.vps_externa || "");
                $("#fsRuta").val(ssl.ruta_almacenamiento || "");
                $("#fsFechaRegistro").val((ssl.fecha_registro || "").substring(0, 10));
                vigSsl.refrescar();   // recalcula la fecha final desde el registro
                $("#fsPrecioCompra").val(ssl.precio_compra != null ? ssl.precio_compra : "");
                $("#fsPrecioVenta").val(ssl.precio_venta != null ? ssl.precio_venta : "");
            }
        });

        modalForm.abrir();
        $("#fsDominio").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formSslError", msg);
        $("#btnGuardarSsl").prop("disabled", false);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var base = {
            dominio_id:          $("#fsDominio").val(),
            proveedor_id:        $("#fsProveedor").val(),
            vps_id:              $("#fsVps").val(),
            vps_externa:         $.trim($("#fsVpsExterna").val()),
            ruta_almacenamiento: $.trim($("#fsRuta").val()),
            fecha_registro:      $("#fsFechaRegistro").val(),
            // Fecha final automatica: registro + 1 año - 1 dia (no manipulable).
            fecha_vencimiento:   AX.calcularVigenciaAnual($("#fsFechaRegistro").val()),
            precio_compra:       $("#fsPrecioCompra").val() === "" ? 0 : $("#fsPrecioCompra").val(),
            precio_venta:        $("#fsPrecioVenta").val() === "" ? 0 : $("#fsPrecioVenta").val()
        };

        if (!base.dominio_id || !base.proveedor_id || !base.ruta_almacenamiento || !base.fecha_registro) {
            return errorFormulario("Completa los campos obligatorios (dominio, proveedor, ruta y fecha de registro).");
        }

        var archivo = document.getElementById("fsArchivo").files[0];
        if (!esEditar && !archivo) {
            return errorFormulario("Sube el paquete de certificados (.zip o .rar).");
        }

        $("#btnGuardarSsl").prop("disabled", true);

        // Envia el certificado por JSON (con la ruta del paquete si aplica).
        function enviarDatos(rutaPaquete) {
            var datos = $.extend({}, base);
            if (rutaPaquete) { datos.archivo_paquete_path = rutaPaquete; }
            if (esEditar) { datos.id = editandoId; }

            var url = esEditar ? "endpoints/ssl/actualizar.php" : "endpoints/ssl/crear.php";
            $.ajax({
                url: url, method: "POST", contentType: "application/json",
                dataType: "json", xhrFields: { withCredentials: true }, data: JSON.stringify(datos)
            }).done(function (res) {
                if (res && res.ok) {
                    trasGuardar();
                    AX.exito(esEditar ? "El certificado se actualizó correctamente." : "El certificado se creó correctamente.");
                } else {
                    errorFormulario((res && res.mensaje) || "No se pudo guardar el certificado.");
                }
            }).fail(function (xhr) {
                errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el certificado.");
            });
        }

        // Si hay archivo, primero se sube de forma segura y luego se guarda.
        if (archivo) {
            var fd = new FormData();
            fd.append("archivo", archivo);
            $.ajax({
                url: "endpoints/ssl/subir.php", method: "POST", data: fd,
                processData: false, contentType: false, dataType: "json",
                xhrFields: { withCredentials: true }
            }).done(function (res) {
                if (res && res.ok && res.data && res.data.ruta) {
                    enviarDatos(res.data.ruta);
                } else {
                    errorFormulario((res && res.mensaje) || "No se pudo subir el paquete de certificados.");
                }
            }).fail(function (xhr) {
                errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo subir el paquete de certificados.");
            });
        } else {
            enviarDatos(null); // edicion sin reemplazar el archivo
        }
    }

    // Tras guardar en el modal: cerrarlo y refrescar el listado.
    function trasGuardar() {
        modalForm.cerrar();
        // Alta y edicion aparecen en vivo por socket (el propio actor recibe el
        // evento); solo se recarga como respaldo si el socket no esta activo.
        if (!socketActivo) { cargar(); }
    }

    // Descarga segura del paquete del certificado (endpoint con permisos).
    function descargarPaquete(id) {
        if (!id) { return; }
        window.location.href = "endpoints/ssl/descargar.php?id=" + encodeURIComponent(id);
    }

    // ===============================================================
    //  Eventos
    // ===============================================================
    $("#btnNuevoSsl").on("click", function () { abrirFormulario("crear"); });
    $(document).on("click", "#btnGuardarSsl", enviarFormulario);

    // Vigencia anual: la fecha de vencimiento se calcula sola (registro + 1 año
    // - 1 dia) y se muestra de solo lectura; ya no es manipulable por el usuario.
    var vigSsl = AX.vincularVigencia("#fsFechaRegistro", "#fsFechaVencimiento");

    // Eliminacion real del certificado: confirma, borra (endpoint + paquete
    // fisico) y recarga.
    function eliminarRegistro(reg) {
        AX.confirmar({
            titulo: "Eliminar certificado",
            texto: 'Se eliminará el certificado SSL del dominio "' + (reg.dominio || "seleccionado") +
                   '" y su paquete de archivos. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/ssl/eliminar.php", { id: reg.id }).then(function (res) {
                if (res.ok) {
                    AX.exito(res.mensaje || "Certificado eliminado.");
                    // La fila se quita en vivo por socket; recarga de respaldo si no hay socket.
                    if (!socketActivo) { cargar(); }
                }
                else { AX.error(res.mensaje || "No se pudo eliminar el certificado."); }
            }).catch(function () { AX.error("No se pudo eliminar el certificado."); });
        });
    }

    $tbody.on("click", "tr", function (e) {
        if (AX.esClicEnEnlace(e)) { return; } // deja navegar los enlaces cruzados
        var $btn = $(e.target).closest("[data-accion]");
        if (!$btn.length) { return; }
        var reg = AX.datosFila(this);
        var accion = $btn.data("accion");
        if (accion === "descargar") {
            descargarPaquete($btn.data("id"));
        } else if (accion === "editar") {
            if (reg) { abrirFormulario("editar", reg); }
            else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
        } else if (accion === "eliminar") {
            if (reg) { eliminarRegistro(reg); }
            else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
        }
    });

    cargar();
    conectarSocketSsl(); // tiempo real: escucha altas
});
