<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\ForbiddenException;
use FindSync\Core\NotFoundException;
use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\ValidationException;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\FoundRepository;
use FindSync\Repositories\StudentRepository;
use FindSync\Services\AuditService;

final class FoundController
{
    public function index(): never
    {
        $isStaff = AuthMiddleware::isStaff();
        $finderStudentId = null;
        if (!$isStaff) {
            $finderStudentId = AuthMiddleware::studentId();
            if ($finderStudentId === null) {
                throw new ForbiddenException('A student profile is required.');
            }
        }
        $search = normalize_text(Request::input('search'));
        Response::success(['items' => (new FoundRepository())->allFor($finderStudentId, $search)]);
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
            throw new ValidationException('Please correct the item fields.', $errors);
        }

        $isStaff = AuthMiddleware::isStaff();
        $finderStudentId = null;
        if ($isStaff) {
            // Staff may omit student_id to record an anonymous finder (finder_student_id = NULL).
            $rawStudentId = $data['student_id'] ?? null;
            if ($rawStudentId !== null && $rawStudentId !== '') {
                if (!is_scalar($rawStudentId) || !ctype_digit(trim((string) $rawStudentId))) {
                    throw new ValidationException('A valid student_id is required.', [
                        'student_id' => 'Select an existing student.',
                    ]);
                }
                $finderStudentId = (int) $rawStudentId;
                if ((new StudentRepository())->findById($finderStudentId) === null) {
                    throw new NotFoundException('Student not found.');
                }
            }
        } else {
            // Student identity always comes from the session; any client student_id is ignored.
            $finderStudentId = AuthMiddleware::studentId();
            if ($finderStudentId === null) {
                throw new ForbiddenException('A student profile is required.');
            }
        }

        $items = new FoundRepository();
        if ($items->findDuplicate($finderStudentId, $description, $date, $location)) {
            throw new ValidationException('A matching found item already exists.');
        }

        $now = now_utc();
        $id = $items->create([
            'finder_student_id' => $finderStudentId,
            'category' => $category,
            'description' => $description,
            'location' => $location,
            'event_date' => $date,
            'status' => 'available',
            'source' => $isStaff ? 'staff' : 'web',
            'created_by_user_id' => AuthMiddleware::userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AuditService::log('FOUND_ITEM_CREATED', 'found_item', $id, 'Found item recorded in ' . $location);
        Response::success(['item' => $items->findById($id)], 201);
    }
}
