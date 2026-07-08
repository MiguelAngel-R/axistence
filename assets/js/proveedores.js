/* =====================================================================
   AXISTENCE - proveedores.js
   Logica del modulo Proveedores. Carga el listado por AJAX, pinta la
   tabla y delega el paginador en general.js (AX). El formulario se
   reutiliza para alta y edicion, en el mismo espacio que la tabla, e
   incluye la seleccion multiple de "tipos de producto" (M:N).
   Espejo de assets/js/clientes.js.

   NOTA: la accion "Eliminar" queda solo maquetada (boton en la fila);
   por regla de negocio de esta fase NO se implementa borrado.
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyProveedores");
    var COLUMNAS = 5;
    var modo = "crear";      // "crear" | "editar"
    var editandoId = null;   // id del proveedor en edicion
    var modalProveedor = AX.modal("#modalProveedor");

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
                cargar(); // recarga la lista
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

    // Acciones de fila: editar reutiliza los datos ya cargados en la tabla.
    // Eliminar queda solo maquetado en esta fase (sin logica de borrado).
    $tbody.on("click", "[data-accion]", function () {
        var accion = $(this).data("accion");
        if (accion === "editar") {
            var proveedor = AX.datosFila(this);
            if (proveedor) {
                abrirModal("editar", proveedor);
            } else {
                AX.toast("No se pudieron leer los datos de la fila.", "error");
            }
        } else {
            AX.toast("Eliminación de proveedores: disponible próximamente.", "info");
        }
    });

    cargar();
});
