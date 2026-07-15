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
    var $btnInstr, $instrLista;

    // Catalogo de instrucciones rapidas (comandos configurables). Global, no
    // depende del VPS. Se (re)carga al abrir el modal y tras cada alta/edicion/
    // borrado para reflejar los cambios.
    var instrGrupos = null;   // [{categoria, items:[{id,titulo,descripcion,comando}]}]

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
    // URL BASE del servidor unico de websockets. La consola vive en el
    // namespace "/consola" (se agrega al abrir el socket); los listados usan
    // el namespace por defecto del mismo server/puerto.
    function wsUrl() {
        var base = ($cont && $cont.data("ws")) || "http://127.0.0.1:3002";
        return String(base).replace(/\/+$/, ""); // sin barra final -> evita "//consola"
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
        // Se pide la palabra maestra para CIFRAR la credencial. Va en el payload,
        // se usa en el servidor y no se guarda; aqui no se retiene.
        AX.pedirClave({
            titulo: "Palabra maestra",
            texto: "Necesaria para cifrar esta credencial antes de guardarla.",
            confirmar: "Guardar"
        }).then(function (clave) {
            if (!clave) { return; }        // cancelado: no se guarda
            payload.clave_maestra = clave;
            enviarCredencial(payload);
        });
    }

    function enviarCredencial(payload) {
        var $btn = $("#btnGuardarAddCred").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_credenciales.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(payload)
        }).done(function (res) {
            payload.clave_maestra = null;
            if (res && res.ok) {
                modalCred().hide();
                if (AX && AX.toast) { AX.toast("Credencial guardada."); }
                cargarCredenciales(res.data && res.data.id);
            } else {
                errorCred((res && res.mensaje) || "No se pudo guardar la credencial.");
            }
            $btn.prop("disabled", false);
        }).fail(function (xhr) {
            payload.clave_maestra = null;
            $btn.prop("disabled", false);
            // 409 = la llave maestra aun no esta configurada: se ofrece hacerlo.
            if (xhr.status === 409) {
                modalCred().hide();
                AX.confirmar({
                    titulo: "Falta la llave maestra",
                    texto: "Debes configurar la palabra maestra del sistema antes de guardar credenciales. ¿Configurarla ahora?",
                    confirmar: "Configurar"
                }).then(function (r) { if (r.isConfirmed) { abrirModalLlave(); } });
                return;
            }
            errorCred((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar la credencial.");
        });
    }

    // --- Llave maestra (configurar / cambiar) ------------------------
    var llaveConfigurada = false;

    function modalLlave() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById("modalLlaveMaestra"));
    }
    function errorLlave(msg) {
        $("#formLlaveError").text(msg).removeClass("d-none");
    }
    // Consulta el estado (configurada o no) y alterna la UI del modal.
    function abrirModalLlave() {
        $("#formLlave")[0].reset();
        $("#formLlaveError").addClass("d-none").text("");
        $.ajax({
            url: "endpoints/vps/consola_llave.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }
        }).done(function (res) {
            llaveConfigurada = !!(res && res.ok && res.data && res.data.configurada);
            pintarModoLlave();
            modalLlave().show();
        }).fail(function () {
            llaveConfigurada = false;
            pintarModoLlave();
            modalLlave().show();
        });
    }
    // Modo "configurar" (solo palabra nueva) vs "cambiar" (actual + nueva).
    function pintarModoLlave() {
        $("#llaveTitulo").text(llaveConfigurada ? "Cambiar palabra maestra" : "Configurar palabra maestra");
        $('#modalLlaveMaestra [data-rol="campo-actual"]').toggleClass("d-none", !llaveConfigurada);
        $("#llaveActual").prop("required", llaveConfigurada);
        $("#llaveAviso").text(llaveConfigurada
            ? "Cambiar la palabra no re-cifra las credenciales; solo cambia la llave que las abre."
            : "Esta palabra cifra todas las credenciales SSH. No se guarda en ningún lado: si se olvida, no se podrán descifrar.");
    }
    function guardarLlave() {
        var nueva = $("#llaveNueva").val();
        var repetir = $("#llaveRepetir").val();
        var actual = $("#llaveActual").val();
        if (!nueva || nueva.length < 8) { errorLlave("La palabra maestra debe tener al menos 8 caracteres."); return; }
        if (nueva !== repetir) { errorLlave("La palabra nueva y su repetición no coinciden."); return; }
        if (llaveConfigurada && !actual) { errorLlave("Escribe la palabra maestra actual."); return; }

        var payload = { clave_maestra_nueva: nueva };
        if (llaveConfigurada) { payload.clave_maestra_actual = actual; }

        var $btn = $("#btnGuardarLlave").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_llave.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(payload)
        }).done(function (res) {
            $btn.prop("disabled", false);
            if (res && res.ok) {
                modalLlave().hide();
                if (AX && AX.toast) { AX.toast(llaveConfigurada ? "Palabra maestra actualizada." : "Palabra maestra configurada."); }
            } else {
                errorLlave((res && res.mensaje) || "No se pudo guardar.");
            }
        }).fail(function (xhr) {
            $btn.prop("disabled", false);
            errorLlave((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar.");
        });
    }

    // --- Instrucciones rapidas (catalogo configurable de comandos) ----
    var instrPorId = {};   // id -> {id, categoria, titulo, descripcion, comando}
    var instrEditId = "";  // id en edicion ("" = alta); lo usa guardarInstr

    function modalInstr() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById("modalInstrucciones"));
    }
    // Alterna entre la vista LISTA y la vista FORMULARIO dentro del modal.
    function modoFormInstr(esForm) {
        $("#instrVista").prop("hidden", esForm);
        $("#instrForm").prop("hidden", !esForm);
        $('#modalInstrucciones [data-rol="pie-lista"]').prop("hidden", esForm);
        $('#modalInstrucciones [data-rol="pie-form"]').prop("hidden", !esForm);
    }

    // Pide el catalogo al backend, lo cachea y ejecuta cb() al terminar.
    function cargarInstr(cb) {
        $.ajax({
            url: "endpoints/vps/consola_instrucciones.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }
        }).done(function (res) {
            instrGrupos = (res && res.ok && res.data && res.data.grupos) || [];
            // Mapa id->item (con su categoria) para poder editar.
            instrPorId = {};
            instrGrupos.forEach(function (g) {
                (g.items || []).forEach(function (it) {
                    instrPorId[it.id] = {
                        id: it.id, categoria: g.categoria,
                        titulo: it.titulo, descripcion: it.descripcion, comando: it.comando
                    };
                });
            });
            if (cb) { cb(true); }
        }).fail(function () {
            if (cb) { cb(false); }
        });
    }

    // Pinta el catalogo agrupado por categoria, con acciones por instruccion.
    function renderInstr() {
        $instrLista.empty();
        if (!instrGrupos || !instrGrupos.length) {
            $instrLista.html('<div class="consola__vacio">Aún no hay instrucciones. Crea la primera con «Nueva instrucción».</div>');
            return;
        }
        instrGrupos.forEach(function (g) {
            $instrLista.append($('<h3 class="consola__instr-cat">').text(g.categoria));
            (g.items || []).forEach(function (it) {
                var $acc = $('<span class="consola__instr-acc">').append(
                    $('<button type="button" class="btn btn-icon btn-sm" data-accion="ejecutar" title="Ejecutar en la terminal"><i class="bi bi-play-fill" aria-hidden="true"></i></button>').attr("data-cmd", it.comando),
                    $('<button type="button" class="btn btn-icon btn-sm" data-accion="editar" title="Editar"><i class="bi bi-pencil" aria-hidden="true"></i></button>').attr("data-id", it.id),
                    $('<button type="button" class="btn btn-icon btn-sm" data-accion="eliminar" title="Eliminar"><i class="bi bi-trash" aria-hidden="true"></i></button>').attr("data-id", it.id)
                );
                var $li = $('<div class="consola__instr-item">').append(
                    $('<span class="consola__instr-info">').append(
                        $('<b>').text(it.titulo),
                        it.descripcion ? $('<small>').text(it.descripcion) : null,
                        $('<code>').text(it.comando)
                    ),
                    $acc
                );
                $instrLista.append($li);
            });
        });
    }

    // Abre el modal en modo LISTA y (re)carga el catalogo.
    function abrirModalInstr() {
        modoFormInstr(false);
        modalInstr().show();
        $instrLista.html('<div class="consola__cargando">Cargando…</div>');
        cargarInstr(function (ok) {
            if (ok) { renderInstr(); }
            else { $instrLista.html('<div class="consola__vacio">No se pudo cargar el catálogo.</div>'); }
        });
    }

    // Ejecuta la instruccion: la envia al shell como si se tecleara + Enter.
    // El server Node la registra en el historial igual que cualquier comando.
    function ejecutarInstr(cmd) {
        cmd = (cmd || "").trim();
        if (!cmd) { return; }
        if (!socket || !conectado) { AX.toast && AX.toast("Conéctate a la consola para ejecutar."); return; }
        socket.emit("input", cmd + "\r");
        recordarComando(cmd);          // que aparezca ya en las sugerencias
        modalInstr().hide();
        if (term) { term.focus(); }
        if (AX && AX.toast) { AX.toast("Ejecutando: " + cmd); }
    }

    // Muestra el formulario. item = objeto para editar, o null para alta.
    function mostrarFormInstr(item) {
        instrEditId = item ? item.id : "";
        $("#instrFormError").addClass("d-none").text("");
        // Datalist con las categorias ya existentes (para reutilizarlas).
        var $dl = $("#instrCategorias").empty();
        (instrGrupos || []).forEach(function (g) { $dl.append($("<option>").val(g.categoria)); });
        $("#instrCategoria").val(item ? item.categoria : "");
        $("#instrTitulo").val(item ? item.titulo : "");
        $("#instrDescripcion").val(item ? (item.descripcion || "") : "");
        $("#instrComando").val(item ? item.comando : "");
        modoFormInstr(true);
        $("#instrCategoria").trigger("focus");
    }

    // Guarda el formulario (alta o edicion) contra el backend.
    function guardarInstr() {
        var payload = {
            categoria: $("#instrCategoria").val().trim(),
            titulo: $("#instrTitulo").val().trim(),
            descripcion: $("#instrDescripcion").val().trim(),
            comando: $("#instrComando").val().trim()
        };
        if (instrEditId) { payload.id = instrEditId; }
        if (!payload.categoria || !payload.titulo || !payload.comando) {
            $("#instrFormError").text("Categoría, título y comando son obligatorios.").removeClass("d-none");
            return;
        }
        var $btn = $("#instrGuardar").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_instrucciones.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(payload)
        }).done(function (res) {
            if (res && res.ok) {
                if (AX && AX.toast) { AX.toast(instrEditId ? "Instrucción actualizada." : "Instrucción creada."); }
                cargarInstr(function () { renderInstr(); modoFormInstr(false); });
            } else {
                $("#instrFormError").text((res && res.mensaje) || "No se pudo guardar.").removeClass("d-none");
            }
            $btn.prop("disabled", false);
        }).fail(function (xhr) {
            $("#instrFormError").text((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar.").removeClass("d-none");
            $btn.prop("disabled", false);
        });
    }

    // Elimina una instruccion (con confirmacion) y refresca la lista.
    function eliminarInstr(id) {
        var it = instrPorId[id];
        AX.confirmar({
            titulo: "Eliminar instrucción",
            texto: "¿Eliminar «" + ((it && it.titulo) || "esta instrucción") + "» del catálogo?",
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            $.ajax({
                url: "endpoints/vps/consola_instrucciones.php",
                method: "DELETE", contentType: "application/json", dataType: "json",
                xhrFields: { withCredentials: true },
                data: JSON.stringify({ id: id })
            }).done(function (res) {
                if (res && res.ok) {
                    if (AX && AX.toast) { AX.toast("Instrucción eliminada."); }
                    cargarInstr(function () { renderInstr(); });
                } else {
                    AX.error((res && res.mensaje) || "No se pudo eliminar.");
                }
            }).fail(function (xhr) {
                AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo eliminar.");
            });
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
        // Se pide la palabra maestra ANTES de conectar. Se usa para descifrar
        // la credencial y se descarta al instante; nunca se guarda en el sistema.
        AX.pedirClave({
            titulo: "Palabra maestra",
            texto: "Necesaria para descifrar las credenciales SSH de este servidor.",
            confirmar: "Conectar"
        }).then(function (clave) {
            if (!clave) { return; }        // cancelado: no se conecta
            solicitarTokenYConectar(id, clave);
        });
    }

    function solicitarTokenYConectar(id, claveMaestra) {
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
            abrirSocket(res.data.token, claveMaestra);
        }).fail(function (xhr) {
            setEstado("Error", "error");
            $btnCon.prop("disabled", false);
            AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo obtener el token.");
        });
    }

    function abrirSocket(token, claveMaestra) {
        initTerminal();
        if (term) { term.reset(); }
        lineaActual = ""; enEscape = false; ocultarSug();
        socketVpsId = vpsId();
        setEstado("Conectando…", "wait");

        socket = io(wsUrl() + "/consola", {
            // La palabra maestra viaja en el handshake; Node la usa para pedir
            // la credencial descifrada a PHP y la descarta. No se guarda aqui.
            auth: {
                token: token,
                clave_maestra: claveMaestra,
                cols: term ? term.cols : 80,
                rows: term ? term.rows : 24
            },
            reconnection: false,
            transports: ["websocket", "polling"]
        });
        claveMaestra = null; // suelta la referencia local

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
    // Construye el <li> de una sesion. Lo usan cargarHistorial (carga inicial) y
    // los eventos de socket (apertura/cierre en vivo). El icono refleja el estado:
    // ● activa (verde), ⚠ error, ○ cerrada.
    function filaSesion(s) {
        var ico = s.estado === "activa" ? "●" : (s.estado === "error" ? "⚠" : "○");
        return $('<li class="consola__sesion">').attr("data-id", s.id).append(
            $('<span class="consola__sesion-ico">').attr("data-estado", s.estado).text(ico),
            $('<span class="consola__sesion-info">').append(
                $("<b>").text(s.usuario || "—"),
                $("<small>").text(AX.formatearFecha(s.inicio) + " · " + (s.total_comandos || 0) + " cmd")
            )
        );
    }

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
            arr.forEach(function (s) { $sesiones.append(filaSesion(s)); });
        });
    }

    // --- Tiempo real del Historial (Socket.IO) -----------------------
    //  El panel de sesiones se actualiza en vivo: cuando ALGUIEN (uno mismo u
    //  otro operador) abre una consola SSH a este VPS aparece como activa (punto
    //  verde y quien esta conectado), y al terminar cambia a cerrada/error. La
    //  apertura/cierre real la persiste PHP (consola_sesion_abrir/cerrar.php,
    //  llamados por el server Node); esos endpoints emiten el evento a la sala
    //  'vps' y aqui solo se PINTA. Se usa el namespace por defecto del server
    //  unico (los listados), independiente del socket SSH del namespace /consola.
    var socketHist = null;

    function sesionPorId(id) {
        return $sesiones.find(".consola__sesion").filter(function () {
            return $(this).attr("data-id") === id;
        });
    }

    // Apertura: inserta la sesion al principio (el historial va por inicio DESC).
    // Si ya estaba (p. ej. el propio actor tras un refresco), la reemplaza.
    function sesionAbierta(s) {
        if (!s || !s.id || !s.vps_id || s.vps_id !== vpsId()) { return; }
        var $existente = sesionPorId(s.id);
        if ($existente.length) { $existente.replaceWith(filaSesion(s)); return; }
        $sesiones.find(".consola__vacio").remove(); // quita el "Sin sesiones."
        $sesiones.prepend(filaSesion(s));
    }

    // Cierre: cambia el punto de la sesion (verde -> cerrada/error) en sitio,
    // conservando la seleccion si el usuario tenia esa sesion abierta.
    function sesionCerrada(s) {
        if (!s || !s.id || !s.vps_id || s.vps_id !== vpsId()) { return; }
        var $li = sesionPorId(s.id);
        if (!$li.length) { return; }
        var $nueva = filaSesion(s);
        if ($li.hasClass("is-active")) { $nueva.addClass("is-active"); }
        $li.replaceWith($nueva);
    }

    function conectarSocketHistorial() {
        if (socketHist || typeof io === "undefined") { return; }
        var base = wsUrl();
        if (!base) { return; }
        socketHist = io(base, { transports: ["websocket", "polling"], withCredentials: true });
        socketHist.on("connect", function () { socketHist.emit("unirse", "vps"); });
        socketHist.on("sesion_vps:abierta", sesionAbierta);
        socketHist.on("sesion_vps:cerrada", sesionCerrada);
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
        $btnInstr = $("#consolaInstrucciones");
        $instrLista = $("#instrLista");

        $btnCon.on("click", conectar);
        $btnDes.on("click", desconectar);
        $btnInstr.on("click", abrirModalInstr);
        $("#consolaRefrescar").on("click", cargarHistorial);

        // Acciones sobre cada instruccion del modal (ejecutar / editar / eliminar).
        $instrLista.on("click", "[data-accion]", function () {
            var accion = $(this).attr("data-accion");
            if (accion === "ejecutar") { ejecutarInstr($(this).attr("data-cmd")); }
            else if (accion === "editar") { mostrarFormInstr(instrPorId[$(this).attr("data-id")]); }
            else if (accion === "eliminar") { eliminarInstr($(this).attr("data-id")); }
        });
        // Formulario del catalogo (crear / editar).
        $("#instrNueva").on("click", function () { mostrarFormInstr(null); });
        $("#instrCancelar").on("click", function () { modoFormInstr(false); });
        $("#instrGuardar").on("click", guardarInstr);
        $("#instrForm").on("submit", function (e) { e.preventDefault(); guardarInstr(); });

        // Clic en una sugerencia = completar ese comando.
        $sugLista.on("click", ".consola__sug-item", function () {
            aceptarSugerencia(parseInt($(this).attr("data-idx"), 10));
        });

        // Modal "Agregar credencial"
        $("#consolaAddCred").on("click", abrirModalCred);
        $("#acTipoAuth").on("change", toggleCamposCred);
        $("#btnGuardarAddCred").on("click", guardarCredencial);

        // Modal "Llave maestra" (configurar / cambiar la palabra del sistema)
        $("#consolaLlaveMaestra").on("click", abrirModalLlave);
        $("#btnGuardarLlave").on("click", guardarLlave);
        $("#formLlave").on("submit", function (e) { e.preventDefault(); guardarLlave(); });
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

        // Tiempo real del panel de sesiones (independiente del socket SSH): se
        // conecta una vez y escucha aperturas/cierres de consola de este VPS.
        conectarSocketHistorial();
    });
})();
