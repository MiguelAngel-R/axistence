<?php
/* ---------------------------------------------------------------------
   Menu lateral de la aplicacion.
   El menu es data-driven: para agregar un modulo nuevo basta con anadir
   una entrada al arreglo $menuGrupos (id, label, icono y href). El item
   se resalta como activo cuando su 'id' coincide con $moduloActivo.
   --------------------------------------------------------------------- */
$moduloActivo = $moduloActivo ?? '';

$menuGrupos = [
    [
        'titulo' => null,
        'items'  => [
            ['id' => 'dashboard', 'label' => 'Panel', 'icon' => 'bi-grid-1x2-fill', 'href' => 'index.php?vista=dashboard'],
        ],
    ],
    // Grupo principal SIN titulo: Clientes y Proveedores quedaron fusionados
    // aqui junto a los productos (se elimino el grupo "Administracion" y el
    // separador visual "Productos").
    [
        'titulo' => null,
        'items'  => [
            ['id' => 'clientes', 'label' => 'Clientes', 'icon' => 'bi-person-vcard', 'href' => 'index.php?vista=clientes'],
            ['id' => 'proveedores', 'label' => 'Proveedores', 'icon' => 'bi-truck', 'href' => 'index.php?vista=proveedores'],
            ['id' => 'vps', 'label' => 'VPS / Servidores', 'icon' => 'bi-hdd-rack-fill', 'href' => 'index.php?vista=vps'],
            ['id' => 'dominios', 'label' => 'Dominios', 'icon' => 'bi-globe2', 'href' => 'index.php?vista=dominios'],
            ['id' => 'ssl', 'label' => 'Certificados SSL', 'icon' => 'bi-shield-lock-fill', 'href' => 'index.php?vista=ssl'],
            ['id' => 'correo', 'label' => 'Cuentas de correo', 'icon' => 'bi-envelope-fill', 'href' => 'index.php?vista=correo'],
            ['id' => 'hosting', 'label' => 'Hosting', 'icon' => 'bi-hdd-network-fill', 'href' => 'index.php?vista=hosting'],
            ['id' => 'otros', 'label' => 'Otros productos', 'icon' => 'bi-box-seam', 'href' => 'index.php?vista=otros'],
            ['id' => 'proyectos', 'label' => 'Proyectos', 'icon' => 'bi-kanban-fill', 'href' => 'index.php?vista=proyectos'],
        ],
    ],
    // Grupo "Sistema" al final: administracion de usuarios de la plataforma.
    [
        'titulo' => 'Sistema',
        'items'  => [
            ['id' => 'usuarios_internos', 'label' => 'Usuarios de Sistema', 'icon' => 'bi-people-fill', 'href' => 'index.php?vista=usuarios_internos'],
        ],
    ],
];
?>
<nav class="app-nav">
    <?php foreach ($menuGrupos as $grupo): ?>
        <?php if (!empty($grupo['titulo'])): ?>
            <div class="app-nav__title"><?php echo htmlspecialchars($grupo['titulo']); ?></div>
        <?php endif; ?>
        <?php foreach ($grupo['items'] as $item): ?>
            <a class="app-nav__item<?php echo $item['id'] === $moduloActivo ? ' is-active' : ''; ?>"
               href="<?php echo $item['href']; ?>">
                <i class="bi <?php echo $item['icon']; ?>" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</nav>
