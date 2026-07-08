<?php
/* ---------------------------------------------------------------------
   Shell de la aplicacion (parte inferior): cierra el contenido y el
   layout, y delega en footer.php la carga de JS globales + de la vista.
   --------------------------------------------------------------------- */
?>
        </main><!-- /.app-content -->

        <!-- Footer fijo y reutilizable: paginador, acciones e info.
             Se rellena dinamicamente desde JS (general.js). -->
        <footer class="app-footer" id="appFooter">
            <div class="app-footer__info" id="footerInfo"></div>
            <div class="app-footer__actions" id="footerActions"></div>
            <div class="app-footer__pager" id="footerPager"></div>
        </footer>
    </div><!-- /.app-main -->
</div><!-- /.app -->
<?php
require __DIR__ . '/footer.php';
