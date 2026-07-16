/* =====================================================================
   AXISTENCE - app.js
   Comportamiento comun del shell de la aplicacion:
   - Menu lateral deslizable en movil (abrir/cerrar con fondo).
   - Cierre de sesion.
   ===================================================================== */

$(function () {
    "use strict";

    var $sidebar  = $("#appSidebar");
    var $backdrop = $("#appBackdrop");

    function abrirMenu() {
        $sidebar.addClass("is-open");
        $backdrop.removeClass("d-none");
    }
    function cerrarMenu() {
        $sidebar.removeClass("is-open");
        $backdrop.addClass("d-none");
    }

    $("#sidebarToggle").on("click", abrirMenu);
    $backdrop.on("click", cerrarMenu);

    // En movil, al elegir una opcion se cierra el menu.
    $(".app-nav__item").on("click", function () {
        if (window.innerWidth < 992) {
            cerrarMenu();
        }
    });

    // Cerrar sesion.
    $("#btnLogout").on("click", function () {
        $(this).prop("disabled", true);
        $.ajax({
            url: "endpoints/auth/logout.php",
            method: "POST",
            dataType: "json",
            xhrFields: { withCredentials: true }
        }).always(function () {
            window.location.href = "index.php?vista=login";
        });
    });

    // =====================================================================
    //  Campana de notificaciones (shell-wide).
    //  Carga por GET al iniciar y al abrir el panel, y recibe el empuje en
    //  vivo por Socket.IO (sala 'usuario:<id>'): 'notificacion:nueva' antepone
    //  + sube el badge + toast; 'notificacion:consumida' sincroniza las demas
    //  pestañas de la misma cuenta.
    //  Politica de UI: abrir el panel NO marca nada; hacer clic en una marca
    //  esa como leida (y navega si trae enlace); "Marcar leídas" limpia el
    //  badge conservando la lista; la X y "Limpiar" eliminan.
    // =====================================================================
    (function notificaciones() {
        var $wrap = $("#appNotif");
        if (!$wrap.length) { return; }

        var $toggle = $("#notifToggle");
        var $badge  = $("#notifBadge");
        var $panel  = $("#notifPanel");
        var $list   = $("#notifList");

        var LISTAR   = "endpoints/notificaciones/listar.php";
        var LEER     = "endpoints/notificaciones/leer.php";
        var ELIMINAR = "endpoints/notificaciones/eliminar.php";
        var TOKEN    = "endpoints/notificaciones/token.php";

        var noLeidas = 0;   // espejo del contador (para el empuje en vivo)

        function setBadge(n) {
            noLeidas = parseInt(n, 10) || 0;
            if (noLeidas > 0) { $badge.text(noLeidas > 99 ? "99+" : noLeidas).removeClass("d-none"); }
            else { $badge.addClass("d-none"); }
        }

        function itemHtml(n) {
            var url = (n.datos && n.datos.url) ? n.datos.url : "";
            var meta = AX.escaparHtml(n.modulo || "") + " · " + AX.formatearFechaHora(n.created_at);
            var msg  = n.mensaje ? '<div class="app-notif__msg">' + AX.escaparHtml(n.mensaje) + "</div>" : "";
            return '<div class="app-notif__item' + (n.leida ? "" : " is-no-leida") + '"' +
                       ' data-id="' + AX.escaparHtml(n.id) + '"' +
                       (url ? ' data-url="' + AX.escaparHtml(url) + '"' : "") + ">" +
                       '<span class="app-notif__dot" aria-hidden="true"></span>' +
                       '<div class="app-notif__body">' +
                           '<div class="app-notif__title">' + AX.escaparHtml(n.titulo || "") + "</div>" +
                           msg +
                           '<div class="app-notif__meta">' + meta + "</div>" +
                       "</div>" +
                       '<button type="button" class="app-notif__del" data-id="' + AX.escaparHtml(n.id) +
                           '" title="Eliminar" aria-label="Eliminar">&times;</button>' +
                   "</div>";
        }

        function renderLista(items) {
            if (!items || !items.length) {
                $list.html('<div class="app-notif__empty">No tienes notificaciones.</div>');
                return;
            }
            $list.html(items.map(itemHtml).join(""));
        }

        function cargar() {
            $.ajax({ url: LISTAR, method: "GET", dataType: "json", xhrFields: { withCredentials: true } })
                .done(function (res) {
                    var d = (res && res.ok && res.data) || {};
                    renderLista(d.notificaciones || []);
                    setBadge(d.no_leidas || 0);
                })
                .fail(function () {
                    $list.html('<div class="app-notif__empty">No se pudieron cargar.</div>');
                });
        }

        function abrir()  { $panel.removeClass("d-none"); $toggle.attr("aria-expanded", "true"); cargar(); }
        function cerrar() { $panel.addClass("d-none"); $toggle.attr("aria-expanded", "false"); }

        $toggle.on("click", function (e) {
            e.stopPropagation();
            if ($panel.hasClass("d-none")) { abrir(); } else { cerrar(); }
        });
        // Clic fuera del componente o Escape -> cierra el panel.
        $(document).on("click", function (e) {
            if (!$panel.hasClass("d-none") && !$(e.target).closest("#appNotif").length) { cerrar(); }
        });
        $(document).on("keydown", function (e) { if (e.key === "Escape") { cerrar(); } });

        // Clic en una notificacion: marcarla leida (si no lo estaba) y navegar
        // a su enlace si lo trae. El boton eliminar tiene su propio handler.
        $list.on("click", ".app-notif__item", function (e) {
            if ($(e.target).closest(".app-notif__del").length) { return; }
            var $it = $(this), id = $it.attr("data-id"), url = $it.attr("data-url");
            if ($it.hasClass("is-no-leida")) {
                $it.removeClass("is-no-leida");
                $.ajax({ url: LEER, method: "POST", contentType: "application/json", dataType: "json",
                         xhrFields: { withCredentials: true }, data: JSON.stringify({ id: id }) })
                    .done(function (res) { if (res && res.ok && res.data) { setBadge(res.data.no_leidas); } });
            }
            if (url) { window.location.href = url; }
        });

        // Eliminar una notificacion.
        $list.on("click", ".app-notif__del", function (e) {
            e.stopPropagation();
            var $it = $(this).closest(".app-notif__item"), id = $(this).attr("data-id");
            $.ajax({ url: ELIMINAR, method: "DELETE", contentType: "application/json", dataType: "json",
                     xhrFields: { withCredentials: true }, data: JSON.stringify({ id: id }) })
                .done(function (res) {
                    if (res && res.ok) {
                        $it.remove();
                        if (res.data) { setBadge(res.data.no_leidas); }
                        if (!$list.children(".app-notif__item").length) { renderLista([]); }
                    }
                });
        });

        // Marcar todas como leidas (baja el badge, conserva la lista).
        $("#notifLeerTodas").on("click", function () {
            $.ajax({ url: LEER, method: "POST", contentType: "application/json", dataType: "json",
                     xhrFields: { withCredentials: true }, data: JSON.stringify({ todas: true }) })
                .done(function (res) {
                    if (res && res.ok) {
                        $list.find(".app-notif__item").removeClass("is-no-leida");
                        if (res.data) { setBadge(res.data.no_leidas); }
                    }
                });
        });

        // Limpiar (eliminar todas), con confirmacion.
        $("#notifLimpiar").on("click", function () {
            AX.confirmar({ titulo: "Limpiar notificaciones", texto: "¿Eliminar todas tus notificaciones?",
                           confirmar: "Eliminar", peligro: true })
                .then(function (r) {
                    if (!r || !r.isConfirmed) { return; }
                    $.ajax({ url: ELIMINAR, method: "DELETE", contentType: "application/json", dataType: "json",
                             xhrFields: { withCredentials: true }, data: JSON.stringify({ todas: true }) })
                        .done(function (res) { if (res && res.ok) { renderLista([]); setBadge(0); } });
                });
        });

        // =============================================================
        //  Tiempo real (Socket.IO): empuje en vivo hacia la sala del
        //  usuario 'usuario:<id>'. El navegador se identifica con un token
        //  firmado por PHP (el usuario lo pone PHP, no el navegador), asi
        //  nadie escucha las notificaciones de otro. La BD sigue siendo la
        //  fuente de verdad: si el socket no esta, la campana funciona con
        //  la carga por GET (al entrar y al abrir el panel).
        // =============================================================

        // Antepone una notificacion recien llegada al panel (dedupe por id;
        // respeta el estado vacio). No toca el badge (lo maneja quien llama).
        function anteponer(n) {
            if (!n || !n.id) { return; }
            if ($list.find('.app-notif__item[data-id="' + n.id + '"]').length) { return; }
            $list.find(".app-notif__empty").remove();
            $list.prepend(itemHtml(n));
        }

        // Llego una notificacion nueva: la antepone, sube el badge y avisa
        // con un toast (aunque el panel este cerrado).
        function alRecibirNueva(n) {
            anteponer(n);
            setBadge(noLeidas + 1);
            if (window.AX && AX.toast) { AX.toast(n.titulo || "Nueva notificacion", "info"); }
        }

        // Sincroniza esta pestaña cuando la misma cuenta consume una
        // notificacion en OTRA (o en esta: es idempotente). El servidor manda
        // el contador autoritativo de no leidas.
        function alConsumir(p) {
            if (!p) { return; }
            switch (p.accion) {
                case "leida":
                    $list.find('.app-notif__item[data-id="' + p.id + '"]').removeClass("is-no-leida");
                    break;
                case "todas_leidas":
                    $list.find(".app-notif__item").removeClass("is-no-leida");
                    break;
                case "eliminada":
                    $list.find('.app-notif__item[data-id="' + p.id + '"]').remove();
                    if (!$list.children(".app-notif__item").length) { renderLista([]); }
                    break;
                case "todas_eliminadas":
                    renderLista([]);
                    break;
            }
            setBadge(p.no_leidas);
        }

        function conectarSocket() {
            var url = $wrap.data("ws");
            // Sin URL o sin la libreria: la campana sigue con la carga por GET.
            if (!url || typeof io === "undefined") { return; }

            var socket = io(url, { transports: ["websocket", "polling"], withCredentials: true });

            // Pide un token fresco a PHP (TTL corto) y se identifica. Se hace en
            // cada (re)conexion porque el token caduca pronto.
            function autenticar() {
                $.ajax({ url: TOKEN, method: "POST", dataType: "json", xhrFields: { withCredentials: true } })
                    .done(function (res) {
                        if (res && res.ok && res.data && res.data.token) {
                            socket.emit("autenticar_usuario", res.data.token);
                        }
                    });
            }

            socket.on("connect", autenticar);
            socket.on("no_autenticado", function () { /* token vencido/invalido: se reintenta al reconectar */ });
            socket.on("notificacion:nueva", alRecibirNueva);
            socket.on("notificacion:consumida", alConsumir);
        }

        // Carga inicial (badge + lista) al entrar a cualquier vista privada.
        cargar();
        conectarSocket();
    })();
});
