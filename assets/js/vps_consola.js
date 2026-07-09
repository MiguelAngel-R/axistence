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
    var $sug, $sugLista;

    // Autocompletar: historial cargado del VPS + linea que se esta tecleando
    // (reconstruida heuristicamente) + estado del panel de sugerencias.
    var sugerencias = [];   // [{comando, veces, ultimo}] ordenado por frecuencia
    var lineaActual = "";   // lo tecleado desde el ultimo Enter (aproximado)
    var enEscape = false;   // dentro de una secuencia ANSI (flechas, etc.)
    var sugVisibles = [];   // subconjunto que se muestra ahora
    var sugActiva = 0;      // indice resaltado dentro de sugVisibles

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
        // Teclado -> VPS (y reconstruccion de la linea para autocompletar).
        term.onData(function (d) {
            if (socket && conectado) {
                socket.emit("input", d);
                procesarTecleo(d);
            }
        });
        // Tab/flechas/Escape solo se interceptan si el panel esta abierto;
        // si no, la tecla sigue su curso normal hacia el shell (Tab nativo).
        term.attachCustomKeyEventHandler(manejarTeclaSug);
        $(window).on("resize.consola", ajustar);
    }

    function ajustar() {
        if (!fit) { return; }
        try { fit.fit(); } catch (e) { /* contenedor aun no visible */ }
        if (socket && conectado && term) {
            socket.emit("resize", { cols: term.cols, rows: term.rows });
        }
    }

    // --- Autocompletar (sugerencias del historial del VPS) -----------
    // El panel esta "abierto" si es visible y tiene al menos una sugerencia.
    function sugAbierta() {
        return $sug && !$sug.prop("hidden") && sugVisibles.length > 0;
    }
    function ocultarSug() {
        sugVisibles = [];
        sugActiva = 0;
        if ($sug) { $sug.prop("hidden", true); }
    }
    // Carga (una vez al conectar) los comandos ya usados en este VPS.
    function cargarSugerencias() {
        var id = vpsId();
        if (!id) { return; }
        $.ajax({
            url: "endpoints/vps/consola_sugerencias.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true },
            data: { vps_id: id }
        }).done(function (res) {
            sugerencias = (res && res.ok && res.data && res.data.comandos) || [];
        }).fail(function () { sugerencias = []; });
    }
    // Suma al vuelo un comando recien tecleado para poder sugerirlo enseguida.
    function recordarComando(cmd) {
        cmd = (cmd || "").trim();
        if (!cmd) { return; }
        for (var i = 0; i < sugerencias.length; i++) {
            if (sugerencias[i].comando === cmd) {
                sugerencias[i].veces = (parseInt(sugerencias[i].veces, 10) || 0) + 1;
                return;
            }
        }
        sugerencias.unshift({ comando: cmd, veces: 1 });
    }
    // Reconstruye la linea en curso a partir de las teclas (misma heuristica
    // que el server Node). NO es el estado real del shell: es solo una ayuda,
    // por eso ante una secuencia ANSI (flechas/historial) se descarta la linea.
    function procesarTecleo(d) {
        for (var i = 0; i < d.length; i++) {
            var ch = d[i];
            if (enEscape) {
                if (/[a-zA-Z~]/.test(ch)) { enEscape = false; }
                continue;
            }
            if (ch === "\x1b") {                       // inicio de secuencia ANSI
                enEscape = true;
                lineaActual = "";
            } else if (ch === "\r" || ch === "\n") {   // Enter: se envio el comando
                recordarComando(lineaActual);
                lineaActual = "";
            } else if (ch === "\x7f" || ch === "\b") { // backspace
                lineaActual = lineaActual.slice(0, -1);
            } else if (ch === "\x03") {                // Ctrl+C
                lineaActual = "";
            } else if (ch === "\t") {                  // Tab: lo maneja manejarTeclaSug
                /* noop */
            } else if (ch.charCodeAt(0) >= 0x20) {     // caracter imprimible
                lineaActual += ch;
            }
        }
        actualizarSugerencias();
    }
    // Filtra el historial por prefijo de lo tecleado y pinta el panel.
    function actualizarSugerencias() {
        if (lineaActual.trim().length < 1 || !sugerencias.length) { ocultarSug(); return; }
        var vistas = {};
        sugVisibles = [];
        for (var i = 0; i < sugerencias.length && sugVisibles.length < 6; i++) {
            var c = sugerencias[i].comando;
            // Prefijo exacto (sensible a mayusculas: el sufijo debe encajar tal cual).
            if (c.length > lineaActual.length && c.indexOf(lineaActual) === 0 && !vistas[c]) {
                vistas[c] = true;
                sugVisibles.push(sugerencias[i]);
            }
        }
        if (!sugVisibles.length) { ocultarSug(); return; }
        sugActiva = 0;
        renderSug();
        $sug.prop("hidden", false);
    }
    function renderSug() {
        $sugLista.empty();
        sugVisibles.forEach(function (s, idx) {
            var resto = s.comando.slice(lineaActual.length);
            // Comando en un solo bloque: la parte tecleada (resaltada) + el resto
            // PEGADO, sin gap intermedio, para que se lea como el comando exacto.
            var $cmd = $('<span class="consola__sug-cmd">').append(
                $('<span class="match">').text(lineaActual),
                document.createTextNode(resto)
            );
            var $li = $('<li class="consola__sug-item">')
                .attr("data-idx", idx)
                .toggleClass("is-active", idx === sugActiva)
                .append($cmd);
            var veces = parseInt(s.veces, 10) || 0;
            if (veces > 1) {
                $li.append($('<span class="consola__sug-veces">').text("×" + veces));
            }
            $sugLista.append($li);
        });
    }
    // Completa: envia al shell solo el sufijo que falta (lo ya tecleado se queda).
    function aceptarSugerencia(idx) {
        var s = sugVisibles[idx];
        if (!s || !socket || !conectado) { return; }
        var resto = s.comando.slice(lineaActual.length);
        if (resto) { socket.emit("input", resto); }
        lineaActual = s.comando;
        ocultarSug();
        if (term) { term.focus(); }
    }
    // Intercepta teclas SOLO con el panel abierto; si no, devuelve true y la
    // tecla sigue hacia el shell (incluye el autocompletado nativo con Tab).
    function manejarTeclaSug(e) {
        if (e.type !== "keydown" || !sugAbierta()) { return true; }
        if (e.key === "Tab") {
            e.preventDefault();               // evita perder el foco de la terminal
            aceptarSugerencia(sugActiva);
            return false;
        }
        if (e.key === "ArrowDown") {
            sugActiva = Math.min(sugActiva + 1, sugVisibles.length - 1);
            renderSug();
            return false;
        }
        if (e.key === "ArrowUp") {
            sugActiva = Math.max(sugActiva - 1, 0);
            renderSug();
            return false;
        }
        if (e.key === "Escape") { ocultarSug(); return false; }
        return true;
    }

    // --- Credenciales ------------------------------------------------
    // selectId (opcional): id de credencial a dejar seleccionada tras recargar
    // (util despues de crear una desde el modal).
    function cargarCredenciales(selectId) {
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
            if (selectId) { $sel.val(selectId); }
            $btnCon.prop("disabled", conectado);
        }).fail(function () {
            $sel.html('<option value="">— Error al cargar —</option>');
            $btnCon.prop("disabled", true);
        });
    }

    // --- Modal "Agregar credencial" ----------------------------------
    function modalCred() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById("modalAddCredencial"));
    }
    function errorCred(msg) {
        $("#formAddCredError").text(msg).removeClass("d-none");
    }
    // Muestra los campos segun el tipo de autenticacion elegido.
    function toggleCamposCred() {
        var esClave = $("#acTipoAuth").val() === "clave_privada";
        $('#modalAddCredencial [data-rol="campo-password"]').toggleClass("d-none", esClave);
        $('#modalAddCredencial [data-rol="campo-clave"]').toggleClass("d-none", !esClave);
    }
    function abrirModalCred() {
        if (!vpsId()) { AX.error("No se pudo determinar el VPS."); return; }
        document.getElementById("formAddCred").reset();
        $("#acPuerto").val(22);
        $("#acTipoAuth").val("password");
        toggleCamposCred();
        $("#formAddCredError").addClass("d-none").text("");
        modalCred().show();
    }
    function guardarCredencial() {
        var tipo = $("#acTipoAuth").val();
        var payload = {
            vps_id: vpsId(),
            etiqueta: $("#acEtiqueta").val().trim(),
            host: $("#acHost").val().trim(),
            puerto: parseInt($("#acPuerto").val(), 10) || 22,
            usuario: $("#acUsuario").val().trim(),
            tipo_auth: tipo,
            secreto: tipo === "clave_privada" ? $("#acClave").val() : $("#acPassword").val(),
            passphrase: tipo === "clave_privada" ? $("#acPassphrase").val() : ""
        };
        if (!payload.host || !payload.usuario || !payload.secreto) {
            errorCred("Host, usuario y " + (tipo === "clave_privada" ? "clave privada" : "contraseña") + " son obligatorios.");
            return;
        }
        var $btn = $("#btnGuardarAddCred").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_credenciales.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(payload)
        }).done(function (res) {
            if (res && res.ok) {
                modalCred().hide();
                if (AX && AX.toast) { AX.toast("Credencial guardada."); }
                cargarCredenciales(res.data && res.data.id);
            } else {
                errorCred((res && res.mensaje) || "No se pudo guardar la credencial.");
            }
            $btn.prop("disabled", false);
        }).fail(function (xhr) {
            errorCred((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar la credencial.");
            $btn.prop("disabled", false);
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
        lineaActual = ""; enEscape = false; ocultarSug();
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
            cargarSugerencias();
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
        lineaActual = ""; enEscape = false; ocultarSug();
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
        $sug = $("#consolaSugerencias");
        $sugLista = $("#consolaSugLista");

        $btnCon.on("click", conectar);
        $btnDes.on("click", desconectar);
        $("#consolaRefrescar").on("click", cargarHistorial);

        // Clic en una sugerencia = completar ese comando.
        $sugLista.on("click", ".consola__sug-item", function () {
            aceptarSugerencia(parseInt($(this).attr("data-idx"), 10));
        });

        // Modal "Agregar credencial"
        $("#consolaAddCred").on("click", abrirModalCred);
        $("#acTipoAuth").on("change", toggleCamposCred);
        $("#btnGuardarAddCred").on("click", guardarCredencial);
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
