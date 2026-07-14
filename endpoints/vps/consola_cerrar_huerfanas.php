<?php
declare(strict_types=1);

// =====================================================================
//  VPS / Consola SSH - Cerrar sesiones huerfanas (server-to-server).
//
//  Una sesion queda "huerfana" (estado 'activa' sin 'fin') cuando el server
//  Node cae o el proceso muere sin poder cerrar la sesion. Node llama a este
//  endpoint UNA vez al arrancar: como un proceso Node recien iniciado no tiene
//  ninguna consola SSH viva, cualquier sesion 'activa' en BD es un resto de
//  una ejecucion anterior y se marca como 'error' (cierre anormal).
//
//  Supone una UNICA instancia de Node. Si en el futuro hay varias, habria que
//  acotar por instancia (p. ej. un id de server) para no cerrar sesiones de
//  otro proceso vivo.
//
//  Autenticacion: clave compartida Node<->PHP (X-Consola-Node-Key).
//  POST {}
//  200 -> { ok, data: { cerradas } }
// =====================================================================

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../helpers/consola_tokens.php';

solo_metodo('POST');
consola_requiere_node_key();

$pdo = Database::get();

$upd = $pdo->prepare(
    "UPDATE public.vps_consola_sesiones
        SET estado = 'error', fin = CURRENT_TIMESTAMP
      WHERE estado = 'activa'"
);
$upd->execute();
$cerradas = $upd->rowCount();

if ($cerradas > 0) {
    registrar_auditoria(
        'CAMBIO_ESTADO',
        'VPS',
        'Cierre de ' . $cerradas . ' sesion(es) de consola huerfana(s) al arrancar el server'
    );
}

json_ok(['cerradas' => $cerradas], 'Sesiones huerfanas cerradas');
