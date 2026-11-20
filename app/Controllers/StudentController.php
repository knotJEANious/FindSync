<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\NotFoundException;
use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\ValidationException;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\StudentRepository;
use FindSync\Services\AuditService;

final class StudentController
{
    public function index(): never
    {
        AuthMiddleware::requireStaff();
        $search = normalize_text(Request::input('search'));
        Response::success(['students' => (new StudentRepository())->all($search)]);
    }

    public function store(): never
    {
        AuthMiddleware::requireStaff();
        $data = Request::json();
        $studentNumber = normalize_text($data['student_number'] ?? '');
        $fullName = normalize_text($data['full_name'] ?? '');
        $program = normalize_text($data['program'] ?? '');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9-]{3,32}$/', $studentNumber)) {
            $errors['student_number'] = 'Use 3–32 letters, numbers or hyphens.';
        }
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full name must be 3–120 characters.';
        }
        if (mb_strlen($program) < 2 || mb_strlen($program) > 120) {
            $errors['program'] = 'Program must be 2–120 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please correct the student fields.', $errors);
        }

        $students = new StudentRepository();
        if ($students->findByStudentNumber($studentNumber) !== null) {
            throw new ValidationException('That student number already exists.', [
                'student_number' => 'Already exists.',
            ]);
        }

        $id = $students->create($studentNumber, $fullName, $program);
        AuditService::log('STUDENT_CREATED', 'student', $id, 'Fictional student added');
        Response::success(['student' => $students->findById($id)], 201);
    }

    public function profile(): never
    {
        $studentId = AuthMiddleware::studentId();
        if ($studentId === null) {
            throw new NotFoundException('Student profile not found.');
        }
        $profile = (new StudentRepository())->findById($studentId);
        if ($profile === null) {
            throw new NotFoundException('Student profile not found.');
        }
        Response::success(['profile' => $profile]);
    }

    public function updateProfile(): never
    {
        $studentId = AuthMiddleware::studentId();
        if ($studentId === null) {
            throw new NotFoundException('Student profile not found.');
        }

        // student_number is intentionally not read or updated here.
        $data = Request::json();
        $fullName = normalize_text($data['full_name'] ?? '');
        $program = normalize_text($data['program'] ?? '');
        $errors = [];
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full name must be 3–120 characters.';
        }
        if (mb_strlen($program) < 2 || mb_strlen($program) > 120) {
            $errors['program'] = 'Program must be 2–120 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please correct the profile fields.', $errors);
        }

        $students = new StudentRepository();
        if ($students->findById($studentId) === null) {
            throw new NotFoundException('Student profile not found.');
        }
        $students->updateProfile($studentId, $fullName, $program);
        AuditService::log('STUDENT_PROFILE_UPDATED', 'student', $studentId, 'Fictional student profile updated');
        Response::success(['profile' => $students->findById($studentId)]);
    }
}
