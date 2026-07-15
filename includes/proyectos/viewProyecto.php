<?php
/* ---------------------------------------------------------------------
   Modulo: Proyectos - Vista de detalle (viewProyecto).
   Muestra el proyecto con su informacion relacionada en pestañas:
   Equipo (N:N), Dominios / VPS / SSL / Hosting (recursos N:N) y Notas.
   Lo rellena proyectos.js desde endpoints/proyectos/ver.php.
   El tablero Kanban se agregara aqui mas adelante.
   --------------------------------------------------------------------- */
$tabs = [
    ['id' => 'equipo',   'label' => 'Equipo',    'icon' => 'bi-people',        'tbody' => 'detEquipo',   'cols' => ['Integrante', 'Correo', 'Rol en el proyecto', 'Estado']],
    ['id' => 'dominios', 'label' => 'Dominios',  'icon' => 'bi-globe2',        'tbody' => 'detDominios', 'cols' => ['Dominio', 'Proveedor', 'Vence', 'Uso']],
    ['id' => 'hosting',  'label' => 'Hosting',   'icon' => 'bi-hdd-network',   'tbody' => 'detHosting',  'cols' => ['Servidor', 'Espacio', 'Uso']],
    ['id' => 'notas',    'label' => 'Notas',     'icon' => 'bi-journal-text',  'tbody' => 'detNotas',    'cols' => ['Fecha', 'Autor', 'Nota']],
];
?>
<div id="vistaDetalle" class="d-none">
    <div class="detalle">

        <div class="detalle__head">
            <div class="detalle__head-left">
                <button type="button" class="detalle__volver" id="btnVolverDetalle" data-ax-volver
                        aria-label="Volver al listado" title="Volver">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </button>
                <div>
                    <span class="detalle__eyebrow">Proyecto</span>
                    <h2 class="detalle__title" id="detTitulo">Detalle del proyecto</h2>
                </div>
            </div>
        </div>

        <section class="detalle__seccion">
            <h3 class="detalle__seccion-titulo">Informacion general</h3>
            <dl class="detalle__grid">
                <div><dt>Cliente</dt><dd id="detCliente">—</dd></div>
                <div><dt>Estado</dt><dd id="detEstado">—</dd></div>
                <div><dt>Servidor / VPS</dt><dd id="detServidor">—</dd></div>
                <div><dt>Fecha de inicio</dt><dd id="detInicio">—</dd></div>
                <div><dt>Entrega estimada</dt><dd id="detEntrega">—</dd></div>
                <div class="detalle__grid-full"><dt>Descripción / alcance</dt><dd id="detDescripcion">—</dd></div>
                <div><dt>Registrado en el sistema</dt><dd id="detCreado">—</dd></div>
                <div><dt>Actualizado</dt><dd id="detActualizado">—</dd></div>
            </dl>
        </section>

        <div class="detalle-tabs-barra">
            <ul class="nav nav-tabs detalle-tabs" id="detTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tabbtn-kanban"
                            data-bs-toggle="tab" data-bs-target="#pane-kanban"
                            type="button" role="tab" aria-controls="pane-kanban" aria-selected="true">
                        <i class="bi bi-kanban" aria-hidden="true"></i> Tablero
                    </button>
                </li>
                <?php foreach ($tabs as $t): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link"
                                id="tabbtn-<?php echo $t['id']; ?>"
                                data-bs-toggle="tab" data-bs-target="#pane-<?php echo $t['id']; ?>"
                                type="button" role="tab"
                                aria-controls="pane-<?php echo $t['id']; ?>"
                                aria-selected="false">
                            <i class="bi <?php echo $t['icon']; ?>" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($t['label']); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <!-- Acceso a las tarjetas archivadas. Solo visible en el tab del tablero
                 (lo alterna proyectos.js al cambiar de pestaña). -->
            <button type="button" class="btn btn-outline-secondary btn-sm detalle-tabs-barra__accion"
                    id="btnVerArchivadas">
                <i class="bi bi-archive" aria-hidden="true"></i> Archivadas
            </button>
        </div>

        <div class="tab-content detalle-tabs__content" id="detTabsContent">
            <!-- Tablero Kanban (Sortable.js). Lo rellena proyectos.js desde
                 endpoints/kanban/tablero.php; las columnas se pintan dinamicamente. -->
            <div class="tab-pane fade show active" id="pane-kanban" role="tabpanel"
                 aria-labelledby="tabbtn-kanban">
                <div class="kanban" id="kanbanTablero" data-proyecto-id="">
                    <p class="kanban__estado" id="kanbanEstado">Cargando tablero…</p>
                </div>
            </div>

            <?php foreach ($tabs as $t): ?>
                <div class="tab-pane fade"
                     id="pane-<?php echo $t['id']; ?>" role="tabpanel"
                     aria-labelledby="tabbtn-<?php echo $t['id']; ?>">
                    <div class="tabla-wrap">
                        <table class="table tabla">
                            <thead>
                                <tr>
                                    <?php foreach ($t['cols'] as $col): ?>
                                        <th><?php echo htmlspecialchars($col); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="<?php echo $t['tbody']; ?>"></tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- Modal de nueva tarjeta (tarea) del Kanban. Reutiliza el patron de modales
     del sistema (AX.modal). La columna destino se fija al abrir. -->
<div class="modal fade" id="modalTarea" tabindex="-1" aria-hidden="true" aria-labelledby="formTareaTitulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="formTareaTitulo">Nueva tarea</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="formTareaError" class="alert alert-danger d-none" role="alert"></div>
                <form id="formTarea" novalidate autocomplete="off">
                    <input type="hidden" id="ftColumnaId" name="columna_id">
                    <div class="mb-3">
                        <label class="form-label" for="ftTitulo">Título *</label>
                        <input class="form-control" type="text" id="ftTitulo" name="titulo"
                               maxlength="255" required>
                        <div class="form-text" id="ftColumnaNombre"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ftPrioridad">Prioridad</label>
                        <select class="form-select" id="ftPrioridad" name="prioridad">
                            <option value="Baja">Baja</option>
                            <option value="Media" selected>Media</option>
                            <option value="Alta">Alta</option>
                            <option value="Crítica">Crítica</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="ftDescripcion">Descripción</label>
                        <textarea class="form-control" id="ftDescripcion" name="descripcion"
                                  rows="3" maxlength="4000"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnGuardarTarea">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> <span data-rol="texto">Crear tarea</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de DETALLE de una tarjeta (solo lectura). Lo rellena proyectos.js
     desde endpoints/kanban/ver_tarea.php al hacer clic en una tarjeta. -->
<div class="modal fade" id="modalTareaDetalle" tabindex="-1" aria-hidden="true" aria-labelledby="dtTitulo">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="detalle__eyebrow">Tarea</span>
                    <h2 class="modal-title h5" id="dtTitulo">—</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="dtCargando" class="text-muted">Cargando…</div>

                <div id="dtContenido" class="d-none kanban-detalle">
                    <!-- Columna principal: información de la tarea -->
                    <div class="kanban-detalle__main">
                        <dl class="detalle__grid">
                            <div><dt>Estado / columna</dt><dd id="dtColumna">—</dd></div>
                            <div><dt>Prioridad</dt><dd id="dtPrioridad">—</dd></div>
                            <div><dt>Fecha de inicio</dt><dd id="dtInicio">—</dd></div>
                            <div><dt>Fecha límite</dt><dd id="dtLimite">—</dd></div>
                            <div><dt>Finalización real</dt><dd id="dtFinReal">—</dd></div>
                            <div><dt>Creada</dt><dd id="dtCreada">—</dd></div>
                            <div><dt>Actualizada</dt><dd id="dtActualizada">—</dd></div>
                            <div class="detalle__grid-full"><dt>Responsables</dt><dd id="dtResponsables">—</dd></div>
                        </dl>

                        <!-- Descripción: parte central de la tarea (lo que hay que hacer),
                             editable en el sitio con Editar / Guardar / Cancelar. -->
                        <section class="detalle__seccion kanban-desc">
                            <div class="kanban-desc__head">
                                <h3 class="detalle__seccion-titulo mb-0">Descripción de la tarea</h3>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditarDesc">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </button>
                            </div>

                            <div id="dtDescVista" class="kanban-desc__texto">—</div>

                            <div id="dtDescEditor" class="kanban-desc__editor d-none">
                                <textarea class="form-control" id="dtDescInput" rows="6" maxlength="4000"
                                          placeholder="Describe qué hay que hacer en esta tarea…"></textarea>
                                <div id="dtDescError" class="alert alert-danger d-none py-1 px-2 mt-2" role="alert"></div>
                                <div class="kanban-desc__acciones mt-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelarDesc">Cancelar</button>
                                    <button type="button" class="btn btn-primary btn-sm" id="btnGuardarDesc">
                                        <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="detalle__seccion">
                            <h3 class="detalle__seccion-titulo">Adjuntos</h3>
                            <ul class="kanban-detalle__lista" id="dtAdjuntos"></ul>

                            <form id="formAdjunto" class="kanban-adj__form mt-2" autocomplete="off">
                                <div id="dtAdjuntoError" class="alert alert-danger d-none py-1 px-2 mb-2" role="alert"></div>
                                <div class="input-group input-group-sm">
                                    <input type="file" class="form-control" id="dtArchivo">
                                    <button type="submit" class="btn btn-outline-secondary" id="btnSubirAdjunto">
                                        <i class="bi bi-upload" aria-hidden="true"></i>
                                        <span data-rol="texto">Adjuntar</span>
                                    </button>
                                </div>
                                <div class="form-text">Cualquier tipo de archivo, hasta 25 MB.</div>
                            </form>
                        </section>
                    </div>

                    <!-- Panel lateral derecho: comentarios -->
                    <aside class="kanban-detalle__side kanban-coment">
                        <h3 class="detalle__seccion-titulo">
                            <i class="bi bi-chat-left-text" aria-hidden="true"></i> Comentarios
                        </h3>
                        <ul class="kanban-coment__lista" id="dtComentarios"></ul>

                        <form id="formComentario" class="kanban-coment__form" autocomplete="off">
                            <div id="dtComentarioError" class="alert alert-danger d-none py-1 px-2 mb-2" role="alert"></div>
                            <textarea class="form-control" id="dtNuevoComentario" rows="2"
                                      maxlength="4000" placeholder="Escribe un comentario…"></textarea>
                            <button type="submit" class="btn btn-primary btn-sm mt-2" id="btnComentar">
                                <i class="bi bi-send" aria-hidden="true"></i> <span data-rol="texto">Comentar</span>
                            </button>
                        </form>
                    </aside>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm me-auto" id="btnArchivarTarea">
                    <i class="bi bi-archive" aria-hidden="true"></i> Archivar
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de tarjetas ARCHIVADAS del proyecto. Lo rellena proyectos.js desde
     endpoints/kanban/tareas_archivadas.php al pulsar "Archivadas". Cada fila
     permite restaurar la tarjeta (vuelve al tablero, al final de su columna). -->
<div class="modal fade" id="modalArchivadas" tabindex="-1" aria-hidden="true" aria-labelledby="modalArchivadasTitulo">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="detalle__eyebrow">Tablero</span>
                    <h2 class="modal-title h5" id="modalArchivadasTitulo">Tarjetas archivadas</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <ul class="kanban-archivadas" id="listaArchivadas">
                    <li class="kanban-archivadas__vacio text-muted">Cargando…</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
