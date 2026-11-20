<?php

declare(strict_types=1);

namespace FindSync\Database;

use FindSync\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST'),
                Config::get('DB_PORT'),
                Config::get('DB_NAME')
            );

            try {
                self::$pdo = new PDO($dsn, (string) Config::get('DB_USER'), (string) Config::get('DB_PASS'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                self::$pdo->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                $message = Config::bool('APP_DEBUG') ? $e->getMessage() : 'Database connection failed. Check .env and XAMPP MySQL.';
                throw new RuntimeException($message, 0, $e);
            }
        }

        return self::$pdo;
    }

    public static function begin(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }
}
