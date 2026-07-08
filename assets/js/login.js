/* =====================================================================
   AXISTENCE - login.js
   Logica de la pantalla de acceso (jQuery + AJAX a los endpoints PHP).
   Al autenticar correctamente redirige al panel (dashboard).
   ===================================================================== */

$(function () {
    "use strict";

    var $form     = $("#loginForm");
    var $correo   = $("#correo");
    var $clave    = $("#contrasena");
    var $btn      = $("#btnLogin");
    var $btnLabel = $("#btnLoginLabel");
    var $error    = $("#loginError");

    function mostrarError(msg) {
        $error.text(msg).removeClass("d-none");
    }
    function ocultarError() {
        $error.addClass("d-none").text("");
    }
    function setCargando(cargando) {
        $btn.prop("disabled", cargando);
        $btnLabel.text(cargando ? "Ingresando" : "Iniciar sesion");
        $btn.find(".spinner").toggleClass("d-none", !cargando);
    }

    // --- Envio del formulario --------------------------------------
    $form.on("submit", function (e) {
        e.preventDefault();
        ocultarError();

        var correo = $.trim($correo.val());
        var clave  = $clave.val();

        if (!correo || !clave) {
            mostrarError("Ingresa tu correo y contrasena.");
            return;
        }

        setCargando(true);

        $.ajax({
            url: "endpoints/auth/login.php",
            method: "POST",
            contentType: "application/json",
            dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify({ correo: correo, contrasena: clave })
        }).done(function (res) {
            if (res && res.ok) {
                // Sesion iniciada: al panel principal.
                window.location.href = "index.php?vista=dashboard";
            } else {
                mostrarError((res && res.mensaje) ? res.mensaje : "No se pudo iniciar sesion.");
                setCargando(false);
            }
        }).fail(function (xhr) {
            var msg = "No se pudo iniciar sesion.";
            if (xhr.responseJSON && xhr.responseJSON.mensaje) {
                msg = xhr.responseJSON.mensaje;
            } else if (xhr.status === 0) {
                msg = "No hay conexion con el servidor.";
            }
            mostrarError(msg);
            setCargando(false);
        });
    });

    // --- Mostrar / ocultar contrasena ------------------------------
    $("#togglePass").on("click", function () {
        var esPassword = $clave.attr("type") === "password";
        $clave.attr("type", esPassword ? "text" : "password");
        $(this).text(esPassword ? "Ocultar" : "Mostrar");
        $clave.focus();
    });
});
