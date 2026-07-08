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
});
