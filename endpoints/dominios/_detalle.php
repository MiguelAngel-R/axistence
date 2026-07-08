<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Utilidades compartidas por los endpoints "agregar_*" del
//  detalle (registros DNS, notas). Cada creacion se audita con
//  registro_id = id del dominio, para que sea visible de inmediato en el
//  tab "Historial" del detalle (ver.php filtra por ese id).
// =====================================================================

if (!defined('UUID_RE')) {
    define('UUID_RE', '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
}

// Comprueba que el dominio exista y devuelve su nombre (para descripciones).
function exigir_dominio(PDO $pdo, string $dominioId): string
{
    if (!preg_match(UUID_RE, $dominioId)) {
        json_error('Identificador de dominio no valido', 422);
    }
    $stmt = $pdo->prepare('SELECT nombre_dominio FROM public.dominios WHERE id = :id');
    $stmt->execute([':id' => $dominioId]);
    $nombre = $stmt->fetchColumn();
    if ($nombre === false) {
        json_error('Dominio no encontrado', 404);
    }
    return (string)$nombre;
}

// Registra en el log un evento asociado a ESTE dominio (registro_id = dominioId).
function auditar_en_dominio(string $dominioId, string $descripcion, array $datos): void
{
    registrar_auditoria('CREAR', 'Dominios', $descripcion, $dominioId, null, $datos);
}
