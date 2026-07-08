/* =====================================================================
   AXISTENCE - usuarios_internos.js  (Usuarios de Sistema)
   Carga el listado por AJAX, pinta la grilla y delega el paginador en
   general.js (AX). Las tres acciones por fila (Editar, Cambiar clave y
   Habilitar/Deshabilitar) se resuelven de forma asincrona con Fetch API,
   centralizadas en modales dinamicos independientes.

   NOTA: la accion de borrado NO existe en este modulo; el ciclo de vida de
   un usuario se gestiona con el toggle de estado (habilitar/deshabilitar).
   ===================================================================== */

$(function () {
    "use strict";

    var estado = { pagina: 1, porPagina: 30, buscar: "" };
    var $tbody = $("#tbodyUsuarios");
    var COLUMNAS = 8;

    // Controladores de los tres modales (general.js -> Bootstrap).
    var modalAlta   = AX.modal("#modalUsuario");
    var modalEditar = AX.modal("#modalEditarUsuario");
    var modalClave  = AX.modal("#modalClaveUsuario");

    // Estado de edicion / cambio de clave en curso.
    var editandoId    = null;
    var claveUsuarioId = null;

    // Cache de roles (se carga una sola vez y alimenta ambos <select>).
    var roles = null;

    var USERNAME_RE = /^[A-Za-z0-9._-]{3,50}$/;
    var EMAIL_RE    = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    // =================================================================
    //  Utilidades locales
    // =================================================================

    function filaVacia(mensaje) {
        return '<tr><td colspan="' + COLUMNAS + '" class="tabla-vacia">' +
               AX.escaparHtml(mensaje) + "</td></tr>";
    }

    // Fecha + hora (dd/mm/aaaa HH:MM) para la ultima sesion; "Nunca" si null.
    function fechaHora(iso) {
        if (!iso) { return '<span class="text-faint">Nunca</span>'; }
        var d = new Date(String(iso).replace(" ", "T"));
        if (isNaN(d.getTime())) { return AX.escaparHtml(iso); }
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return AX.escaparHtml(
            p(d.getDate()) + "/" + p(d.getMonth() + 1) + "/" + d.getFullYear() +
            " " + p(d.getHours()) + ":" + p(d.getMinutes())
        );
    }

    // Envio asincrono estandar (Fetch API) hacia un endpoint POST JSON.
    // Devuelve una promesa que resuelve { ok, mensaje, data } de la respuesta.
    function enviar(url, payload) {
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "include",
            body: JSON.stringify(payload)
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (cuerpo) {
                return {
                    ok:      resp.ok && cuerpo && cuerpo.ok === true,
                    mensaje: (cuerpo && cuerpo.mensaje) || "",
                    data:    (cuerpo && cuerpo.data) || null
                };
            });
        });
    }

    // =================================================================
    //  Listado
    // =================================================================

    function cargar() {
        $tbody.html(filaVacia("Cargando…"));
        $.ajax({
            url: "endpoints/usuarios_internos/listar.php",
            method: "GET",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: { pagina: estado.pagina, por_pagina: estado.porPagina, buscar: estado.buscar }
        }).done(function (res) {
            if (!res || !res.ok) {
                $tbody.html(filaVacia("No se pudo cargar la lista."));
                return;
            }
            pintar(res.data);
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.mensaje) || "Error al cargar los usuarios.";
            $tbody.html(filaVacia(msg));
            AX.limpiarFooter();
        });
    }

    function pintar(data) {
        var items = data.items || [];
        $tbody.html(items.length ? items.map(fila).join("") : filaVacia("No hay usuarios registrados."));

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

    function fila(u) {
        var activo = u.estado === "Activo";
        var claseEstado = activo ? "badge-estado--activo" : "badge-estado--inactivo";

        // El boton toggle cambia de accion/estilo segun el estado actual.
        var toggle = activo
            ? '<button type="button" class="btn-icono btn-icono--peligro" data-accion="toggle" title="Deshabilitar"><i class="bi bi-person-x"></i></button>'
            : '<button type="button" class="btn-icono btn-icono--exito" data-accion="toggle" title="Habilitar"><i class="bi bi-person-check"></i></button>';

        // Los datos de la fila quedan embebidos para operar sin volver a la BD.
        return '<tr data-registro="' + AX.escaparHtml(JSON.stringify(u)) + '">' +
            "<td>" + AX.escaparHtml(u.nombre_completo) + "</td>" +
            "<td>" + AX.escaparHtml(u.username) + "</td>" +
            "<td>" + AX.escaparHtml(u.rol) + "</td>" +
            "<td>" + AX.escaparHtml(u.correo) + "</td>" +
            "<td>" + AX.escaparHtml(u.telefono || "—") + "</td>" +
            '<td><span class="badge-estado ' + claseEstado + '">' + AX.escaparHtml(u.estado) + "</span></td>" +
            "<td>" + fechaHora(u.last_login) + "</td>" +
            '<td class="tabla-acciones">' +
                '<button type="button" class="btn-icono" data-accion="editar" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="btn-icono" data-accion="clave" title="Cambiar contrasena"><i class="bi bi-key"></i></button>' +
                toggle +
            "</td>" +
        "</tr>";
    }

    // --- Busqueda con retardo (debounce) ---------------------------
    var temporizador;
    $("#buscarUsuario").on("input", function () {
        var valor = this.value;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            estado.buscar = valor.trim();
            estado.pagina = 1;
            cargar();
        }, 350);
    });

    // =================================================================
    //  Roles (cache + pintado en un <select>)
    // =================================================================

    function cargarRoles(despues) {
        if (roles) { if (despues) { despues(); } return; }
        fetch("endpoints/usuarios_internos/roles.php", { credentials: "include" })
            .then(function (r) { return r.json(); })
            .then(function (j) { roles = (j && j.ok) ? (j.data || []) : []; if (despues) { despues(); } })
            .catch(function () { roles = []; if (despues) { despues(); } });
    }

    function pintarRoles($sel, seleccionado) {
        var opciones = '<option value="">Seleccione…</option>' +
            (roles || []).map(function (r) {
                return '<option value="' + AX.escaparHtml(r.id) + '">' + AX.escaparHtml(r.nombre) + "</option>";
            }).join("");
        $sel.html(opciones).val(seleccionado || "");
    }

    // =================================================================
    //  A. Alta de usuario
    // =================================================================

    function abrirAlta() {
        AX.limpiarFormulario("#formUsuario", "#formUsuarioError");
        $("#btnGuardarUsuario").prop("disabled", false);
        cargarRoles(function () { pintarRoles($("#fuRol"), ""); });
        modalAlta.abrir();
        $("#fuNombres").trigger("focus");
    }

    function enviarAlta() {
        var d = AX.leerFormulario("#formUsuario");
        var datos = {
            nombres:    $.trim(d.nombres || ""),
            apellidos:  $.trim(d.apellidos || ""),
            username:   $.trim(d.username || ""),
            correo:     $.trim(d.correo || ""),
            telefono:   $.trim(d.telefono || ""),
            contrasena: d.contrasena || "",
            rol_id:     d.rol_id || ""
        };

        if (!datos.nombres || !datos.apellidos || !datos.username || !datos.correo || !datos.rol_id) {
            return AX.errorFormulario("#formUsuarioError", "Completa todos los campos obligatorios.");
        }
        if (!USERNAME_RE.test(datos.username)) {
            return AX.errorFormulario("#formUsuarioError", "El nombre de usuario debe tener 3 a 50 caracteres (letras, numeros, punto, guion o guion bajo).");
        }
        if (!EMAIL_RE.test(datos.correo)) {
            return AX.errorFormulario("#formUsuarioError", "El correo no tiene un formato valido.");
        }
        if (datos.contrasena.length < 6) {
            return AX.errorFormulario("#formUsuarioError", "La contrasena debe tener al menos 6 caracteres.");
        }

        var $btn = $("#btnGuardarUsuario").prop("disabled", true);
        enviar("endpoints/usuarios_internos/crear.php", datos).then(function (res) {
            if (res.ok) {
                modalAlta.cerrar();
                cargar();
                AX.exito("El usuario se creó correctamente.");
            } else {
                AX.errorFormulario("#formUsuarioError", res.mensaje || "No se pudo crear el usuario.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formUsuarioError", "Error de conexión.");
            $btn.prop("disabled", false);
        });
    }

    // =================================================================
    //  B. Edicion de usuario (alcance restringido)
    // =================================================================

    function abrirEditar(u) {
        editandoId = u.id;
        AX.limpiarFormulario("#formEditarUsuario", "#formEditarUsuarioError");
        $("#feNombres").val(u.nombres || "");
        $("#feApellidos").val(u.apellidos || "");
        $("#feCorreo").val(u.correo || "");
        cargarRoles(function () { pintarRoles($("#feRol"), u.rol_id); });
        $("#btnActualizarUsuario").prop("disabled", false);
        modalEditar.abrir();
        $("#feNombres").trigger("focus");
    }

    function enviarEditar() {
        var d = AX.leerFormulario("#formEditarUsuario");
        var datos = {
            id:        editandoId,
            nombres:   $.trim(d.nombres || ""),
            apellidos: $.trim(d.apellidos || ""),
            correo:    $.trim(d.correo || ""),
            rol_id:    d.rol_id || ""
        };

        if (!datos.nombres || !datos.apellidos || !datos.correo || !datos.rol_id) {
            return AX.errorFormulario("#formEditarUsuarioError", "Completa todos los campos obligatorios.");
        }
        if (!EMAIL_RE.test(datos.correo)) {
            return AX.errorFormulario("#formEditarUsuarioError", "El correo no tiene un formato valido.");
        }

        var $btn = $("#btnActualizarUsuario").prop("disabled", true);
        enviar("endpoints/usuarios_internos/actualizar.php", datos).then(function (res) {
            if (res.ok) {
                modalEditar.cerrar();
                cargar();
                AX.exito("El usuario se actualizó correctamente.");
            } else {
                AX.errorFormulario("#formEditarUsuarioError", res.mensaje || "No se pudo actualizar el usuario.");
                $btn.prop("disabled", false);
            }
        }).catch(function () {
            AX.errorFormulario("#formEditarUsuarioError", "Error de conexión.");
            $btn.prop("disabled", false);
        });
    }

    // =================================================================
    //  C. Cambio de contrasena (modal independiente)
    // =================================================================

    function abrirClave(u) {
        claveUsuarioId = u.id;
        AX.limpiarFormulario("#formClave", "#formClaveError");
        $("#claveUsuarioNombre").text("Usuario: " + u.nombre_completo + " (" + u.username + ")");
        $("#btnGuardarClave").prop("disabled", false);
        modalClave.abrir();
        $("#fcNuevaClave").trigger("focus");
    }

    function enviarClave() {
        var clave = $("#fcNuevaClave").val() || "";
        if (clave.length < 6) {
            return AX.errorFormulario("#formClaveError", "La contrasena debe tener al menos 6 caracteres.");
        }

        var $btn = $("#btnGuardarClave").prop("disabled", true);
        enviar("endpoints/usuarios_internos/cambiar_clave.php", { id: claveUsuarioId, contrasena: clave })
            .then(function (res) {
                if (res.ok) {
                    modalClave.cerrar();
                    AX.exito("La contraseña se actualizó correctamente.");
                } else {
                    AX.errorFormulario("#formClaveError", res.mensaje || "No se pudo cambiar la contrasena.");
                    $btn.prop("disabled", false);
                }
            }).catch(function () {
                AX.errorFormulario("#formClaveError", "Error de conexión.");
                $btn.prop("disabled", false);
            });
    }

    // =================================================================
    //  D. Habilitar / Deshabilitar (toggle asincrono)
    // =================================================================

    function toggleEstado(u) {
        var activar = u.estado !== "Activo";
        AX.confirmar({
            titulo:   activar ? "Habilitar usuario" : "Deshabilitar usuario",
            texto:    (activar
                        ? "El usuario podrá iniciar sesión nuevamente."
                        : "El usuario no podrá iniciar sesión.") + " (" + u.nombre_completo + ")",
            confirmar: activar ? "Habilitar" : "Deshabilitar",
            peligro:  !activar
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            enviar("endpoints/usuarios_internos/cambiar_estado.php", { id: u.id }).then(function (res) {
                if (res.ok) {
                    cargar(); // refresca la fila sin recargar la pagina completa
                    AX.toast(activar ? "Usuario habilitado." : "Usuario deshabilitado.", "exito");
                } else {
                    AX.error(res.mensaje || "No se pudo cambiar el estado.");
                }
            }).catch(function () {
                AX.error("Error de conexión.");
            });
        });
    }

    // =================================================================
    //  Enlaces de eventos
    // =================================================================

    $("#btnNuevoUsuario").on("click", abrirAlta);
    $("#btnGuardarUsuario").on("click", enviarAlta);
    $("#btnActualizarUsuario").on("click", enviarEditar);
    $("#btnGuardarClave").on("click", enviarClave);

    // Acciones de fila (editar / clave / toggle): operan con los datos ya
    // cargados en la fila (data-registro), sin volver a consultar la BD.
    $tbody.on("click", "[data-accion]", function () {
        var accion  = $(this).data("accion");
        var usuario = AX.datosFila(this);
        if (!usuario) { AX.toast("No se pudieron leer los datos de la fila.", "error"); return; }

        if (accion === "editar")      { abrirEditar(usuario); }
        else if (accion === "clave")  { abrirClave(usuario); }
        else if (accion === "toggle") { toggleEstado(usuario); }
    });

    cargar();
});
