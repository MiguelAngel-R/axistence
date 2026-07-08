<?php
declare(strict_types=1);

// =====================================================================
//  Seed inicial: crea el rol Administrador (con permisos totales) y un
//  usuario administrador para poder ingresar por primera vez.
//  Idempotente: puede ejecutarse varias veces sin duplicar datos.
//
//  Uso (CLI):  php endpoints/seed/seed_admin.php
//  IMPORTANTE: cambia la contrasena tras el primer ingreso.
// =====================================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../helpers/passwords.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo puede ejecutarse por linea de comandos.\n");
}

$correoAdmin = getenv('AXISTENCE_ADMIN_EMAIL') ?: 'admin@interacto.com';
$claveAdmin  = getenv('AXISTENCE_ADMIN_PASS')  ?: 'Admin123*';

// Modulos del sistema para inicializar la matriz de permisos.
$modulos = [
    'Clientes', 'Usuarios', 'Proveedores', 'Dominios', 'VPS', 'SSL',
    'Correo', 'Hosting', 'Otros', 'Proyectos', 'Kanban', 'VistaCliente', 'Auditoria',
];

$pdo = Database::get();
$pdo->beginTransaction();

try {
    // --- Rol Administrador ------------------------------------------
    $stmt = $pdo->prepare('SELECT id FROM public.roles WHERE nombre = :n');
    $stmt->execute([':n' => 'Administrador']);
    $rolId = $stmt->fetchColumn();

    if ($rolId === false) {
        $stmt = $pdo->prepare(
            'INSERT INTO public.roles (nombre, descripcion) VALUES (:n, :d) RETURNING id'
        );
        $stmt->execute([':n' => 'Administrador', ':d' => 'Acceso total al sistema']);
        $rolId = $stmt->fetchColumn();
        echo "Rol 'Administrador' creado.\n";
    } else {
        echo "Rol 'Administrador' ya existe.\n";
    }

    // --- Permisos totales para el rol -------------------------------
    $permStmt = $pdo->prepare(
        'INSERT INTO public.rol_permisos
             (rol_id, modulo, puede_ver, puede_crear, puede_editar, puede_eliminar)
         VALUES (:r, :m, true, true, true, true)
         ON CONFLICT (rol_id, modulo) DO UPDATE
             SET puede_ver = true, puede_crear = true,
                 puede_editar = true, puede_eliminar = true'
    );
    foreach ($modulos as $m) {
        $permStmt->execute([':r' => $rolId, ':m' => $m]);
    }
    echo 'Permisos asignados para ' . count($modulos) . " modulos.\n";

    // --- Usuario administrador --------------------------------------
    $stmt = $pdo->prepare('SELECT id FROM public.usuarios_internos WHERE correo = :c');
    $stmt->execute([':c' => $correoAdmin]);

    if ($stmt->fetchColumn() === false) {
        $hash = hash_clave($claveAdmin);   // SHA1 (formato del proyecto)
        $stmt = $pdo->prepare(
            'INSERT INTO public.usuarios_internos
                 (nombres, apellidos, nombre_completo, username, correo, contrasena, rol_id, estado)
             VALUES (:nom, :ape, :nc, :u, :c, :p, :r, :e)'
        );
        $stmt->execute([
            ':nom' => 'Administrador',
            ':ape' => 'del Sistema',
            ':nc'  => 'Administrador del Sistema',
            ':u'   => 'admin',
            ':c'   => $correoAdmin,
            ':p'   => $hash,
            ':r'   => $rolId,
            ':e'   => 'Activo',
        ]);
        echo "Usuario administrador creado:\n";
        echo "   correo:     $correoAdmin\n";
        echo "   contrasena: $claveAdmin   (cambiala tras el primer ingreso)\n";
    } else {
        echo "Usuario administrador ya existe: $correoAdmin\n";
    }

    $pdo->commit();
    echo "Seed completado correctamente.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error en el seed: ' . $e->getMessage() . "\n");
    exit(1);
}
