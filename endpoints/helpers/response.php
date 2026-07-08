<?php
declare(strict_types=1);

// =====================================================================
//  Helpers de respuesta JSON estandar para todos los endpoints.
//  Formato: { ok: bool, mensaje: string, data|detalles: mixed }
// =====================================================================

function json_response($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data = null, string $mensaje = 'OK'): void
{
    json_response(['ok' => true, 'mensaje' => $mensaje, 'data' => $data]);
}

function json_error(string $mensaje, int $status = 400, $detalles = null): void
{
    json_response(['ok' => false, 'mensaje' => $mensaje, 'detalles' => $detalles], $status);
}

// Lee y decodifica el cuerpo JSON de la peticion (para POST/PUT via AJAX).
function body_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Valida que los campos requeridos existan y no esten vacios.
// Devuelve un arreglo con los nombres de campos faltantes.
function campos_faltantes(array $data, array $requeridos): array
{
    $faltan = [];
    foreach ($requeridos as $campo) {
        if (!isset($data[$campo]) || (is_string($data[$campo]) && trim($data[$campo]) === '')) {
            $faltan[] = $campo;
        }
    }
    return $faltan;
}
