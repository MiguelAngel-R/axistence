/* =====================================================================
   AXISTENCE - proveedores.js
   Logica del modulo Proveedores. Carga el listado por AJAX, pinta la
   tabla y delega el paginador en general.js (AX). El formulario se
   reutiliza para alta y edicion, en el mismo espacio que la tabla, e
   incluye la seleccion multiple de "tipos de producto" (M:N).
   Espejo de assets/js/clientes.js.

   NOTA: la accion "Eliminar" ejecuta un borrado real (confirmacion + endpoint
   eliminar.php + auditoria ELIMINAR); 409 si tiene productos asociados.

   TIEMPO REAL (Socket.IO): el listado inserta en vivo la fila cuando se crea un
   proveedor (por cualquier usuario, incluido uno mismo), sin recargar ni volver
   a consultar la BD. La escritura sigue yendo por HTTP al endpoint; el server
   de sockets (websockets/index2.js) solo REPARTE lo que PHP confirma. Si el
   socket no esta activo, se recae en recargar el listado (respaldo).
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyProveedores");
    var $vistaListado = $("#vistaListado");
    var COLUMNAS = 5;
    var modo = "crear";      // "crear" | "editar"
    var editandoId = null;   // id del proveedor en edicion
    var modalProveedor = AX.modal("#modalProveedor");
    var socketActivo = false;   // true cuando el socket de tiempo real esta conectado

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' +
               AX.escaparHtml(mensaje) + "</td></tr>";
    }

    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/proveedores/listar.php",
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
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los proveedores.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];

        if (!items.length) {
            $tbody.html(filaVacia("No hay proveedores registrados."));
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

    // Pinta los tipos de producto como una lista de badges (o "—" si no hay).
    function tiposBadges(tipos) {
        if (!tipos || !tipos.length) { return "—"; }
        return tipos.map(function (t) {
            return '<span class="tipo-badge">' + AX.escaparHtml(t) + "</span>";
        }).join(" ");
    }

    function fila(p) {
        var sitio = p.sitio_web
            ? '<a href="' + AX.escaparHtml(p.sitio_web) + '" target="_blank" rel="noopener">' + AX.escaparHtml(p.sitio_web) + "</a>"
            : "—";
        // Los datos de la fila quedan embebidos para editar sin consultar la BD.
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(p)) + '">' +
            "<td>" + AX.escaparHtml(p.nombre_proveedor) + "</td>" +
            "<td>" + sitio + "</td>" +
            '<td class="celda-tipos">' + tiposBadges(p.tipos) + "</td>" +
            "<td>" + AX.formatearFecha(p.created_at) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="editar" data-id="' + AX.escaparHtml(p.id) + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono btn-icono--peligro" data-accion="eliminar" data-id="' + AX.escaparHtml(p.id) + '" title="Eliminar"><i class="bi bi-trash"></i></button>' +
            "</td>" +
        "</tr>";
    }

    // --- Busqueda con retardo (debounce) ---------------------------
    var temporizador;
    $("#buscarProveedor").on("input", function () {
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

    // proveedor: objeto de la fila cuando es edicion; null cuando es alta.
    function abrirModal(nuevoModo, proveedor) {
        modo = nuevoModo;
        editandoId = (modo === "editar" && proveedor) ? proveedor.id : null;
        var esEditar = (modo === "editar");

        AX.limpiarFormulario("#formProveedor", "#formProveedorError");
        $("#btnGuardarProveedor").prop("disabled", false)
            .find("[data-rol='texto']").text(esEditar ? "Guardar cambios" : "Crear");

        $("#formProveedorTitulo").text(esEditar ? "Editar proveedor" : "Nuevo proveedor");

        if (esEditar && proveedor) {
            // Puebla nombre_proveedor, sitio_web y marca los checkboxes 'tipos'.
            AX.poblarFormulario("#formProveedor", proveedor);
        }

        modalProveedor.abrir();
        $("#fpNombre").trigger("focus");
    }

    function errorFormulario(msg) {
        AX.errorFormulario("#formProveedorError", msg);
    }

    function enviarFormulario() {
        var esEditar = (modo === "editar");
        var lectura = AX.leerFormulario("#formProveedor");
        var datos = {
            nombre_proveedor: $.trim(lectura.nombre_proveedor || ""),
            sitio_web:        $.trim(lectura.sitio_web || ""),
            tipos:            lectura.tipos || []
        };

        if (!datos.nombre_proveedor) {
            return errorFormulario("El nombre del proveedor es obligatorio.");
        }

        var url = esEditar ? "endpoints/proveedores/actualizar.php"
                           : "endpoints/proveedores/crear.php";
        if (esEditar) { datos.id = editandoId; }

        var $btn = $("#btnGuardarProveedor").prop("disabled", true);
        $.ajax({
            url: url,
            method: "POST",
            contentType: "application/json",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(datos)
        }).done(function (res) {
            if (res && res.ok) {
                modalProveedor.cerrar();
                // Tanto el alta como la edicion aparecen en vivo por socket (el
                // propio actor recibe el evento); solo se recarga como respaldo
                // si el socket no esta activo.
                if (!socketActivo) { cargar(); }
                AX.exito(esEditar ? "El proveedor se actualizó correctamente." : "El proveedor se creó correctamente.");
            } else {
                errorFormulario((res && res.mensaje) || "No se pudo guardar el proveedor.");
                $btn.prop("disabled", false);
            }
        }).fail(function (xhr) {
            errorFormulario((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el proveedor.");
            $btn.prop("disabled", false);
        });
    }

    // Abrir el modal de alta.
    $("#btnNuevoProveedor").on("click", function () { abrirModal("crear"); });

    // Boton Guardar del modal (Cancelar/cerrar los maneja data-bs-dismiss).
    $("#btnGuardarProveedor").on("click", enviarFormulario);

    // Eliminacion real del proveedor: confirma, llama al endpoint y recarga.
    // El backend responde 409 si el proveedor tiene productos asociados.
    function eliminarProveedor(proveedor) {
        AX.confirmar({
            titulo: "Eliminar proveedor",
            texto: 'Se eliminará "' + (proveedor.nombre_proveedor || "este proveedor") +
                   '" junto con sus contactos, cuentas de acceso y referencias. Esta acción no se puede deshacer.',
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            AX.enviarJSON("endpoints/proveedores/eliminar.php", { id: proveedor.id }).then(function (res) {
                if (res.ok) {
                    AX.exito(res.mensaje || "Proveedor eliminado.");
                    // La fila se quita en vivo por socket; recarga de respaldo si no hay socket.
                    if (!socketActivo) { cargar(); }
                }
                else { AX.error(res.mensaje || "No se pudo eliminar el proveedor."); }
            }).catch(function () { AX.error("No se pudo eliminar el proveedor."); });
        });
    }

    // =================================================================
    //  Tiempo real (Socket.IO)
    //  El listado inserta en vivo la fila cuando se crea un proveedor (por
    //  cualquier usuario, incluido uno mismo), sin recargar ni volver a
    //  consultar la BD: el evento ya trae los datos de la fila. La escritura
    //  sigue yendo por HTTP al endpoint; el socket solo REPARTE lo confirmado.
    // =================================================================

    // Localiza la fila del listado cuyo registro embebido tiene ese id.
    function filaPorId(id) {
        return $tbody.find("tr").filter(function () {
            var d = AX.datosFila(this);
            return d && d.id === id;
        });
    }

    // Alta: inserta la fila en su posicion alfabetica (el listado va ordenado
    // por nombre). Solo aplica en la primera pagina y sin busqueda activa; en
    // otro caso la fila aparecera de forma natural al navegar/filtrar.
    function socketProveedorCreado(proveedor) {
        if (!proveedor || !proveedor.id) { return; }
        if (estado.pagina !== 1 || estado.buscar !== "") { return; }
        if (filaPorId(proveedor.id).length) { return; }      // evita duplicar
        $tbody.find(".tabla-vacia").closest("tr").remove();  // quita el placeholder "vacio"
        var $nueva = $(fila(proveedor));
        var nombre = proveedor.nombre_proveedor || "";
        var insertado = false;
        $tbody.find("tr").each(function () {
            var d = AX.datosFila(this);
            if (d && nombre.localeCompare(d.nombre_proveedor || "", "es", { sensitivity: "base" }) < 0) {
                $nueva.insertBefore(this);
                insertado = true;
                return false;
            }
        });
        if (!insertado) { $tbody.append($nueva); }
    }

    // Edicion: reemplaza la fila si esta en pantalla, fusionando sobre los datos
    // previos (conserva lo que el evento no traiga, p. ej. created_at).
    function socketProveedorActualizado(proveedor) {
        if (!proveedor || !proveedor.id) { return; }
        var $fila = filaPorId(proveedor.id);
        if (!$fila.length) { return; }
        var previo = AX.datosFila($fila[0]) || {};
        $fila.replaceWith(fila($.extend({}, previo, proveedor)));
    }

    // Borrado: quita la fila; si la tabla queda vacia, muestra el placeholder.
    function socketProveedorEliminado(payload) {
        var id = payload && payload.id;
        if (!id) { return; }
        var $fila = filaPorId(id);
        if (!$fila.length) { return; }
        $fila.remove();
        if (!$tbody.children().length) {
            $tbody.html(filaVacia("No hay proveedores registrados."));
        }
    }

    function conectarSocketProveedores() {
        var url = $vistaListado.data("ws");
        // Sin URL o sin la libreria cargada: la app sigue funcionando (con recarga).
        if (!url || typeof io === "undefined") { return; }
        var socket = io(url, { transports: ["websocket", "polling"], withCredentials: true });
        socket.on("connect", function () {
            socketActivo = true;
            socket.emit("unirse", "proveedores"); // entra a la sala del modulo
        });
        socket.on("disconnect", function () { socketActivo = false; });
        socket.on("proveedor:creado", socketProveedorCreado);
        socket.on("proveedor:actualizado", socketProveedorActualizado);
        socket.on("proveedor:eliminado", socketProveedorEliminado);
    }

    // Acciones de fila: editar y eliminar reutilizan los datos de la tabla.
    $tbody.on("click", "[data-accion]", function () {
        var accion = $(this).data("accion");
        var proveedor = AX.datosFila(this);
        if (accion === "editar") {
            if (proveedor) { abrirModal("editar", proveedor); }
            else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
        } else if (accion === "eliminar") {
            if (proveedor) { eliminarProveedor(proveedor); }
            else { AX.toast("No se pudieron leer los datos de la fila.", "error"); }
        }
    });

    cargar();
    conectarSocketProveedores(); // tiempo real: escucha altas
});
