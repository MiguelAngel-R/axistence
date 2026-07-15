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
                // Si el bloque esperaba una contraseña, este Enter la confirma:
                // no se registra (es secreto) y se pasa a "verificando".
                if (!bloqueEnterClave()) {
                    recordarComando(lineaActual);
                }
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
        // El generador de bloques toma los comandos de la LISTA; en el
        // formulario esa lista no esta visible, asi que se cierra el panel.
        if (esForm) { toggleBloques(false); }
    }

    // Abre/cierra el panel "Generar bloque de codigo", que aparece pegado a
    // la derecha del modal (mismo tamaño). Ensancha el dialogo para que quepan
    // los dos recuadros. Al abrir por primera vez engancha el arrastre.
    function toggleBloques(abrir) {
        abrir = !!abrir;
        $("#bloquesPanel").prop("hidden", !abrir);
        $("#modalInstrucciones").find(".consola__instr-dialog").toggleClass("is-bloques", abrir);
        if (abrir) { iniciarBloquesDnd(); }
    }

    // --- Arrastre de comandos hacia el bloque (Sortable.js) -----------
    var bloquesDndListo = false;         // el DnD se engancha una sola vez
    var bloquesGuardadosCargados = false; // cache de la pestaña "Bloques"
    var bloquesPorId = {};               // id -> bloque (para editar)
    var bloqueEditId = "";               // id del bloque en edicion ("" = alta)

    // --- Ejecucion por pasos de un bloque (para no romper el flujo cuando un
    //     comando pide contraseña) ------------------------------------------
    var bloqueCola = [];                 // comandos pendientes del bloque en curso
    var bloqueActivo = false;            // hay un bloque ejecutandose paso a paso
    // Interaccion de contraseña (dos fases; la contraseña se "confirma" con Enter):
    var bloqueEsperaClave = false;       // hay un prompt de clave y el usuario NO ha dado Enter
    var bloqueVerificaClave = false;     // el usuario dio Enter; se espera el veredicto del shell
    var bloqueQuietTimer = null;         // temporizador de "silencio" del shell
    var BLOQUE_QUIET_MS = 800;           // ms sin salida para dar por listo el comando
    // Mismos patrones que usa el server (index.js) para detectar el prompt de
    // clave en la salida: se limpian los codigos ANSI y se busca una linea que
    // contenga password/passphrase/contraseña y TERMINE en ":".
    var RE_ANSI_CLI = /\x1b\[[0-9;?]*[a-zA-Z]|\x1b[()][A-Za-z0-9]|\x1b[=>]/g;
    var RE_PROMPT_CLAVE_CLI = /(?:password|passphrase|contraseña|verification code)\b[^\r\n]*:[ \t]*$/i;

    // Muestra/oculta el placeholder de la zona segun tenga o no comandos.
    function actualizarVacioBloques() {
        var hay = $("#bloquesZona .consola__bloque-item").length > 0;
        $("#bloquesZona .consola__vacio").toggleClass("d-none", hay);
    }

    // Construye el "chip" que representa un comando dentro del bloque. Es un
    // nodo distinto al del catalogo (sin las acciones ejecutar/editar/eliminar):
    // solo el titulo, el comando y un boton para quitarlo del bloque.
    function construirBloqueItem(titulo, cmd) {
        return $('<div class="consola__bloque-item">')
            .attr("data-cmd", cmd)
            .attr("data-titulo", titulo || "")
            .append(
                $('<span class="consola__bloque-asa" title="Arrastrar para reordenar"><i class="bi bi-grip-vertical" aria-hidden="true"></i></span>'),
                $('<span class="consola__bloque-info">').append(
                    titulo ? $("<b>").text(titulo) : null,
                    $("<code>").text(cmd)
                ),
                $('<button type="button" class="btn btn-icon btn-sm consola__bloque-quitar" title="Quitar del bloque"><i class="bi bi-x-lg" aria-hidden="true"></i></button>')
            );
    }

    // Engancha el arrastre (una sola vez): la lista del catalogo es el ORIGEN
    // (se clona: los comandos siguen existiendo alli) y la zona del bloque es
    // el DESTINO (acepta soltar y permite reordenar lo ya soltado).
    function iniciarBloquesDnd() {
        if (bloquesDndListo) { return; }
        if (typeof Sortable === "undefined") { return; }
        var listaEl = document.getElementById("instrLista");
        var zonaEl = document.getElementById("bloquesZona");
        if (!listaEl || !zonaEl) { return; }

        // ORIGEN: catalogo. pull:"clone" + put:false => los comandos no se
        // mueven ni se reordenan; se copia una instancia hacia el bloque.
        new Sortable(listaEl, {
            group: { name: "bloques", pull: "clone", put: false },
            sort: false,
            draggable: ".consola__instr-item",
            filter: ".consola__instr-acc, .consola__instr-acc *",  // no iniciar arrastre desde los botones
            preventOnFilter: false,                                // ...para que sus clics sigan funcionando
            ghostClass: "consola__instr-item--fantasma",
            dragClass: "consola__instr-item--arrastrando"
        });

        // DESTINO: zona del bloque. Acepta lo que llega del catalogo y permite
        // reordenar los chips ya soltados (pero no arrastrarlos de vuelta).
        new Sortable(zonaEl, {
            group: { name: "bloques", pull: false, put: true },
            animation: 150,
            draggable: ".consola__bloque-item",
            handle: ".consola__bloque-asa",
            ghostClass: "consola__bloque-item--fantasma",
            // Al soltar un comando del catalogo llega su CLON (un .consola__instr-item);
            // lo reemplazamos por un chip de bloque limpio con el comando.
            onAdd: function (evt) {
                var cmd = evt.item.getAttribute("data-cmd") || "";
                var titulo = evt.item.getAttribute("data-titulo") || "";
                evt.item.replaceWith(construirBloqueItem(titulo, cmd)[0]);
                actualizarVacioBloques();
            }
        });

        bloquesDndListo = true;
    }

    // Vacia el generador y lo deja en modo ALTA: nombre + comandos + textos de
    // titulo/boton (tras guardar, al cerrar el panel o al abrir el modal).
    function limpiarBloque() {
        bloqueEditId = "";
        $("#bloqueNombre").val("");
        $("#bloquesError").addClass("d-none").text("");
        $("#bloquesZona .consola__bloque-item").remove();
        actualizarVacioBloques();
        $("#bloquesTitulo").text("Generar bloque de código");
        $("#bloquesGuardarTexto").text("Guardar bloque");
    }

    // Guarda el bloque: nombre + los comandos soltados, en el orden actual.
    function guardarBloque() {
        var $err = $("#bloquesError").addClass("d-none").text("");
        var nombre = ($("#bloqueNombre").val() || "").trim();
        var comandos = [];
        $("#bloquesZona .consola__bloque-item").each(function () {
            comandos.push({
                titulo: $(this).attr("data-titulo") || "",
                comando: $(this).attr("data-cmd") || ""
            });
        });
        if (!nombre) { $err.text("Ponle un nombre al bloque.").removeClass("d-none"); return; }
        if (!comandos.length) { $err.text("Arrastra al menos un comando al bloque.").removeClass("d-none"); return; }

        var editando = !!bloqueEditId;
        var payload = { nombre: nombre, comandos: comandos };
        if (editando) { payload.id = bloqueEditId; }

        var $btn = $("#bloquesGuardar").prop("disabled", true);
        $.ajax({
            url: "endpoints/vps/consola_bloques.php",
            method: "POST", contentType: "application/json", dataType: "json",
            xhrFields: { withCredentials: true },
            data: JSON.stringify(payload)
        }).done(function (res) {
            if (res && res.ok) {
                if (AX && AX.toast) { AX.toast(editando ? "Bloque actualizado." : "Bloque guardado."); }
                limpiarBloque();
                toggleBloques(false);
                bloquesGuardadosCargados = false;   // que la pestaña "Bloques" lo muestre al abrirla
            } else {
                $err.text((res && res.mensaje) || "No se pudo guardar el bloque.").removeClass("d-none");
            }
            $btn.prop("disabled", false);
        }).fail(function (xhr) {
            $err.text((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo guardar el bloque.").removeClass("d-none");
            $btn.prop("disabled", false);
        });
    }

    // --- Bloques guardados (pestaña "Bloques de comando", solo lectura) ---
    // Carga los bloques desde el backend (perezoso: solo la primera vez o si
    // se fuerza tras guardar/crear uno nuevo).
    function cargarBloquesGuardados(forzar) {
        if (bloquesGuardadosCargados && !forzar) { return; }
        var $cont = $("#bloquesGuardados").html('<div class="consola__cargando">Cargando…</div>');
        $.ajax({
            url: "endpoints/vps/consola_bloques.php",
            method: "GET", dataType: "json",
            xhrFields: { withCredentials: true }
        }).done(function (res) {
            renderBloquesGuardados((res && res.ok && res.data && res.data.bloques) || []);
            bloquesGuardadosCargados = true;
        }).fail(function () {
            $cont.html('<div class="consola__vacio">No se pudieron cargar los bloques.</div>');
        });
    }

    // Pinta los bloques: por cada uno, su nombre + acciones + los comandos que
    // contiene. Cachea cada bloque en bloquesPorId para poder editarlo.
    function renderBloquesGuardados(bloques) {
        var $cont = $("#bloquesGuardados").empty();
        bloquesPorId = {};
        if (!bloques.length) {
            $cont.html('<div class="consola__vacio">Aún no hay bloques guardados. Crea uno con «Generar bloque de código».</div>');
            return;
        }
        bloques.forEach(function (b) {
            bloquesPorId[b.id] = b;
            var comandos = b.comandos || [];
            var $cmds = $('<div class="consola__bloque-cmds">');
            comandos.forEach(function (c, i) {
                $cmds.append(
                    $('<div class="consola__bloque-cmd">').append(
                        $('<span class="consola__bloque-num">').text((i + 1) + "."),
                        $('<span class="consola__bloque-cmd-info">').append(
                            c.titulo ? $("<small>").text(c.titulo) : null,
                            $("<code>").text(c.comando)
                        )
                    )
                );
            });
            var $acc = $('<span class="consola__instr-acc">').append(
                $('<button type="button" class="btn btn-icon btn-sm" data-accion="ejecutar-bloque" title="Ejecutar el bloque en la terminal"><i class="bi bi-play-fill" aria-hidden="true"></i></button>').attr("data-id", b.id),
                $('<button type="button" class="btn btn-icon btn-sm" data-accion="editar-bloque" title="Editar bloque"><i class="bi bi-pencil" aria-hidden="true"></i></button>').attr("data-id", b.id),
                $('<button type="button" class="btn btn-icon btn-sm" data-accion="eliminar-bloque" title="Eliminar bloque"><i class="bi bi-trash" aria-hidden="true"></i></button>').attr("data-id", b.id)
            );
            $cont.append(
                $('<div class="consola__bloque-card">').append(
                    $('<div class="consola__bloque-card-head">').append(
                        $("<b>").text(b.nombre),
                        $('<span class="consola__bloque-card-right">').append(
                            $('<span class="consola__bloque-card-count">').text(comandos.length + " comando(s)"),
                            $acc
                        )
                    ),
                    b.descripcion ? $('<small class="consola__bloque-card-desc">').text(b.descripcion) : null,
                    $cmds
                )
            );
        });
    }

    // Abre el generador precargado con el bloque para editarlo: nombre + sus
    // comandos como chips. Desde ahi se pueden quitar comandos y arrastrar mas.
    function editarBloque(id) {
        var b = bloquesPorId[id];
        if (!b) { return; }
        bloqueEditId = id;
        // El origen del arrastre (catalogo) vive en la pestaña "Comandos": la activamos.
        if (typeof bootstrap !== "undefined" && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(document.getElementById("instrtab-comandos")).show();
        }
        $("#bloquesError").addClass("d-none").text("");
        $("#bloqueNombre").val(b.nombre || "");
        // Reemplaza los chips por los comandos del bloque, en orden.
        $("#bloquesZona .consola__bloque-item").remove();
        (b.comandos || []).forEach(function (c) {
            $("#bloquesZona").append(construirBloqueItem(c.titulo || "", c.comando || ""));
        });
        actualizarVacioBloques();
        // UI en modo edicion (titulo + boton).
        $("#bloquesTitulo").text("Editar bloque");
        $("#bloquesGuardarTexto").text("Guardar cambios");
        toggleBloques(true);
    }

    // Elimina un bloque (con confirmacion) y refresca la pestaña.
    function eliminarBloque(id) {
        var b = bloquesPorId[id];
        AX.confirmar({
            titulo: "Eliminar bloque",
            texto: "¿Eliminar «" + ((b && b.nombre) || "este bloque") + "»? Se borrarán también sus comandos.",
            confirmar: "Eliminar", peligro: true
        }).then(function (r) {
            if (!r.isConfirmed) { return; }
            $.ajax({
                url: "endpoints/vps/consola_bloques.php",
                method: "DELETE", contentType: "application/json", dataType: "json",
                xhrFields: { withCredentials: true },
                data: JSON.stringify({ id: id })
            }).done(function (res) {
                if (res && res.ok) {
                    if (AX && AX.toast) { AX.toast("Bloque eliminado."); }
                    // Si se estaba editando justo ese bloque, descarta el generador.
                    if (bloqueEditId === id) { toggleBloques(false); limpiarBloque(); }
                    cargarBloquesGuardados(true);
                } else {
                    AX.error((res && res.mensaje) || "No se pudo eliminar.");
                }
            }).fail(function (xhr) {
                AX.error((xhr.responseJSON && xhr.responseJSON.mensaje) || "No se pudo eliminar.");
            });
        });
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
                var $li = $('<div class="consola__instr-item">')
                    .attr("data-cmd", it.comando)
                    .attr("data-titulo", it.titulo)
                    .append(
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
        limpiarBloque();        // el generador arranca vacio en cada apertura del modal
        toggleBloques(false);   // ...y cerrado
        // Arranca siempre en la pestaña "Comandos".
        if (typeof bootstrap !== "undefined" && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(document.getElementById("instrtab-comandos")).show();
        }
        bloquesGuardadosCargados = false;   // recargar bloques la proxima vez que se abra su pestaña
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

    // Ejecuta un bloque completo enviando sus comandos EN SECUENCIA, pero uno a
    // uno: no manda el siguiente hasta que el shell queda en silencio. Si algun
    // comando pide contraseña (prompt detectado en la salida), PAUSA hasta que
    // el operador la teclee (al continuar el shell produce salida y se reanuda),
    // asi la contraseña no se "come" el siguiente comando del bloque.
    function ejecutarBloque(id) {
        var b = bloquesPorId[id];
        if (!b) { return; }
        var comandos = (b.comandos || [])
            .map(function (c) { return ((c && c.comando) || "").trim(); })
            .filter(function (c) { return c !== ""; });
        if (!comandos.length) { AX.toast && AX.toast("El bloque no tiene comandos."); return; }
        if (!socket || !conectado) { AX.toast && AX.toast("Conéctate a la consola para ejecutar."); return; }
        bloqueCola = comandos.slice();
        bloqueActivo = true;
        bloqueEsperaClave = false;
        bloqueVerificaClave = false;
        modalInstr().hide();
        if (term) { term.focus(); }
        if (AX && AX.toast) { AX.toast("Ejecutando bloque «" + (b.nombre || "") + "» (" + comandos.length + " comando(s))"); }
        enviarSiguienteComandoBloque();
    }

    // Envia el proximo comando del bloque y arma el temporizador de silencio.
    function enviarSiguienteComandoBloque() {
        clearTimeout(bloqueQuietTimer);
        if (!bloqueActivo) { return; }
        if (!bloqueCola.length) { bloqueActivo = false; return; }   // bloque terminado
        if (!socket || !conectado) { cancelarBloque(); return; }    // se cayo la consola
        var cmd = bloqueCola.shift();
        socket.emit("input", cmd + "\r");
        recordarComando(cmd);
        armarSilencioBloque();
    }

    // (Re)arma el temporizador: cuando el shell lleva BLOQUE_QUIET_MS sin emitir
    // salida y NO hay una interaccion de contraseña en curso, envia el siguiente.
    function armarSilencioBloque() {
        clearTimeout(bloqueQuietTimer);
        bloqueQuietTimer = setTimeout(function () {
            if (!bloqueActivo) { return; }
            if (bloqueEsperaClave || bloqueVerificaClave) { return; } // en plena clave: esperar
            enviarSiguienteComandoBloque();
        }, BLOQUE_QUIET_MS);
    }

    // Se llama con cada trozo de salida del shell mientras corre un bloque.
    // Maneja la interaccion de contraseña SIN depender del silencio (la clave se
    // "confirma" con Enter, no cuando el usuario deja de teclear):
    //   - Si la salida es un prompt de clave -> pausa (espera Enter del usuario).
    //   - Si aun se espera la clave (usuario no ha dado Enter), ignora el eco
    //     (p.ej. asteriscos) y sigue esperando.
    //   - Si el usuario ya dio Enter y esto NO es otro prompt -> clave aceptada,
    //     el comando corre; se retoma el ritmo por silencio.
    function procesarSalidaBloque(texto) {
        if (!bloqueActivo) { return; }
        var esPromptClave = RE_PROMPT_CLAVE_CLI.test(String(texto).replace(RE_ANSI_CLI, ""));
        if (esPromptClave) {
            bloqueEsperaClave = true;      // pide clave: pausa hasta el Enter del usuario
            bloqueVerificaClave = false;
            clearTimeout(bloqueQuietTimer);
            return;
        }
        if (bloqueEsperaClave) {
            return;                        // eco de la clave aun sin enviar: seguir esperando
        }
        if (bloqueVerificaClave) {
            bloqueVerificaClave = false;   // Enter dado y no re-pregunto: clave aceptada
        }
        armarSilencioBloque();
    }

    // Enter del usuario mientras el bloque espera una contraseña: la confirma.
    // Pasa a "verificando" hasta ver el veredicto del shell (si vuelve a pedir
    // clave, fue incorrecta y se sigue esperando). Devuelve true si consumio el
    // Enter (para no registrarlo como comando: es secreto).
    function bloqueEnterClave() {
        if (bloqueActivo && bloqueEsperaClave) {
            bloqueEsperaClave = false;
            bloqueVerificaClave = true;
            clearTimeout(bloqueQuietTimer);
            return true;
        }
        return false;
    }

    // Cancela la ejecucion por pasos (al desconectar / cerrar la sesion).
    function cancelarBloque() {
        clearTimeout(bloqueQuietTimer);
        bloqueCola = [];
        bloqueActivo = false;
        bloqueEsperaClave = false;
        bloqueVerificaClave = false;
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
        socket.on("output", function (d) { if (term) { term.write(d); } procesarSalidaBloque(d); });
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
        cancelarBloque();   // corta cualquier bloque en ejecucion por pasos
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
        // Generador de bloques: "Generar bloque" arranca un bloque NUEVO (limpio);
        // cerrar/cancelar descarta y resetea el estado (por si venia de editar).
        $("#instrGenerarBloque").on("click", function () { limpiarBloque(); toggleBloques(true); });
        $("#bloquesCerrar, #bloquesCancelar").on("click", function () { toggleBloques(false); limpiarBloque(); });
        $("#bloquesGuardar").on("click", guardarBloque);
        // Acciones sobre un bloque guardado: ejecutar / editar / eliminar.
        $("#bloquesGuardados").on("click", "[data-accion]", function () {
            var accion = $(this).attr("data-accion");
            var id = $(this).attr("data-id");
            if (accion === "ejecutar-bloque") { ejecutarBloque(id); }
            else if (accion === "editar-bloque") { editarBloque(id); }
            else if (accion === "eliminar-bloque") { eliminarBloque(id); }
        });
        // Pestaña "Bloques de comando": cierra el generador (su lista de
        // origen vive en la otra pestaña) y carga perezosa los bloques.
        $("#instrtab-bloques").on("shown.bs.tab", function () {
            toggleBloques(false);
            cargarBloquesGuardados(false);
        });
        // Quitar un comando ya soltado en el bloque.
        $("#bloquesZona").on("click", ".consola__bloque-quitar", function () {
            $(this).closest(".consola__bloque-item").remove();
            actualizarVacioBloques();
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
