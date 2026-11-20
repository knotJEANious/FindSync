<?php

declare(strict_types=1);

namespace FindSync\Config;

final class Config
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $root): void
    {
        $env = [];
        $file = $root . DIRECTORY_SEPARATOR . '.env';

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                $env[$key] = $value;
            }
        }

        self::$values = array_merge([
            'APP_NAME' => 'FindSync',
            'APP_TIMEZONE' => 'Asia/Manila',
            'APP_DEBUG' => 'false',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'findsync_db',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'CSV_MAX_BYTES' => '2097152',
            'MATCH_THRESHOLD' => '60',
        ], $env, self::$values);

        // All database timestamps are stored in UTC. Display timezone is documented separately.
        date_default_timezone_set('UTC');
        ini_set('display_errors', self::bool('APP_DEBUG') ? '1' : '0');
        error_reporting(E_ALL);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return filter_var(self::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function displayTimezone(): string
    {
        return (string) self::get('APP_TIMEZONE', 'Asia/Manila');
    }
}
