<?php
declare(strict_types=1);

// =====================================================================
//  Dominios - Tipo de administracion + cuenta, y vencimiento automatico.
//  Compartido por crear/actualizar.
//
//  REGLA DE NEGOCIO (reingenieria): el tipo de administracion se DERIVA del
//  servidor elegido, ya no se digita:
//    - Si el dominio apunta a un VPS del sistema  -> PROPIA  (servidor propio).
//    - Si apunta a un servidor externo (o ninguno) -> TERCEROS.
//  Si es PROPIA, la cuenta de acceso es obligatoria y debe pertenecer al
//  proveedor seleccionado. Si es TERCEROS, cuenta_id queda null.
//  Devuelve ['tipo_administracion' => ..., 'cuenta_id' => ...|null].
//  Corta con json_error(...) si algo es invalido.
// =====================================================================

function resolver_administracion_dominio(PDO $pdo, array $in, string $proveedorId, ?string $vpsId): array
{
    $re     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    $cuenta = trim((string)($in['cuenta_id'] ?? ''));

    // Sin VPS del sistema -> administracion de TERCEROS (servidor externo).
    if ($vpsId === null) {
        return ['tipo_administracion' => 'TERCEROS', 'cuenta_id' => null];
    }

    // Con VPS del sistema -> PROPIA: requiere cuenta valida y del mismo proveedor.
    if ($cuenta === '') {
        json_error('Selecciona la cuenta con la que se administra el dominio', 422);
    }
    if (!preg_match($re, $cuenta)) {
        json_error('La cuenta seleccionada no es valida', 422);
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM public.cuentas_acceso WHERE id = :c AND proveedor_id = :p'
    );
    $stmt->execute([':c' => $cuenta, ':p' => $proveedorId]);
    if (!$stmt->fetchColumn()) {
        json_error('La cuenta no pertenece al proveedor seleccionado', 422);
    }

    return ['tipo_administracion' => 'PROPIA', 'cuenta_id' => $cuenta];
}

// ---------------------------------------------------------------------
//  Vencimiento automatico del dominio: fecha_registro + 1 año - 1 dia.
//  (Ej: 2026-03-15 -> 2027-03-14). El backend es la fuente autoritativa.
//  Recibe una fecha 'YYYY-MM-DD' valida y devuelve el vencimiento igual.
// ---------------------------------------------------------------------
function calcular_vencimiento_anual(string $fechaRegistro): string
{
    $fecha = DateTime::createFromFormat('Y-m-d', $fechaRegistro);
    $fecha->setTime(0, 0, 0);
    $fecha->modify('+1 year');
    $fecha->modify('-1 day');
    return $fecha->format('Y-m-d');
}
