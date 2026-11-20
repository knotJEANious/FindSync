<?php

declare(strict_types=1);

namespace FindSync\Services;

use FindSync\Config\Config;
use FindSync\Core\ConflictException;
use FindSync\Core\ValidationException;
use FindSync\Database\Database;
use FindSync\Repositories\FoundRepository;
use FindSync\Repositories\ImportRepository;
use FindSync\Repositories\LostRepository;
use FindSync\Repositories\StudentRepository;
use PDOException;
use RuntimeException;

final class EtlService
{
    private const REQUIRED_HEADERS = [
        'studentid' => 'studentId',
        'category' => 'category',
        'description' => 'description',
        'location' => 'location',
        'date' => 'date',
    ];

    /**
     * @param array<string, mixed> $file A single $_FILES entry.
     * @return array<string, mixed>
     */
    public static function import(array $file, string $sourceType, int $actorId): array
    {
        if ($sourceType !== 'found' && $sourceType !== 'lost') {
            throw new ValidationException('Invalid CSV source type.');
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException('CSV upload failed. Please try again.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
            throw new ValidationException('CSV upload has no readable temporary file.');
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = Config::int('CSV_MAX_BYTES');
        if ($size <= 0 || $size > $maxBytes) {
            throw new ValidationException('CSV file must be non-empty and no larger than ' . $maxBytes . ' bytes.');
        }

        $filename = safe_details((string) ($file['name'] ?? 'upload.csv'), 255);
        if ($filename === '') {
            $filename = 'upload.csv';
        }

        // Uploaded files are only read through fopen(); they are never executed or moved by ETL.
        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            throw new ValidationException('Unable to open CSV file for reading.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false || $header === null || $header === []) {
                throw new ValidationException('CSV file is empty or has no header row.');
            }

            // Strip a UTF-8 BOM from the first header cell before matching column names.
            $firstHeader = $header[0];
            if (is_string($firstHeader)) {
                $header[0] = str_replace("\xEF\xBB\xBF", '', $firstHeader);
            }

            $columns = [];
            foreach ($header as $index => $name) {
                $normalized = strtolower(normalize_text($name));
                if (isset(self::REQUIRED_HEADERS[$normalized]) && !isset($columns[$normalized])) {
                    $columns[$normalized] = (int) $index;
                }
            }

            $missing = [];
            foreach (self::REQUIRED_HEADERS as $key => $logicalName) {
                if (!isset($columns[$key])) {
                    $missing[] = $logicalName;
                }
            }
            if ($missing !== []) {
                throw new ValidationException('Missing required CSV header(s): ' . implode(', ', $missing) . '.');
            }

            $students = new StudentRepository();
            $found = new FoundRepository();
            $lost = new LostRepository();
            $imports = new ImportRepository();

            // POLICY: each data row is validated independently. Every accepted row is committed
            // in ONE batch transaction together with the import_batches row and all import_errors
            // rows. Rejected rows are recorded with a reason and do not abort the remaining rows.
            // If any PDO operation fails, the whole transaction is rolled back so no partial
            // batch, rows, or errors are persisted.

            $accepted = [];
            $errors = [];
            $lineNumber = 1; // The header occupies CSV line 1.

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;
                if ($row === [null] || $row === ['']) {
                    continue;
                }

                $result = self::validateRow($row, $columns, $students, $found, $lost, $sourceType, $accepted);
                if ($result['reasons'] !== []) {
                    $errors[] = [
                        'row_number' => $lineNumber,
                        'reason' => 'Row ' . $lineNumber . ': ' . implode('; ', $result['reasons']),
                    ];
                    continue;
                }

                $record = $result['record'];
                $record['row_number'] = $lineNumber;
                $accepted[] = $record;
            }

            $totalRows = count($accepted) + count($errors);
            $acceptedRows = count($accepted);
            $rejectedRows = count($errors);
            $batchId = 0;
            $transactionActive = false;

            try {
                Database::begin();
                $transactionActive = true;

                $batchId = $imports->createBatch([
                    'source_type' => $sourceType,
                    'filename' => $filename,
                    'total_rows' => $totalRows,
                    'accepted_rows' => $acceptedRows,
                    'rejected_rows' => $rejectedRows,
                    'created_by_user_id' => $actorId,
                    'created_at' => now_utc(),
                ]);

                foreach ($accepted as $record) {
                    $now = now_utc();
                    if ($sourceType === 'found') {
                        $found->create([
                            'finder_student_id' => $record['student_id'],
                            'category' => $record['category'],
                            'description' => $record['description'],
                            'location' => $record['location'],
                            'event_date' => $record['event_date'],
                            'status' => 'available',
                            'source' => 'csv',
                            'created_by_user_id' => $actorId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $lost->create([
                            'student_id' => $record['student_id'],
                            'category' => $record['category'],
                            'description' => $record['description'],
                            'location' => $record['location'],
                            'event_date' => $record['event_date'],
                            'status' => 'open',
                            'source' => 'csv',
                            'created_by_user_id' => $actorId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                foreach ($errors as $error) {
                    $imports->addError($batchId, $error['row_number'], $error['reason']);
                }

                Database::commit();
                $transactionActive = false;
            } catch (PDOException $e) {
                if ($transactionActive) {
                    Database::rollBack();
                }
                throw new ConflictException('CSV import could not be completed. No changes were saved.', 'ETL_IMPORT_FAILED');
            } catch (RuntimeException $e) {
                if ($transactionActive) {
                    Database::rollBack();
                }
                throw new ConflictException('CSV import could not be completed. No changes were saved.', 'ETL_IMPORT_FAILED');
            }

            AuditService::log(
                'ETL_IMPORT',
                'import_batch',
                $batchId,
                $sourceType . ': ' . $acceptedRows . ' loaded, ' . $rejectedRows . ' rejected'
            );

            return [
                'batch_id' => $batchId,
                'source_type' => $sourceType,
                'filename' => $filename,
                'total_rows' => $totalRows,
                'accepted_rows' => $acceptedRows,
                'rejected_rows' => $rejectedRows,
                'errors' => $errors,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, int> $columns
     * @param array<int, array<string, mixed>> $accepted
     * @return array{reasons: array<int, string>, record: array<string, mixed>|null}
     */
    private static function validateRow(
        array $row,
        array $columns,
        StudentRepository $students,
        FoundRepository $found,
        LostRepository $lost,
        string $sourceType,
        array $accepted
    ): array {
        $reasons = [];

        $studentNumber = normalize_text($row[$columns['studentid']] ?? '');
        $student = $studentNumber === '' ? null : $students->findByStudentNumber($studentNumber);
        if ($student === null) {
            $reasons[] = 'unknown student ID';
        }

        $category = canonical_category($row[$columns['category']] ?? '');
        if ($category === null) {
            $reasons[] = 'invalid category';
        }

        $location = canonical_location($row[$columns['location']] ?? '');
        if ($location === null) {
            $reasons[] = 'invalid location';
        }

        $description = normalize_text($row[$columns['description']] ?? '');
        if ($description === '') {
            $reasons[] = 'missing description';
        }

        $date = normalize_text($row[$columns['date']] ?? '');
        if (!valid_event_date($date)) {
            $reasons[] = 'invalid date (YYYY-MM-DD required, not in the future)';
        }

        if ($reasons !== []) {
            return ['reasons' => $reasons, 'record' => null];
        }

        $studentId = (int) $student['id'];
        $category = (string) $category;
        $location = (string) $location;

        $isDuplicate = $sourceType === 'found'
            ? $found->findDuplicate($studentId, $description, $date, $location)
            : $lost->findDuplicate($studentId, $description, $date, $location);

        if (!$isDuplicate) {
            foreach ($accepted as $record) {
                if ($record['student_id'] === $studentId
                    && $record['description'] === $description
                    && $record['location'] === $location
                    && $record['event_date'] === $date) {
                    $isDuplicate = true;
                    break;
                }
            }
        }

        if ($isDuplicate) {
            return ['reasons' => ['duplicate record'], 'record' => null];
        }

        return [
            'reasons' => [],
            'record' => [
                'student_id' => $studentId,
                'category' => $category,
                'description' => $description,
                'location' => $location,
                'event_date' => $date,
            ],
        ];
    }
}
