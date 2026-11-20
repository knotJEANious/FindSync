<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\ForbiddenException;
use FindSync\Core\NotFoundException;
use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\ValidationException;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\LostRepository;
use FindSync\Repositories\StudentRepository;
use FindSync\Services\AuditService;

final class LostController
{
    public function index(): never
    {
        $isStaff = AuthMiddleware::isStaff();
        $studentId = null;
        if (!$isStaff) {
            $studentId = AuthMiddleware::studentId();
            if ($studentId === null) {
                throw new ForbiddenException('A student profile is required.');
            }
        }
        $search = normalize_text(Request::input('search'));
        Response::success(['reports' => (new LostRepository())->allFor($studentId, $search)]);
    }

    public function store(): never
    {
        $data = Request::json();
        $category = canonical_category($data['category'] ?? null);
        $location = canonical_location($data['location'] ?? null);
        $description = normalize_text($data['description'] ?? '');
        $date = normalize_text($data['event_date'] ?? '');

        $errors = [];
        if ($category === null) {
            $errors['category'] = 'Select a valid category.';
        }
        if ($location === null) {
            $errors['location'] = 'Select a valid location.';
        }
        if (mb_strlen($description) < 5 || mb_strlen($description) > 500) {
            $errors['description'] = 'Description must be 5–500 characters.';
        }
        if (!valid_event_date($date)) {
            $errors['event_date'] = 'Use a valid date in YYYY-MM-DD format, not in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please correct the report fields.', $errors);
        }

        $isStaff = AuthMiddleware::isStaff();
        if ($isStaff) {
            // Staff act on behalf of a fictional student, which they must identify explicitly.
            $rawStudentId = $data['student_id'] ?? null;
            if (!is_scalar($rawStudentId) || !ctype_digit(trim((string) $rawStudentId))) {
                throw new ValidationException('A valid student_id is required.', [
                    'student_id' => 'Select an existing student.',
                ]);
            }
            $studentId = (int) $rawStudentId;
            if ((new StudentRepository())->findById($studentId) === null) {
                throw new NotFoundException('Student not found.');
            }
        } else {
            // Student identity always comes from the session; any client student_id is ignored.
            $studentId = AuthMiddleware::studentId();
            if ($studentId === null) {
                throw new ForbiddenException('A student profile is required.');
            }
        }

        $reports = new LostRepository();
        if ($reports->findDuplicate($studentId, $description, $date, $location)) {
            throw new ValidationException('A matching lost report already exists.');
        }

        $now = now_utc();
        $id = $reports->create([
            'student_id' => $studentId,
            'category' => $category,
            'description' => $description,
            'location' => $location,
            'event_date' => $date,
            'status' => 'open',
            'source' => $isStaff ? 'staff' : 'web',
            'created_by_user_id' => AuthMiddleware::userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditService::log('LOST_REPORT_CREATED', 'lost_report', $id, 'Lost report created in ' . $location);
        Response::success(['report' => $reports->findById($id)], 201);
    }
}
