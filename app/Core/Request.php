<?php

declare(strict_types=1);

namespace FindSync\Core;

final class Request
{
    /** @var array<string, mixed>|null */
    private static ?array $json = null;

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isWrite(): bool
    {
        return !in_array(self::method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public static function route(): string
    {
        return trim((string) ($_GET['route'] ?? ''), '/');
    }

    /** @return array<string, mixed> */
    public static function json(): array
    {
        if (self::$json === null) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                self::$json = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new ValidationException('Request body must be valid JSON.');
                }
                self::$json = $decoded;
            }
        }
        return self::$json;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return array_merge($_GET, $_POST, self::json());
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $header => $value) {
                if (strcasecmp($header, $name) === 0) {
                    return (string) $value;
                }
            }
        }
        return null;
    }

    public static function csrfToken(): ?string
    {
        $fromHeader = self::header('X-CSRF-Token');
        if ($fromHeader !== null && $fromHeader !== '') {
            return $fromHeader;
        }
        $fromPost = $_POST['csrf_token'] ?? null;
        return is_string($fromPost) && $fromPost !== '' ? $fromPost : null;
    }
}
