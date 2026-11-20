<?php

declare(strict_types=1);

namespace FindSync\Services;

use FindSync\Database\Database;

final class CsvExportService
{
    /** @var array<int, string> */
    private const HEADERS = [
        'Source',
        'Record ID',
        'Student ID',
        'Category',
        'Description',
        'Location',
        'Date',
        'Status',
    ];

    public static function build(): string
    {
        $pdo = Database::connection();

        $lostRows = $pdo->query(
            'SELECT l.id, s.student_number, l.category, l.description, l.location, l.event_date, l.status '
            . 'FROM lost_reports l LEFT JOIN students s ON s.id = l.student_id '
            . 'ORDER BY l.id ASC'
        )->fetchAll();

        $foundRows = $pdo->query(
            'SELECT f.id, s.student_number, f.category, f.description, f.location, f.event_date, f.status '
            . 'FROM found_items f LEFT JOIN students s ON s.id = f.finder_student_id '
            . 'ORDER BY f.id ASC'
        )->fetchAll();

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV export stream.');
        }

        try {
            // Neutralize every field (including headers) before fputcsv so cells beginning
            // with =, +, - or @ cannot be interpreted as spreadsheet formulas.
            fputcsv($handle, array_map('csv_formula_escape', self::HEADERS), ',', '"', '\\');

            foreach ($lostRows as $row) {
                fputcsv($handle, self::escapeRow([
                    'Lost',
                    $row['id'],
                    $row['student_number'],
                    $row['category'],
                    $row['description'],
                    $row['location'],
                    $row['event_date'],
                    $row['status'],
                ]), ',', '"', '\\');
            }

            foreach ($foundRows as $row) {
                fputcsv($handle, self::escapeRow([
                    'Found',
                    $row['id'],
                    $row['student_number'],
                    $row['category'],
                    $row['description'],
                    $row['location'],
                    $row['event_date'],
                    $row['status'],
                ]), ',', '"', '\\');
            }

            rewind($handle);
            $content = stream_get_contents($handle);

            return $content === false ? '' : $content;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, string>
     */
    private static function escapeRow(array $fields): array
    {
        return array_map('csv_formula_escape', $fields);
    }
}
