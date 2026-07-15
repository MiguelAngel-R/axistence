/* =====================================================================
   AXISTENCE - general.js
   Funciones reutilizables para el manejo del DOM en todos los modulos:
   paginador (footer), escape de HTML, formato de fecha y avisos (toasts).
   Expone el espacio de nombres global  AX.
   ===================================================================== */

window.AX = window.AX || {};

(function (AX, $) {
    "use strict";

    /* --- SweetAlert2 sobre modales de Bootstrap -------------------- */
    // Cuando un diálogo de Swal (p. ej. AX.pedirClave) se abre ENCIMA de un
    // modal de Bootstrap, el "focus trap" del modal intenta devolver el foco
    // al modal y bloquea el tecleo en el input de Swal. Se deja pasar el foco
    // si el destino está dentro del contenedor de Swal. Fase de captura +
    // stopImmediatePropagation para adelantarse al handler de Bootstrap.
    document.addEventListener("focusin", function (e) {
        if (e.target && e.target.closest && e.target.closest(".swal2-container")) {
            e.stopImmediatePropagation();
        }
    }, true);

    /* --- Utilidades ------------------------------------------------ */

    // Escapa texto para insertarlo de forma segura en HTML (evita XSS).
    AX.escaparHtml = function (valor) {
        if (valor === null || valor === undefined) { return ""; }
        return String(valor).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    };

    // Lee los datos embebidos de una fila de tabla, sin consultar la BD.
    // Cada fila se pinta con  data-registro='{"...":"..."}'  (JSON).
    // Recibe cualquier elemento dentro de la fila (o la propia fila) y
    // devuelve el objeto, o null si no lo encuentra.
    AX.datosFila = function (elemento) {
        var $fila = $(elemento).closest("[data-registro]");
        if (!$fila.length) { return null; }
        try {
            return JSON.parse($fila.attr("data-registro"));
        } catch (e) {
            return null;
        }
    };

    // Formatea una fecha ISO / timestamp de PostgreSQL a dd/mm/aaaa.
    AX.formatearFecha = function (iso) {
        if (!iso) { return ""; }
        var d = new Date(String(iso).replace(" ", "T"));
        if (isNaN(d.getTime())) { return AX.escaparHtml(iso); }
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return p(d.getDate()) + "/" + p(d.getMonth() + 1) + "/" + d.getFullYear();
    };

    // Igual que formatearFecha pero incluye la hora: dd/mm/aaaa HH:MM.
    AX.formatearFechaHora = function (iso) {
        if (!iso) { return ""; }
        var d = new Date(String(iso).replace(" ", "T"));
        if (isNaN(d.getTime())) { return AX.escaparHtml(iso); }
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return p(d.getDate()) + "/" + p(d.getMonth() + 1) + "/" + d.getFullYear() +
               " " + p(d.getHours()) + ":" + p(d.getMinutes());
    };

    /* --- Alertas (SweetAlert2) ------------------------------------- */
    // Aviso de proceso cumplido. Devuelve la promesa de Swal.
    AX.exito = function (mensaje, titulo) {
        if (typeof Swal === "undefined") { return AX.toast(mensaje, "exito"); }
        return Swal.fire({
            icon: "success",
            title: titulo || "Listo",
            text: mensaje,
            confirmButtonText: "Aceptar"
        });
    };

    // Aviso de error.
    AX.error = function (mensaje, titulo) {
        if (typeof Swal === "undefined") { return AX.toast(mensaje, "error"); }
        return Swal.fire({
            icon: "error",
            title: titulo || "Error",
            text: mensaje,
            confirmButtonText: "Aceptar"
        });
    };

    // Confirmacion (para acciones sensibles, p. ej. eliminar).
    // opts: { titulo, texto, icon, confirmar, cancelar, peligro }
    // Devuelve la promesa de Swal; usar .then(res => res.isConfirmed).
    AX.confirmar = function (opts) {
        opts = opts || {};
        return Swal.fire({
            icon: opts.icon || "warning",
            title: opts.titulo || "¿Confirmar?",
            text: opts.texto || "",
            showCancelButton: true,
            confirmButtonText: opts.confirmar || "Confirmar",
            cancelButtonText: opts.cancelar || "Cancelar",
            reverseButtons: true,
            focusCancel: true,
            customClass: opts.peligro ? { confirmButton: "swal2-confirm--peligro" } : {}
        });
    };

    // Pide una clave/palabra por teclado (campo password). Devuelve una
    // promesa que resuelve con el texto tecleado, o null si se cancela.
    // La palabra NO se guarda: solo se resuelve para usarla al instante.
    // opts: { titulo, texto, placeholder, confirmar, cancelar, minimo }
    AX.pedirClave = function (opts) {
        opts = opts || {};
        var minimo = opts.minimo || 0;
        return Swal.fire({
            icon: opts.icon || "question",
            title: opts.titulo || "Palabra maestra",
            text: opts.texto || "",
            input: "password",
            inputPlaceholder: opts.placeholder || "Escribe la palabra maestra",
            inputAttributes: { autocapitalize: "off", autocorrect: "off", autocomplete: "off" },
            showCancelButton: true,
            confirmButtonText: opts.confirmar || "Continuar",
            cancelButtonText: opts.cancelar || "Cancelar",
            reverseButtons: true,
            inputValidator: function (valor) {
                if (!valor) { return "Escribe la palabra maestra"; }
                if (minimo && valor.length < minimo) {
                    return "Debe tener al menos " + minimo + " caracteres";
                }
                return undefined;
            }
        }).then(function (res) {
            return res.isConfirmed ? res.value : null;
        });
    };

    /* --- Avisos (toasts) ------------------------------------------- */
    // tipo: 'info' | 'exito' | 'error'
    AX.toast = function (mensaje, tipo) {
        tipo = tipo || "info";
        var $cont = $("#axToasts");
        if (!$cont.length) {
            $cont = $('<div id="axToasts" class="ax-toasts"></div>').appendTo("body");
        }
        var $t = $('<div class="ax-toast"></div>').addClass("ax-toast--" + tipo).text(mensaje);
        $cont.append($t);
        setTimeout(function () {
            $t.addClass("is-out");
            setTimeout(function () { $t.remove(); }, 250);
        }, 2600);
    };

    /* --- Footer dinamico ------------------------------------------- */
    AX.limpiarFooter = function () {
        $("#footerInfo, #footerActions, #footerPager").empty();
        $("#appFooter").removeClass("is-visible");
    };

    // Calcula la ventana de paginas a mostrar: 1 ... (act-2..act+2) ... N
    AX._ventanaPaginas = function (actual, total) {
        var delta = 2, rango = [], resultado = [], anterior;
        for (var i = 1; i <= total; i++) {
            if (i === 1 || i === total || (i >= actual - delta && i <= actual + delta)) {
                rango.push(i);
            }
        }
        rango.forEach(function (i) {
            if (anterior) {
                if (i - anterior === 2) { resultado.push(anterior + 1); }
                else if (i - anterior > 2) { resultado.push("..."); }
            }
            resultado.push(i);
            anterior = i;
        });
        return resultado;
    };

    // Coloca botones de accion en el footer (reemplaza info/paginador).
    // botones: [{ texto, id, clase, icono }]
    AX.footerBotones = function (botones) {
        AX.limpiarFooter();
        var $cont = $("#footerActions");
        (botones || []).forEach(function (b) {
            var $b = $('<button type="button" class="btn btn-sm"></button>')
                .addClass(b.clase || "btn-primary");
            if (b.id) { $b.attr("id", b.id); }
            if (b.icono) { $b.append('<i class="bi ' + b.icono + '"></i> '); }
            $b.append(document.createTextNode(b.texto));
            $cont.append($b);
        });
        $("#appFooter").addClass("is-visible");
    };

    // Renderiza el paginador en el footer y muestra el footer.
    // opts: { pagina, totalPaginas, total, porPagina, onCambio(pagina) }
    AX.renderPaginador = function (opts) {
        var pagina       = opts.pagina;
        var totalPaginas = opts.totalPaginas || 0;
        var total        = opts.total || 0;
        var porPagina    = opts.porPagina || 0;

        $("#footerActions").empty();
        var $info  = $("#footerInfo");
        var $pager = $("#footerPager").empty();

        // Resumen "Mostrando X-Y de N".
        if (total > 0) {
            var desde = (pagina - 1) * porPagina + 1;
            var hasta = Math.min(pagina * porPagina, total);
            $info.text("Mostrando " + desde + "–" + hasta + " de " + total);
        } else {
            $info.text("Sin resultados");
        }

        // Contenedor en columna: los botones de pagina arriba y, debajo, el
        // selector dinamico de "filas por pagina". Asi ambos controles quedan
        // alineados a la derecha del footer, uno encima del otro.
        var $wrap = $('<div class="ax-pager-wrap"></div>');

        // Controles de pagina (solo si hay mas de una).
        if (totalPaginas > 1) {
            var $pg = $('<div class="ax-pager"></div>');

            var boton = function (etiqueta, destino, cfg) {
                cfg = cfg || {};
                if (cfg.ellipsis) {
                    $pg.append('<span class="ax-pager__ellipsis">…</span>');
                    return;
                }
                var $b = $('<button type="button" class="ax-pager__btn"></button>').html(etiqueta);
                if (cfg.activo) { $b.addClass("is-active"); }
                if (cfg.disabled) { $b.prop("disabled", true); }
                if (!cfg.activo && !cfg.disabled) {
                    $b.on("click", function () { opts.onCambio(destino); });
                }
                $pg.append($b);
            };

            boton("‹", pagina - 1, { disabled: pagina <= 1 });
            AX._ventanaPaginas(pagina, totalPaginas).forEach(function (p) {
                if (p === "...") { boton("", null, { ellipsis: true }); }
                else { boton(String(p), p, { activo: p === pagina }); }
            });
            boton("›", pagina + 1, { disabled: pagina >= totalPaginas });

            $wrap.append($pg);
        }

        // Selector dinamico de filas por pagina (control reutilizable). Solo se
        // renderiza si el modulo entrega el callback onPorPagina; muestra las
        // opciones 30/50/100 (o las de opts.opcionesPorPagina) y refleja el
        // valor vigente. Al cambiar, avisa al modulo para recargar el listado.
        if (typeof opts.onPorPagina === "function") {
            var opcionesPP = opts.opcionesPorPagina || [30, 50, 100];
            var $sel = $('<select class="form-select form-select-sm ax-perpage__select" aria-label="Filas por página"></select>');
            opcionesPP.forEach(function (n) {
                var $o = $('<option></option>').attr("value", n).text(n);
                if (n === porPagina) { $o.prop("selected", true); }
                $sel.append($o);
            });
            $sel.on("change", function () {
                opts.onPorPagina(parseInt(this.value, 10) || opcionesPP[0]);
            });
            var $pp = $('<label class="ax-perpage"><span class="ax-perpage__label">Filas por página</span></label>');
            $pp.append($sel);
            $wrap.append($pp);
        }

        $pager.append($wrap);
        $("#appFooter").addClass("is-visible");
    };

    // Vincula la flecha "Volver" del detalle (cualquier elemento con el atributo
    // data-ax-volver) con la accion de retorno del modulo. Centraliza aqui el
    // callback para no repetir el cableado en cada modulo. Idempotente: re-liga
    // el manejador en cada llamada (un solo modulo se carga por pagina).
    AX.vincularVolver = function (callback) {
        $(document).off("click.axvolver").on("click.axvolver", "[data-ax-volver]", function (e) {
            e.preventDefault();
            if (typeof callback === "function") { callback(); }
        });
    };

    /* --- Filtro de tablas (reutilizable) --------------------------- */

    // Quita el resaltado (<mark class="ax-marca">) de un contenedor,
    // devolviendo los nodos de texto a su estado original.
    function limpiarResaltado(contenedor) {
        var marcas = contenedor.querySelectorAll("mark.ax-marca");
        for (var i = 0; i < marcas.length; i++) {
            var m = marcas[i];
            var padre = m.parentNode;
            padre.replaceChild(document.createTextNode(m.textContent), m);
            padre.normalize(); // fusiona nodos de texto adyacentes
        }
    }

    // Resalta (envuelve en <mark class="ax-marca">) las coincidencias del
    // termino dentro de un elemento, recorriendo solo sus nodos de TEXTO
    // (no rompe etiquetas internas: badges, enlaces, etc.) y sin riesgo de
    // XSS (usa textContent / createTextNode). 'termino' viene en minusculas.
    function resaltarCoincidencias(elemento, termino) {
        if (!termino) { return; }
        var nodos = [];
        var walker = document.createTreeWalker(elemento, NodeFilter.SHOW_TEXT, null, false);
        var nodo;
        while ((nodo = walker.nextNode())) { nodos.push(nodo); }

        nodos.forEach(function (n) {
            var texto = n.nodeValue;
            var lower = texto.toLowerCase();
            var idx = lower.indexOf(termino);
            if (idx === -1) { return; }

            var frag = document.createDocumentFragment();
            var pos = 0;
            while (idx !== -1) {
                if (idx > pos) {
                    frag.appendChild(document.createTextNode(texto.slice(pos, idx)));
                }
                var marca = document.createElement("mark");
                marca.className = "ax-marca";
                marca.textContent = texto.slice(idx, idx + termino.length);
                frag.appendChild(marca);
                pos = idx + termino.length;
                idx = lower.indexOf(termino, pos);
            }
            if (pos < texto.length) {
                frag.appendChild(document.createTextNode(texto.slice(pos)));
            }
            n.parentNode.replaceChild(frag, n);
        });
    }

    // Filtra en el cliente las filas de un <tbody> por texto y/o rango de
    // fechas, mostrando/ocultando cada <tr>. Ademas RESALTA en las filas
    // visibles el texto que coincide con la busqueda. Reutilizable en
    // cualquier tabla del sistema (detalle de producto, listados, etc.).
    //   $tbody     : el cuerpo de la tabla (jQuery o selector).
    //   criterios  : { texto, desde, hasta }
    //                - texto: coincidencia (insensible) en el texto de la fila.
    //                - desde/hasta: rango 'aaaa-mm-dd'; se compara contra el
    //                  atributo data-fecha de cada <tr> (si no lo tiene, la
    //                  fila no se filtra por fecha).
    // Devuelve la cantidad de filas visibles. Si no hay coincidencias,
    // inserta una fila "Sin coincidencias.".
    AX.filtrarTabla = function ($tbody, criterios) {
        $tbody = $tbody && $tbody.jquery ? $tbody : $($tbody);
        criterios = criterios || {};
        var texto = (criterios.texto || "").trim().toLowerCase();
        var desde = criterios.desde || "";
        var hasta = criterios.hasta || "";

        // Se limpia siempre el resaltado previo (cambia en cada tecla).
        if ($tbody.length) { limpiarResaltado($tbody[0]); }

        // Filas de datos (excluye el placeholder de "sin coincidencias").
        var $filas = $tbody.children("tr").not(".fila-sin-coincidencias");

        // Si la tabla ya venia vacia (placeholder "Sin registros"), no se filtra.
        if ($filas.filter(":has(td.tabla-vacia)").length) {
            $tbody.children("tr.fila-sin-coincidencias").remove();
            return 0;
        }

        var visibles = 0;
        $filas.each(function () {
            var $tr = $(this);
            var okTexto = !texto || $tr.text().toLowerCase().indexOf(texto) !== -1;
            var okFecha = true;
            if (desde || hasta) {
                var f = ($tr.attr("data-fecha") || "").substring(0, 10);
                if (f) {
                    okFecha = (!desde || f >= desde) && (!hasta || f <= hasta);
                }
            }
            var mostrar = okTexto && okFecha;
            $tr.toggleClass("d-none", !mostrar);
            if (mostrar) {
                visibles++;
                if (texto) { resaltarCoincidencias(this, texto); }
            }
        });

        var $sin = $tbody.children("tr.fila-sin-coincidencias");
        if ($filas.length && visibles === 0) {
            if (!$sin.length) {
                var cols = $tbody.closest("table").find("thead th").length || 1;
                $tbody.append('<tr class="fila-sin-coincidencias"><td colspan="' + cols +
                              '" class="tabla-vacia">Sin coincidencias.</td></tr>');
            }
        } else {
            $sin.remove();
        }
        return visibles;
    };

    /* --- Dropdown de rango de fechas (reutilizable) ---------------- */
    // Inicializa un dropdown con dos campos de fecha (desde/hasta) y los
    // botones Limpiar y Aplicar. Al Aplicar se cierra; al Limpiar solo se
    // vacian los campos (no se cierra). Cierra tambien al hacer clic fuera.
    // Estructura esperada del contenedor (data-rol):
    //   toggle, panel, desde, hasta, limpiar, aplicar
    // opciones: { onAplicar(desde, hasta), onLimpiar() }
    // Devuelve { abrir, cerrar, valores(), limpiar() }.
    AX.dropdownFechas = function (contenedor, opciones) {
        var $cont    = contenedor && contenedor.jquery ? contenedor : $(contenedor);
        opciones     = opciones || {};
        var $toggle  = $cont.find("[data-rol='toggle']");
        var $panel   = $cont.find("[data-rol='panel']");
        var $desde   = $cont.find("[data-rol='desde']");
        var $hasta   = $cont.find("[data-rol='hasta']");
        var $limpiar = $cont.find("[data-rol='limpiar']");
        var $aplicar = $cont.find("[data-rol='aplicar']");

        function abierto() { return !$panel.hasClass("d-none"); }
        function abrir()   { $panel.removeClass("d-none"); $cont.addClass("is-open"); }
        function cerrar()  { $panel.addClass("d-none"); $cont.removeClass("is-open"); }

        $toggle.on("click", function (e) {
            e.stopPropagation();
            abierto() ? cerrar() : abrir();
        });
        // Clics dentro del panel no cierran el dropdown.
        $panel.on("click", function (e) { e.stopPropagation(); });
        // Clic fuera: cerrar.
        $(document).on("click", function () { if (abierto()) { cerrar(); } });

        $aplicar.on("click", function () {
            if (opciones.onAplicar) { opciones.onAplicar($desde.val(), $hasta.val()); }
            cerrar(); // solo Aplicar cierra
        });
        $limpiar.on("click", function () {
            $desde.val(""); $hasta.val("");
            if (opciones.onLimpiar) { opciones.onLimpiar(); }
            else if (opciones.onAplicar) { opciones.onAplicar("", ""); }
        });

        return {
            abrir: abrir,
            cerrar: cerrar,
            valores: function () { return { desde: $desde.val(), hasta: $hasta.val() }; },
            limpiar: function () { $desde.val(""); $hasta.val(""); }
        };
    };

    /* --- Navegacion cruzada entre productos (deep linking) --------- */

    // Modulos de producto que tienen vista de DETALLE (viewProducto) y por
    // tanto pueden ser destino de un enlace cruzado. Otros modulos (clientes,
    // proveedores) no tienen detalle: sus datos se muestran como texto plano.
    AX.MODULOS_PRODUCTO = ['vps', 'dominios', 'correo', 'hosting', 'otros'];

    var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    // Construye el HTML de un enlace cruzado al detalle de otro producto.
    //   modulo : uno de AX.MODULOS_PRODUCTO (destino).
    //   id     : uuid del registro destino.
    //   texto  : etiqueta visible (dato relacionado, p. ej. la referencia).
    // Si no hay id valido o el modulo no tiene detalle, devuelve el texto
    // como texto PLANO ESCAPADO (fallback seguro, nunca rompe la vista).
    // La salida SIEMPRE va escapada (previene XSS al inyectarla como HTML).
    AX.enlaceProducto = function (modulo, id, texto) {
        var etiqueta = (texto === null || texto === undefined || texto === "") ? "—" : String(texto);
        if (!id || AX.MODULOS_PRODUCTO.indexOf(modulo) === -1) {
            return AX.escaparHtml(etiqueta);
        }
        var href = "index.php?vista=" + encodeURIComponent(modulo) + "&detalle=" + encodeURIComponent(id);
        return '<a class="ax-enlace" href="' + AX.escaparHtml(href) + '" title="Ver detalle">' +
               AX.escaparHtml(etiqueta) +
               '<i class="bi bi-box-arrow-up-right ax-enlace__icono" aria-hidden="true"></i></a>';
    };

    // Lee el parametro ?detalle= de la URL (uuid del registro a abrir tras
    // una navegacion cruzada). Devuelve el uuid validado o null. Cada modulo
    // lo consulta al cargar para auto-abrir el detalle solicitado.
    AX.detalleSolicitado = function () {
        try {
            var valor = new URLSearchParams(window.location.search).get("detalle");
            return (valor && UUID_RE.test(valor)) ? valor : null;
        } catch (e) {
            return null;
        }
    };

    // Indica si un evento de clic ocurrio sobre un enlace cruzado. Se usa en
    // los manejadores de clic de fila para NO abrir el detalle propio cuando
    // el usuario pulsa un enlace hacia otro producto (deja navegar al enlace).
    AX.esClicEnEnlace = function (evento) {
        return $(evento.target).closest("a.ax-enlace").length > 0;
    };

    /* --- Multiselect con buscador + chips (reutilizable) ----------- */
    // Da comportamiento al componente maquetado en includes/layout/multiselect.php.
    // Abre/cierra el panel, filtra la lista, y al hacer clic en una opcion la
    // inserta como "chip" debajo. Expone metodos para poblar opciones, fijar la
    // seleccion (edicion) y leer los ids seleccionados (envio del formulario).
    //
    // NOTA (por pedido): el boton de QUITAR del chip queda SIN programar; se
    // muestra pero no elimina la seleccion.
    //
    // Uso:
    //   var ms = AX.multiselect("#fvClientes");
    //   ms.opciones([{id, texto}, ...]);   // lista disponible
    //   ms.seleccion([id, ...]);           // preseleccion (o [] para limpiar)
    //   ms.valores();                      // -> [id, ...] seleccionados
    //
    // Opciones (2do parametro, opcional):
    //   { vacio: "Sin dominio" } -> agrega al inicio de la lista una opcion
    //     "sin seleccion" que, al pulsarla, limpia la seleccion. Tambien se usa
    //     como texto del estado vacio de los chips. Equivalente al "Sin X" de un
    //     <select> normal.
    AX.multiselect = function (contenedor, opts) {
        var $cont = contenedor && contenedor.jquery ? contenedor : $(contenedor);
        if (!$cont.length) { return null; }

        opts = opts || {};
        var textoVacio = opts.vacio || "";   // "" -> sin opcion "Sin X"

        var $toggle   = $cont.find("[data-rol='toggle']");
        var $ph       = $cont.find(".ax-multiselect__ph");
        var $panel    = $cont.find("[data-rol='panel']");
        var $buscar   = $cont.find("[data-rol='buscar']");
        var $opciones = $cont.find("[data-rol='opciones']");
        var $chips    = $cont.find("[data-rol='chips']");
        var phBase    = $ph.text() || "Selecciona…";

        var items = [];        // [{id, texto}] disponibles
        var elegidos = [];     // [id] seleccionados (en orden)

        function textoDe(id) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].id === id) { return items[i].texto; }
            }
            return id;
        }

        function abierto() { return !$panel.hasClass("d-none"); }
        function abrir() {
            $panel.removeClass("d-none");
            $cont.addClass("is-open");
            $buscar.val("");
            pintarOpciones();
            $buscar.trigger("focus");
        }
        function cerrar() { $panel.addClass("d-none"); $cont.removeClass("is-open"); }

        function pintarOpciones() {
            var filtro = ($buscar.val() || "").trim().toLowerCase();
            // Opcion "Sin X" (equivalente al "Sin servidor" de un <select>). Solo
            // se muestra cuando no hay filtro activo, siempre al inicio de la lista.
            var htmlVacio = "";
            if (textoVacio && !filtro) {
                var activo = elegidos.length === 0;
                htmlVacio = '<li data-rol="ninguno"' + (activo ? ' class="is-seleccionado"' : '') + '>' +
                            (activo ? '<i class="bi bi-check2" aria-hidden="true"></i> ' : '') +
                            AX.escaparHtml(textoVacio) + '</li>';
            }
            var visibles = items.filter(function (it) {
                return !filtro || String(it.texto).toLowerCase().indexOf(filtro) !== -1;
            });
            if (!visibles.length) {
                $opciones.html(htmlVacio || '<li class="ax-multiselect__vacio">Sin resultados</li>');
                return;
            }
            $opciones.html(htmlVacio + visibles.map(function (it) {
                var sel = elegidos.indexOf(it.id) !== -1;
                return '<li data-id="' + AX.escaparHtml(it.id) + '"' + (sel ? ' class="is-seleccionado"' : '') + '>' +
                       (sel ? '<i class="bi bi-check2" aria-hidden="true"></i> ' : '') +
                       AX.escaparHtml(it.texto) + '</li>';
            }).join(""));
        }

        function pintarChips() {
            if (!elegidos.length) {
                $chips.html('<li class="ax-multiselect__vacio" data-rol="vacio">' +
                            AX.escaparHtml(textoVacio || "Ninguno seleccionado") + '</li>');
            } else {
                $chips.html(elegidos.map(function (id) {
                    return '<li class="ax-multiselect__chip" data-id="' + AX.escaparHtml(id) + '">' +
                           AX.escaparHtml(textoDe(id)) +
                           ' <button type="button" class="ax-multiselect__chip-quitar" tabindex="-1" aria-label="Quitar">' +
                           '<i class="bi bi-x-lg" aria-hidden="true"></i></button></li>';
                }).join(""));
            }
            $ph.text(elegidos.length
                ? (elegidos.length + (elegidos.length > 1 ? " seleccionados" : " seleccionado"))
                : phBase);
        }

        // --- Eventos ---------------------------------------------------
        $toggle.on("click", function (e) { e.stopPropagation(); abierto() ? cerrar() : abrir(); });
        $panel.on("click", function (e) { e.stopPropagation(); });
        $(document).on("click", function () { if (abierto()) { cerrar(); } });
        $buscar.on("input", pintarOpciones);

        // Clic en la opcion "Sin X" -> limpia toda la seleccion.
        $opciones.on("click", "[data-rol='ninguno']", function () {
            elegidos = [];
            pintarChips();
            pintarOpciones();
        });
        // Clic en una opcion -> se agrega como chip (si no estaba ya).
        $opciones.on("click", "li[data-id]", function () {
            var id = $(this).attr("data-id");
            if (elegidos.indexOf(id) === -1) {
                elegidos.push(id);
                pintarChips();
                pintarOpciones();
            }
        });
        // El boton de quitar del chip NO se programa (pedido del usuario).

        return {
            opciones: function (nuevos) { items = nuevos || []; pintarOpciones(); pintarChips(); },
            seleccion: function (ids) { elegidos = (ids || []).slice(); pintarOpciones(); pintarChips(); },
            valores: function () { return elegidos.slice(); },
            limpiar: function () { elegidos = []; pintarOpciones(); pintarChips(); }
        };
    };

    /* --- Modales reutilizables (Bootstrap) ------------------------- */
    // Toda la logica generica para abrir/cerrar/limpiar/transferir datos a
    // los modales vive aqui, para no duplicarla en cada modulo.

    // Controlador de un modal. Devuelve { abrir, cerrar, el, instancia }.
    // Reutiliza (o crea) la instancia Bootstrap del contenedor .modal.
    AX.modal = function (selector) {
        var el = (selector && selector.nodeType) ? selector
                                                 : document.querySelector(selector);
        if (!el || typeof bootstrap === "undefined") { return null; }
        var instancia = bootstrap.Modal.getOrCreateInstance(el);
        return {
            el: el,
            instancia: instancia,
            abrir: function () { instancia.show(); },
            cerrar: function () { instancia.hide(); }
        };
    };

    // Limpia un formulario (reset nativo) y oculta su caja de error asociada.
    //   form     : elemento <form> o selector.
    //   cajaError: (opcional) selector/elemento de la alerta de error a ocultar.
    AX.limpiarFormulario = function (form, cajaError) {
        var f = (form && form.nodeType) ? form : document.querySelector(form);
        if (f && typeof f.reset === "function") { f.reset(); }
        if (cajaError) { $(cajaError).addClass("d-none").text(""); }
    };

    // Puebla los campos de un formulario desde un objeto de datos, emparejando
    // por atributo name. Soporta input/textarea/select, grupos de checkboxes
    // (name repetido -> el valor en datos es un array) y radios.
    AX.poblarFormulario = function (form, datos) {
        var f = (form && form.nodeType) ? form : document.querySelector(form);
        if (!f || !datos) { return; }
        var campos = f.elements;
        for (var i = 0; i < campos.length; i++) {
            var el = campos[i];
            var nombre = el.name;
            if (!nombre || !(nombre in datos)) { continue; }
            var valor = datos[nombre];
            if (el.type === "checkbox") {
                el.checked = Array.isArray(valor)
                    ? valor.indexOf(el.value) !== -1
                    : !!valor;
            } else if (el.type === "radio") {
                el.checked = (String(el.value) === String(valor));
            } else {
                el.value = (valor === null || valor === undefined) ? "" : valor;
            }
        }
    };

    // Lee los campos de un formulario a un objeto plano. Los grupos de
    // checkboxes (mismo name) se devuelven como array de valores marcados; el
    // radio marcado como valor unico. Ignora los campos deshabilitados.
    AX.leerFormulario = function (form) {
        var f = (form && form.nodeType) ? form : document.querySelector(form);
        var datos = {};
        if (!f) { return datos; }
        var campos = f.elements;
        for (var i = 0; i < campos.length; i++) {
            var el = campos[i];
            var nombre = el.name;
            if (!nombre || el.disabled) { continue; }
            if (el.type === "checkbox") {
                var grupo = f.elements[nombre];
                var esGrupo = grupo && grupo.length !== undefined && grupo.nodeType === undefined;
                if (esGrupo) {
                    if (!Array.isArray(datos[nombre])) { datos[nombre] = []; }
                    if (el.checked) { datos[nombre].push(el.value); }
                } else {
                    datos[nombre] = el.checked;
                }
            } else if (el.type === "radio") {
                if (el.checked) { datos[nombre] = el.value; }
                else if (!(nombre in datos)) { datos[nombre] = ""; }
            } else {
                datos[nombre] = el.value;
            }
        }
        return datos;
    };

    // Muestra un mensaje en la caja de error de un formulario dentro del modal.
    AX.errorFormulario = function (cajaError, mensaje) {
        $(cajaError).text(mensaje).removeClass("d-none");
    };

    // Envio asincrono estandar (Fetch API) a un endpoint POST JSON. Centraliza
    // la manipulacion de la respuesta de guardado: devuelve una promesa con
    // { ok, mensaje, data } normalizado (ok = HTTP correcto && cuerpo.ok===true).
    // Reutilizable por cualquier formulario/modal relacional del sistema.
    AX.enviarJSON = function (url, datos) {
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "include",
            body: JSON.stringify(datos)
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; }).then(function (cuerpo) {
                return {
                    ok:      resp.ok && cuerpo && cuerpo.ok === true,
                    mensaje: (cuerpo && cuerpo.mensaje) || "",
                    data:    (cuerpo && cuerpo.data) || null
                };
            });
        });
    };

    // Devuelve la clave del tab activo dentro de un contenedor de pestañas
    // Bootstrap. Convencion: los botones tienen id "tabbtn-<clave>". Sirve para
    // saber en que pestaña esta el usuario (p. ej. a que grilla refrescar).
    AX.tabActivo = function (contenedorTabs) {
        var $b = $(contenedorTabs).find(".nav-link.active").first();
        if (!$b.length) { return null; }
        return ($b.attr("id") || "").replace(/^tabbtn-/, "") || null;
    };

    // Modales ANIDADOS (uno abierto encima de otro). Bootstrap no apila bien
    // los z-index cuando hay un modal sobre otro; este init (idempotente)
    // recalcula el z-index del modal y de su backdrop segun la profundidad,
    // de modo que el modal hijo y su fondo traslucido queden por encima del
    // padre. El primer nivel usa los valores por defecto de Bootstrap.
    var modalesAnidadosListo = false;
    AX.iniciarModalesAnidados = function () {
        if (modalesAnidadosListo || typeof bootstrap === "undefined") { return; }
        modalesAnidadosListo = true;

        $(document).on("show.bs.modal", ".modal", function () {
            var previos = $(".modal.show").length; // modales ya visibles (padres)
            if (previos === 0) { return; }          // primer nivel: z-index por defecto
            var z = 1055 + previos * 20;
            $(this).css("z-index", z);
            // El backdrop se crea justo despues; se eleva por debajo del hijo.
            window.setTimeout(function () {
                $(".modal-backdrop").last().css("z-index", z - 10);
            }, 0);
        });

        // Al cerrar un modal hijo, si aun queda otro abierto, se conserva el
        // bloqueo de scroll del body (Bootstrap lo quita al cerrar cualquiera).
        $(document).on("hidden.bs.modal", ".modal", function () {
            if ($(".modal.show").length) { $("body").addClass("modal-open"); }
        });
    };

    /* --- Filtro de entrada numerica (reutilizable) ----------------- */
    // Restringe uno o varios inputs a numeros decimales positivos, bloqueando
    // caracteres no validos al teclear y al pegar. 'selector' admite lista
    // separada por comas. Se apoya en delegacion para funcionar tambien con
    // inputs que aparecen dentro de modales.
    AX.restringirNumerico = function (selector) {
        $(document).on("keypress", selector, function (e) {
            if (e.ctrlKey || e.metaKey) { return; }         // permitir atajos
            var ch = String.fromCharCode(e.which);
            if (e.which === 8 || e.which === 0 || e.which === 13) { return; } // back/enter
            if (!/[0-9.]/.test(ch)) { e.preventDefault(); return; }
            // Un solo punto decimal.
            if (ch === "." && String(this.value).indexOf(".") !== -1) { e.preventDefault(); }
        });
        $(document).on("input", selector, function () {
            var limpio = String(this.value).replace(/[^0-9.]/g, "").replace(/(\..*)\./g, "$1");
            if (limpio !== this.value) { this.value = limpio; }
        });
    };

    /* --- Vigencias: calculo automatico de vencimiento -------------- */
    // Regla de negocio del VPS: vencimiento = fecha_creacion + 1 mes - 1 dia.
    // (Ej: 2026-03-15 -> 2026-04-14). Recibe una fecha ISO (yyyy-mm-dd, admite
    // timestamp) y devuelve la fecha de vencimiento en formato yyyy-mm-dd, o ""
    // si la entrada no es una fecha valida. El calculo es identico al del
    // backend (PHP) para que la vista previa coincida con lo que se persiste.
    AX.calcularVencimiento = function (fechaIso) {
        if (!fechaIso) { return ""; }
        var partes = String(fechaIso).substring(0, 10).split("-");
        if (partes.length !== 3) { return ""; }
        var y = parseInt(partes[0], 10);
        var m = parseInt(partes[1], 10);
        var d = parseInt(partes[2], 10);
        if (isNaN(y) || isNaN(m) || isNaN(d)) { return ""; }
        // Fecha local (sin desfase de zona horaria).
        var fecha = new Date(y, m - 1, d);
        if (isNaN(fecha.getTime())) { return ""; }
        fecha.setMonth(fecha.getMonth() + 1);   // + 1 mes
        fecha.setDate(fecha.getDate() - 1);     // - 1 dia
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return fecha.getFullYear() + "-" + p(fecha.getMonth() + 1) + "-" + p(fecha.getDate());
    };

    // Vigencia ANUAL de productos (dominios, SSL, correo, hosting): la fecha
    // final = fecha_inicio + 1 año - 1 dia. (Ej: 2026-01-01 -> 2026-12-31).
    // Recibe una fecha ISO (yyyy-mm-dd, admite timestamp) y devuelve la fecha
    // final en yyyy-mm-dd, o "" si la entrada no es valida. Es la MISMA regla
    // que aplica el backend (calcular_vencimiento_anual en PHP), para que la
    // vista previa coincida con lo que se persiste.
    AX.calcularVigenciaAnual = function (fechaIso) {
        if (!fechaIso) { return ""; }
        var partes = String(fechaIso).substring(0, 10).split("-");
        if (partes.length !== 3) { return ""; }
        var y = parseInt(partes[0], 10);
        var m = parseInt(partes[1], 10);
        var d = parseInt(partes[2], 10);
        if (isNaN(y) || isNaN(m) || isNaN(d)) { return ""; }
        var fecha = new Date(y, m - 1, d);           // fecha local (sin desfase TZ)
        if (isNaN(fecha.getTime())) { return ""; }
        fecha.setFullYear(fecha.getFullYear() + 1);  // + 1 año
        fecha.setDate(fecha.getDate() - 1);          // - 1 dia
        var p = function (n) { return (n < 10 ? "0" : "") + n; };
        return fecha.getFullYear() + "-" + p(fecha.getMonth() + 1) + "-" + p(fecha.getDate());
    };

    // Vincula un input de fecha de INICIO con el campo (de solo lectura) que
    // refleja la fecha FINAL calculada automaticamente (inicio + 1 año - 1 dia).
    // La fecha final ya no es manipulable por el usuario: se recalcula sola cada
    // vez que cambia la de inicio. Reutilizable por cualquier modal con vigencia.
    //   inicioSel : input date de la fecha de inicio.
    //   finSel    : input/elemento (solo lectura) donde se muestra la fecha final.
    // Devuelve { valor(), refrescar() }:
    //   valor()    -> fecha final vigente (yyyy-mm-dd) para el envio del formulario.
    //   refrescar()-> recalcula y repinta (llamar tras precargar el inicio en edicion).
    AX.vincularVigencia = function (inicioSel, finSel) {
        var $inicio = $(inicioSel);
        var $fin    = $(finSel);
        function refrescar() {
            var final = AX.calcularVigenciaAnual($inicio.val());
            if ($fin.is("input, select, textarea")) { $fin.val(final); }
            else { $fin.text(final ? AX.formatearFecha(final) : "—"); }
            return final;
        }
        $inicio.off(".axvig").on("change.axvig input.axvig", refrescar);
        refrescar();
        return {
            valor:     function () { return AX.calcularVigenciaAnual($inicio.val()); },
            refrescar: refrescar
        };
    };

    /* --- Medidas con unidad (GB / TB) ------------------------------ */
    // Empaqueta el valor numerico de un input junto a la unidad de su selector
    // adjunto (GB/TB). Devuelve { valor: string, unidad: 'GB'|'TB' }. Reutilizable
    // por cualquier campo con control de unidad (disco, RAM, ancho de banda).
    //   var disco = AX.leerMedida("#frDisco", "#frDiscoUnidad");
    AX.leerMedida = function (valorSelector, unidadSelector) {
        var valor  = $.trim($(valorSelector).val() || "");
        var unidad = String($(unidadSelector).val() || "GB").toUpperCase();
        if (unidad !== "GB" && unidad !== "TB") { unidad = "GB"; }
        return { valor: valor, unidad: unidad };
    };

    // Formatea una medida (valor + unidad) para mostrarla. "" si no hay valor.
    //   AX.formatearMedida(80, "GB")  ->  "80 GB"
    AX.formatearMedida = function (valor, unidad) {
        if (valor === null || valor === undefined || valor === "") { return ""; }
        var n = parseFloat(valor);
        if (isNaN(n)) { return ""; }
        // Se muestra sin decimales si es entero (80 en vez de 80.00).
        var texto = (n % 1 === 0) ? String(n) : n.toLocaleString("es-CO", { maximumFractionDigits: 2 });
        return texto + " " + (unidad || "GB");
    };

    /* --- Combobox: input autocomplete + dropdown (reutilizable) ---- */
    // Componente hibrido en Vanilla JS: un input con lista desplegable.
    //   - El boton lateral (data-rol="toggle") abre/cierra la lista completa.
    //   - Al escribir en el input, la lista se abre sola y filtra en vivo por
    //     coincidencia de texto (contiene).
    //   - Al elegir una opcion, su texto se carga en el input y se recuerda su id.
    //
    // Estructura esperada del contenedor (data-rol):
    //   input, toggle, panel (una <ul> para las opciones).
    // opciones: { onSeleccion(item), onEscribir(texto) }  (ambos opcionales)
    //
    // Uso:
    //   var cb = AX.combobox("#cbRef", { onSeleccion: fn });
    //   cb.opciones([{id, texto}, ...]);
    //   cb.seleccionarTexto("nombre");   // fija el input y resuelve el id si coincide
    //   cb.agregar({id, texto}, true);   // agrega y selecciona (p. ej. tras crear)
    //   cb.valor();                      // -> { texto, id }  (id null si es texto libre)
    AX.combobox = function (contenedor, opciones) {
        var $cont = contenedor && contenedor.jquery ? contenedor : $(contenedor);
        if (!$cont.length) { return null; }
        opciones = opciones || {};

        var $input  = $cont.find("[data-rol='input']");
        var $toggle = $cont.find("[data-rol='toggle']");
        var $panel  = $cont.find("[data-rol='panel']");

        var items = [];          // [{id, texto}] disponibles
        var seleccionId = null;  // id de la opcion elegida (null si es texto libre)

        function itemPorId(id) {
            for (var i = 0; i < items.length; i++) { if (items[i].id === id) { return items[i]; } }
            return null;
        }

        function abierto() { return !$panel.hasClass("d-none"); }
        function abrir()   { pintar($input.val()); $panel.removeClass("d-none"); $cont.addClass("is-open"); }
        function cerrar()  { $panel.addClass("d-none"); $cont.removeClass("is-open"); }

        function pintar(filtro) {
            filtro = (filtro || "").trim().toLowerCase();
            var visibles = items.filter(function (it) {
                return !filtro || String(it.texto).toLowerCase().indexOf(filtro) !== -1;
            });
            if (!visibles.length) {
                $panel.html('<li class="ax-combobox__vacio">Sin coincidencias</li>');
                return;
            }
            $panel.html(visibles.map(function (it) {
                var sel = it.id === seleccionId ? ' class="is-seleccionado"' : '';
                return '<li data-id="' + AX.escaparHtml(it.id) + '"' + sel + '>' +
                       AX.escaparHtml(it.texto) + '</li>';
            }).join(""));
        }

        // Toggle: abre/cierra la lista completa.
        $toggle.on("click", function (e) {
            e.preventDefault(); e.stopPropagation();
            abierto() ? cerrar() : abrir();
            $input.trigger("focus");
        });
        // Escribir: abre la lista (si estaba cerrada) y filtra en vivo.
        $input.on("input", function () {
            seleccionId = null;              // texto libre hasta que elija de la lista
            if (!abierto()) { abrir(); } else { pintar($input.val()); }
            if (opciones.onEscribir) { opciones.onEscribir($input.val()); }
        });
        $input.on("click", function (e) { e.stopPropagation(); if (!abierto()) { abrir(); } });
        // Elegir una opcion.
        $panel.on("click", "li[data-id]", function () {
            var id = $(this).attr("data-id");
            var it = itemPorId(id);
            $input.val(it ? it.texto : "");
            seleccionId = id;
            cerrar();
            if (opciones.onSeleccion) { opciones.onSeleccion(it); }
        });
        $panel.on("click", function (e) { e.stopPropagation(); });
        // Clic fuera: cerrar.
        $(document).on("click", function () { if (abierto()) { cerrar(); } });

        return {
            // Reemplaza la lista de opciones disponibles.
            opciones: function (nuevos) { items = nuevos || []; seleccionId = null; },
            // Vacia el input y la seleccion.
            limpiar: function () { $input.val(""); seleccionId = null; cerrar(); },
            // Fija el texto del input y resuelve el id si coincide con una opcion.
            seleccionarTexto: function (texto) {
                texto = texto || "";
                $input.val(texto);
                var m = null;
                for (var i = 0; i < items.length; i++) {
                    if (String(items[i].texto).toLowerCase() === String(texto).toLowerCase()) { m = items[i]; break; }
                }
                seleccionId = m ? m.id : null;
            },
            // Agrega una opcion; si 'seleccionar' es true, la deja cargada en el input.
            agregar: function (item, seleccionar) {
                if (!item) { return; }
                if (!itemPorId(item.id)) { items.push(item); }
                if (seleccionar) { $input.val(item.texto); seleccionId = item.id; }
            },
            // Lectura para el envio del formulario.
            valor: function () { return { texto: $.trim($input.val()), id: seleccionId }; },
            abrir: abrir,
            cerrar: cerrar
        };
    };

    /* --- Manejadores globales delegados ---------------------------- */
    $(function () {
        // Apilado correcto de modales anidados (z-index + backdrops).
        AX.iniciarModalesAnidados();

        // Mostrar/ocultar contrasena en cualquier campo con data-toggle-pass.
        $(document).on("click", "[data-toggle-pass]", function () {
            var $inp = $($(this).data("toggle-pass"));
            if (!$inp.length) { return; }
            var esPassword = $inp.attr("type") === "password";
            $inp.attr("type", esPassword ? "text" : "password");
            $(this).text(esPassword ? "Ocultar" : "Mostrar");
            $inp.trigger("focus");
        });
    });

}(window.AX, jQuery));
