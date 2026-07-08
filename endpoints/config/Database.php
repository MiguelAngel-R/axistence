<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// =====================================================================
//  Conexion PDO unica (singleton) a PostgreSQL.
//  Todas las consultas de la app se ejecutan preparadas sobre esta PDO.
// =====================================================================
final class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$instance->exec("SET client_encoding TO 'UTF8'");
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
