<?php

declare(strict_types=1);

namespace FindSync\Core;

use FindSync\Config\Config;
use Throwable;

final class Response
{
    public static function success(mixed $data, int $status = 200): never
    {
        self::json(['success' => true, 'data' => $data], $status);
    }

    /** @param array<string, mixed> $errors */
    public static function error(string $code, string $message, int $status = 400, array $errors = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($errors !== []) {
            $error['errors'] = $errors;
        }
        self::json(['success' => false, 'error' => $error], $status);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function csv(string $filename, string $content): never
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $content;
        exit;
    }

    public static function exception(Throwable $e): never
    {
        if ($e instanceof AppException) {
            self::error($e->errorCode, $e->getMessage(), $e->statusCode, $e->errors);
        }

        $message = Config::bool('APP_DEBUG') ? $e->getMessage() : 'Unexpected server error.';
        self::error('INTERNAL_ERROR', $message, 500);
    }
}
