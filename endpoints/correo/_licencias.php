<?php
declare(strict_types=1);

// =====================================================================
//  Correo - Utilidades para las licencias (tipo + cantidad) de una relacion.
//  Una relacion puede cargar varias licencias, cada una con su cantidad de
//  cuentas. Estas funciones normalizan/validan la entrada, calculan el total
//  y el resumen (denormalizados en cuentas_correo) y sincronizan la tabla
//  hija public.correo_licencias. Compartidas por crear.php y actualizar.php.
// =====================================================================

// Toma el cuerpo de la peticion y devuelve las lineas de licencia normalizadas
// como [['tipo_licencia' => ?string, 'cantidad_cuentas' => int], ...].
// Acepta el formato nuevo ($in['licencias'] = [{tipo_licencia, cantidad_cuentas}])
// y, por compatibilidad, el formato antiguo (cantidad_cuentas + tipo_licencia
// sueltos) cuando no se envia 'licencias'. Corta con 422 si algo no es valido.
function correo_licencias_desde_input(array $in): array
{
    $crudas = $in['licencias'] ?? null;

    // Compatibilidad: sin 'licencias', se arma una sola linea con los campos sueltos.
    if (!is_array($crudas)) {
        $crudas = [[
            'tipo_licencia'    => $in['tipo_licencia'] ?? '',
            'cantidad_cuentas' => $in['cantidad_cuentas'] ?? null,
        ]];
    }

    $lineas = [];
    foreach ($crudas as $c) {
        if (!is_array($c)) { continue; }
        $tipo = trim((string)($c['tipo_licencia'] ?? ''));
        $cant = $c['cantidad_cuentas'] ?? null;

        // Se ignoran las lineas totalmente vacias (fila del formulario sin usar).
        if ($tipo === '' && ($cant === null || $cant === '')) { continue; }

        if (!is_numeric($cant) || (int)$cant < 1) {
            json_error('Cada licencia debe tener una cantidad de cuentas mayor o igual a 1', 422);
        }
        if (mb_strlen($tipo) > 100) {
            json_error('El tipo de licencia es demasiado largo (maximo 100 caracteres)', 422);
        }
        $lineas[] = [
            'tipo_licencia'    => $tipo !== '' ? $tipo : null,
            'cantidad_cuentas' => (int)$cant,
        ];
    }

    if (!$lineas) {
        json_error('Agrega al menos una licencia con su cantidad de cuentas', 422, ['faltantes' => ['licencias']]);
    }
    return $lineas;
}

// Total de cuentas (suma de las lineas). Se guarda en cuentas_correo.cantidad_cuentas.
function correo_licencias_total(array $lineas): int
{
    $total = 0;
    foreach ($lineas as $l) { $total += (int)$l['cantidad_cuentas']; }
    return $total;
}

// Resumen denormalizado para cuentas_correo.tipo_licencia: los tipos distintos
// (no vacios) unidos por coma, recortado a 100 chars. NULL si no hay ninguno.
function correo_licencias_resumen(array $lineas): ?string
{
    $tipos = [];
    foreach ($lineas as $l) {
        $t = $l['tipo_licencia'];
        if ($t !== null && $t !== '' && !in_array($t, $tipos, true)) { $tipos[] = $t; }
    }
    if (!$tipos) { return null; }
    return mb_substr(implode(', ', $tipos), 0, 100);
}

// Reemplaza las lineas de licencia de la relacion (borra e inserta). Debe
// ejecutarse dentro de una transaccion ya iniciada.
function correo_guardar_licencias(PDO $pdo, string $cuentaId, array $lineas): void
{
    $pdo->prepare('DELETE FROM public.correo_licencias WHERE cuenta_correo_id = :c')
        ->execute([':c' => $cuentaId]);

    $ins = $pdo->prepare(
        'INSERT INTO public.correo_licencias
            (cuenta_correo_id, tipo_licencia, cantidad_cuentas, orden)
         VALUES (:c, :t, :cant, :o)'
    );
    foreach ($lineas as $i => $l) {
        $ins->execute([
            ':c'    => $cuentaId,
            ':t'    => $l['tipo_licencia'],
            ':cant' => $l['cantidad_cuentas'],
            ':o'    => $i,
        ]);
    }
}
