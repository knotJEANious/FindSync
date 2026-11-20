<?php

declare(strict_types=1);

if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('normalize_text')) {
    function normalize_text(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) ($value ?? ''))) ?? '';
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('valid_iso_date')) {
    function valid_iso_date(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}

if (!function_exists('valid_event_date')) {
    function valid_event_date(mixed $value): bool
    {
        if (!valid_iso_date($value)) {
            return false;
        }
        return trim((string) $value) <= gmdate('Y-m-d');
    }
}

if (!function_exists('canonical_category')) {
    function canonical_category(mixed $value): ?string
    {
        $needle = strtolower(normalize_text($value));
        foreach (FINDSYNC_CATEGORIES as $category) {
            if (strtolower($category) === $needle) {
                return $category;
            }
        }
        return null;
    }
}

if (!function_exists('canonical_location')) {
    function canonical_location(mixed $value): ?string
    {
        $needle = strtolower(normalize_text($value));
        foreach (FINDSYNC_LOCATIONS as $location) {
            if (strtolower($location) === $needle) {
                return $location;
            }
        }
        return null;
    }
}

if (!function_exists('safe_details')) {
    function safe_details(mixed $value, int $limit = 500): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($value ?? '')) ?? '';
        $text = normalize_text($text);
        return mb_substr($text, 0, $limit);
    }
}

if (!function_exists('csv_formula_escape')) {
    function csv_formula_escape(mixed $value): string
    {
        $text = (string) ($value ?? '');
        $probe = preg_replace('/^[\x00-\x20]+/', '', $text) ?? $text;
        if ($probe !== '' && str_contains('=+-@', $probe[0])) {
            return "'" . $text;
        }
        return $text;
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 120): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1) . '…';
    }
}

const FINDSYNC_CATEGORIES = ['Wallet', 'ID Card', 'Keys', 'Electronics', 'Bag', 'Umbrella', 'Clothing', 'Other'];
const FINDSYNC_LOCATIONS = ['Library', 'Cafeteria', 'Main Gate', 'Computer Laboratory', 'Gymnasium', 'Classroom', 'Hallway', 'Other'];
