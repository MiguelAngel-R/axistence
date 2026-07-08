/* =====================================================================
   Modulo: VPS / Consola SSH (pestaña del detalle, viewProducto).
   Terminal en el navegador con xterm.js conectada por Socket.IO al server
   Node (websockets/), que mantiene el SSH. El flujo:
     1. Se pide un token a endpoints/vps/consola_token.php (sesion + permiso).
     2. Se abre el socket enviando el token en `auth`.
     3. Node valida el token contra PHP, abre el SSH y hace streaming.
   Este archivo NO toca la BD ni el SSH: solo UI + socket. Depende de globals
   ya cargados por index.php: Terminal (xterm), FitAddon, io (socket.io) y AX.
   ===================================================================== */
(function () {
    "use strict";

    var term = null, fit = null, socket = null;
    var conectado = false;
    var socketVpsId = "";
    var $cont, $sel, $btnCon, $btnDes, $estado, $sesiones, $comandos;

    // --- Utilidades --------------------------------------------------
    function vpsId() {
        var el = document.getElementById("vistaDetalle");
        return el ? (el.getAttribute("data-vps-id") || "") : "";
    }
    function wsUrl() {
        return ($cont && $cont.data("ws")) || "http://127.0.0.1:3001";
    }
    function setEstado(txt, estado) {
        $estado.text(txt).attr("data-estado", estado);
    }

    // --- Terminal ----------------------------------------------------
    function initTerminal() {
        if (term || typeof Terminal === "undefined") { return; }
        term = new Terminal({
            cursorBlink: true,
            fontFamily: "'Cascadia Code','Consolas','Courier New',monospace",
            fontSize: 13,
            scrollback: 2000,
            theme: { background: "#0f172a", foreground: "#e2e8f0", cursor: "#e2e8f0" }
        });
        if (typeof FitAddon !== "undefined" && FitAddon.FitAddon) {
            fit = new FitAddon.FitAddon();
            term.loadAddon(fit);
        }
        term.open(document.getElementById("consolaTerminal"));
        ajustar();
        // Teclado -> VPS.
        term.onData(function (d) {
            if (socket && conectado) { socket.emit("input", d); }
        });
        $(window).on("resize.consola", ajustar);
    }

    function ajustar() {
        if (!fit) { return; }
        try { fit.fit(); } catch (e) { /* contenedor aun no visible */ }
        if (socket && conectado && term) {
            socket.emit("resize", { cols: term.cols, rows: term.rows });
        }
    }

    // --- Credenciales ------------------------------------------------
    function cargarCredenciales() {
        var id = vpsId();
        if (!id) { return; }
        $.ajax({
            url: "endpoints/vps/consola_credenciales.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { vps_id: id }
        }).done(function (res) {
            var arr = (res && res.ok && res.data) || [];
            $sel.empty();
            if (!arr.length) {
                $sel.append('<option value="">— Sin credenciales —</option>');
                $btnCon.prop("disabled", true);
                return;
            }
            arr.forEach(function (c) {
                var etq = c.etiqueta ? c.etiqueta + " · " : "";
                $sel.append($("<option>").val(c.id).text(etq + c.usuario + "@" + c.host + ":" + c.puerto));
            });
            $btnCon.prop("disabled", conectado);
        }).fail(function () {
            $sel.html('<option value="">— Error al cargar —</option>');
            $btnCon.prop("disabled", true);
        });
    }

    // --- Conexion ----------------------------------------------------
    function conectar() {
        if (conectado) { return; }
        var id = vpsId();
        if (!id) { AX.error("No se pudo determinar el VPS."); return; }
        if (typeof io === "undefined" || typeof Terminal === "undefined") {
            AX.error("No se cargaron las librerias de la consola (xterm/socket.io).");
            return;
        }
        setEstado("Solicitando token…", "wait");
        $btnCon.prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_token.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify({ vps_id: id, credencial_id: $sel.val() || "" })
        }).done(function (res) {
            if (!res || !res.ok || !res.data || !res.data.token) {
                setEstado("Error", "error");
                $btnCon.prop("disabled", false);
                AX.error((res && res.mensaje) || "No se pudo obtener el token.");
                return;
            }
            abrirSocket(res.data.token);
        }).fail(function (xhr) {
            setEstado("Error", "error");
            $btnCon.prop("disabled", false);
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo obtener el token.");
        });
    }

    function abrirSocket(token) {
        initTerminal();
        if (term) { term.reset(); }
        socketVpsId = vpsId();
        setEstado("Conectando…", "wait");

        socket = io(wsUrl(), {
            auth: { token: token, cols: term ? term.cols : 80, rows: term ? term.rows : 24 },
            reconnection: false,
            transports: ["websocket", "polling"]
        });

        socket.on("autorizado", function () { setEstado("Autorizado…", "wait"); });
        socket.on("no_autorizado", function (d) {
            setEstado("No autorizado", "error");
            if (term) { term.writeln("\r\n*** No autorizado: " + ((d && d.mensaje) || "") + " ***"); }
            finalizar();
        });
        socket.on("ssh_listo", function () {
            conectado = true;
            setEstado("Conectado", "on");
            $btnDes.prop("disabled", false);
            $sel.prop("disabled", true);
            if (term) { term.focus(); }
            ajustar();
        });
        socket.on("output", function (d) { if (term) { term.write(d); } });
        socket.on("ssh_error", function (d) {
            setEstado("Error SSH", "error");
            if (term) { term.writeln("\r\n*** " + ((d && d.mensaje) || "Error SSH") + " ***"); }
        });
        socket.on("ssh_cerrado", function (d) {
            if (term) { term.writeln("\r\n*** " + ((d && d.mensaje) || "Sesion cerrada") + " ***"); }
            finalizar();
        });
        socket.on("disconnect", function () { finalizar(); });
        socket.on("connect_error", function () {
            setEstado("Sin conexion al servidor", "error");
            AX.error("No se pudo conectar al servidor de consola. ¿Esta corriendo el server de websockets?");
            finalizar();
        });
    }

    function finalizar() {
        conectado = false;
        $btnDes.prop("disabled", true);
        $sel.prop("disabled", false);
        $btnCon.prop("disabled", !$sel.find("option[value!='']").length);
        if ($estado.attr("data-estado") !== "error") { setEstado("Desconectado", "off"); }
        if (socket) {
            try { socket.removeAllListeners(); socket.disconnect(); } catch (e) { /* noop */ }
            socket = null;
        }
        cargarHistorial();
    }

    function desconectar() {
        if (socket) { socket.disconnect(); }
        finalizar();
    }

    // --- Historial ---------------------------------------------------
    function cargarHistorial() {
        var id = vpsId();
        if (!id) { return; }
        $.ajax({
            url: "endpoints/vps/consola_historial.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { vps_id: id }
        }).done(function (res) {
            var arr = (res && res.ok && res.data && res.data.sesiones) || [];
            $comandos.empty();
            if (!arr.length) {
                $sesiones.html('<li class="consola__vacio">Sin sesiones.</li>');
                return;
            }
            $sesiones.empty();
            arr.forEach(function (s) {
                var ico = s.estado === "activa" ? "●" : (s.estado === "error" ? "⚠" : "○");
                var $li = $('<li class="consola__sesion">').attr("data-id", s.id).append(
                    $('<span class="consola__sesion-ico">').attr("data-estado", s.estado).text(ico),
                    $('<span class="consola__sesion-info">').append(
                        $("<b>").text(s.usuario || "—"),
                        $("<small>").text(AX.formatearFecha(s.inicio) + " · " + (s.total_comandos || 0) + " cmd")
                    )
                );
                $sesiones.append($li);
            });
        });
    }

    function verComandos(sesionId) {
        var id = vpsId();
        if (!id) { return; }
        $comandos.html('<div class="consola__cargando">Cargando…</div>');
        $.ajax({
            url: "endpoints/vps/consola_historial.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { vps_id: id, sesion_id: sesionId }
        }).done(function (res) {
            var arr = (res && res.ok && res.data && res.data.comandos) || [];
            if (!arr.length) { $comandos.html('<div class="consola__vacio">Sin comandos.</div>'); return; }
            var $ol = $('<ol class="consola__cmdlist">');
            arr.forEach(function (c) {
                $ol.append($("<li>").append($("<code>").text(c.comando)));
            });
            $comandos.empty().append($ol);
        }).fail(function () {
            $comandos.html('<div class="consola__vacio">No se pudo cargar.</div>');
        });
    }

    // --- Arranque ----------------------------------------------------
    $(function () {
        $cont = $(".consola");
        if (!$cont.length) { return; } // el detalle de VPS no esta en esta pagina

        $sel = $("#consolaCredencial");
        $btnCon = $("#consolaConectar");
        $btnDes = $("#consolaDesconectar");
        $estado = $("#consolaEstado");
        $sesiones = $("#consolaSesiones");
        $comandos = $("#consolaComandos");

        $btnCon.on("click", conectar);
        $btnDes.on("click", desconectar);
        $("#consolaRefrescar").on("click", cargarHistorial);
        $sesiones.on("click", ".consola__sesion", function () {
            $sesiones.find(".consola__sesion").removeClass("is-active");
            $(this).addClass("is-active");
            verComandos($(this).attr("data-id"));
        });

        // Al mostrar la pestaña Consola: el contenedor ya es visible, se puede
        // inicializar/ajustar la terminal. Si cambio el VPS, cerrar lo previo.
        $("#tabbtn-consola").on("shown.bs.tab", function () {
            if (conectado && socketVpsId !== vpsId()) { desconectar(); }
            initTerminal();
            ajustar();
            cargarCredenciales();
            cargarHistorial();
        });

        // Volver al listado cierra la sesion abierta.
        $(document).on("click", "#btnVolverDetalle", function () {
            if (conectado) { desconectar(); }
        });
        $(window).on("beforeunload", function () {
            if (socket) { try { socket.disconnect(); } catch (e) { /* noop */ } }
        });
    });
})();
