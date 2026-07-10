<?php
declare(strict_types=1);

// =====================================================================
//  Notificacion a los navegadores en tiempo real (sockets).
//  Publica un evento en el servidor de sockets (Node, websockets/index2.js),
//  que lo reemite a los navegadores que tienen abierto el listado del modulo
//  para que la tabla se actualice sin recargar ni volver a consultar la BD.
//
//  Es "fire-and-forget": un fallo aqui (Node caido, red, etc.) JAMAS debe
//  romper la operacion principal. Por eso se usan timeouts cortos y se ignora
//  cualquier error (solo queda rastro en el log).
//
//  La llamada va autorizada con la clave compartida SOCKETS_KEY (cabecera
//  X-Sockets-Key), que debe coincidir con la del server Node.
// =====================================================================

function notificar_socket(string $modulo, string $evento, array $data): void
{
    // Sin cURL no intentamos nada (el tiempo real no es critico para la
    // operacion; el listado siempre puede recargarse a mano).
    if (!function_exists('curl_init')) {
        return;
    }
    try {
        $payload = json_encode(
            ['modulo' => $modulo, 'evento' => $evento, 'data' => $data],
            JSON_UNESCAPED_UNICODE
        );

        $ch = curl_init(rtrim(SOCKETS_URL, '/') . '/emitir');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Sockets-Key: ' . SOCKETS_KEY,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // Timeouts cortos: la respuesta al usuario no debe esperar por esto.
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            CURLOPT_TIMEOUT_MS        => 900,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[AXISTENCE] Fallo al notificar por socket: ' . $e->getMessage());
    }
}
