<?php
declare(strict_types=1);

// =====================================================================
//  AXISTENCE - Cargador de variables de entorno desde un .env (sin librerias).
//
//  Lee un archivo de formato CLAVE=valor y lo inyecta al entorno del proceso
//  (putenv + $_ENV + $_SERVER) para que getenv() lo vea en TODO el codigo:
//  tanto en los endpoints (via config.php) como en el front controller
//  (index.php, que renderiza las vistas que leen getenv()).
//
//  Es la MISMA fuente de verdad que consume el server Node
//  (node --env-file=<mismo archivo> index.js), asi PHP y Node comparten un
//  UNICO .env con todas las variables. Cada runtime usa las que le tocan e
//  ignora el resto.
//
//  Ubicacion del archivo (se usa el primero que exista y sea legible):
//    1. La ruta en la variable de entorno AXISTENCE_ENV_FILE (si el proceso
//       ya la trae, p. ej. puesta por Apache/systemd).
//    2. /etc/axistence/axistence.env   -> PRODUCCION (fuera del web root).
//    3. <raiz-del-repo>/.env           -> DESARROLLO local (NO subir al repo;
//                                         .gitignore + .htaccess lo protegen).
//
//  Reglas de parseo:
//    - Lineas vacias y las que empiezan por '#' se ignoran.
//    - Se admiten comillas simples o dobles envolviendo el valor.
//    - Las variables que YA existen en el entorno real NO se sobreescriben
//      (asi systemd/Apache pueden forzar un valor por encima del .env).
//
//  Se ejecuta como IIFE para no dejar variables sueltas en el scope que lo
//  incluye (config.php / index.php).
// =====================================================================

(static function (): void {
    // 1) Resolver la ruta del archivo .env.
    $candidatos = [];
    $explicito = getenv('AXISTENCE_ENV_FILE');
    if ($explicito !== false && $explicito !== '') {
        $candidatos[] = $explicito;
    }
    $candidatos[] = '/etc/axistence/axistence.env';
    $candidatos[] = dirname(__DIR__, 2) . '/.env'; // endpoints/config -> raiz del repo

    $ruta = null;
    foreach ($candidatos as $c) {
        if (is_file($c) && is_readable($c)) {
            $ruta = $c;
            break;
        }
    }
    if ($ruta === null) {
        return; // Sin .env: se usan los defaults de config.php o el entorno real.
    }

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) {
        return;
    }

    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') {
            continue;
        }
        $pos = strpos($linea, '=');
        if ($pos === false) {
            continue; // linea sin '=' -> no es una asignacion
        }
        $clave = trim(substr($linea, 0, $pos));
        $valor = trim(substr($linea, $pos + 1));
        if ($clave === '') {
            continue;
        }
        // Quitar comillas envolventes (simples o dobles) si las hay.
        $n = strlen($valor);
        if ($n >= 2
            && (($valor[0] === '"' && $valor[$n - 1] === '"')
             || ($valor[0] === "'" && $valor[$n - 1] === "'"))) {
            $valor = substr($valor, 1, -1);
        }
        // No pisar lo que ya venga del entorno real (tiene prioridad).
        if (getenv($clave) !== false) {
            continue;
        }
        putenv($clave . '=' . $valor);
        $_ENV[$clave]    = $valor;
        $_SERVER[$clave] = $valor;
    }
})();
