<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use FindSync\Controllers\AuditController;
use FindSync\Controllers\AuthController;
use FindSync\Controllers\ClaimController;
use FindSync\Controllers\ClaimRequestController;
use FindSync\Controllers\DashboardController;
use FindSync\Controllers\FoundController;
use FindSync\Controllers\ImportController;
use FindSync\Controllers\LostController;
use FindSync\Controllers\MatchController;
use FindSync\Controllers\ReportController;
use FindSync\Controllers\StudentController;
use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\Router;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (Request::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

// Authentication and session identity.
$router->add('GET', 'auth/csrf', AuthController::class, 'csrf');
$router->add('POST', 'auth/register', AuthController::class, 'register');
$router->add('POST', 'auth/login', AuthController::class, 'login');
$router->add('POST', 'auth/logout', AuthController::class, 'logout', ['auth' => true]);
$router->add('GET', 'auth/me', AuthController::class, 'me');

// Dashboards.
$router->add('GET', 'dashboard', DashboardController::class, 'staff', ['auth' => true, 'roles' => ['staff']]);
$router->add('GET', 'me/dashboard', DashboardController::class, 'student', ['auth' => true, 'roles' => ['student']]);

// Student registry and profile.
$router->add('GET', 'students', StudentController::class, 'index', ['auth' => true, 'roles' => ['staff']]);
$router->add('POST', 'students', StudentController::class, 'store', ['auth' => true, 'roles' => ['staff']]);
$router->add('GET', 'me/profile', StudentController::class, 'profile', ['auth' => true, 'roles' => ['student']]);
$router->add('PATCH', 'me/profile', StudentController::class, 'updateProfile', ['auth' => true, 'roles' => ['student']]);

// Reports.
$router->add('GET', 'lost', LostController::class, 'index', ['auth' => true]);
$router->add('POST', 'lost', LostController::class, 'store', ['auth' => true]);
$router->add('GET', 'found', FoundController::class, 'index', ['auth' => true]);
$router->add('POST', 'found', FoundController::class, 'store', ['auth' => true]);

// Matching.
$router->add('GET', 'matches', MatchController::class, 'index', ['auth' => true]);

// Claims and staff verification.
$router->add('GET', 'claims', ClaimController::class, 'index', ['auth' => true]);
$router->add('POST', 'claims', ClaimRequestController::class, 'store', ['auth' => true, 'roles' => ['student']]);
$router->add('POST', 'claims/{id}/approve', ClaimController::class, 'approve', ['auth' => true, 'roles' => ['staff']]);
$router->add('POST', 'claims/{id}/reject', ClaimController::class, 'reject', ['auth' => true, 'roles' => ['staff']]);
$router->add('POST', 'claims/{id}/release', ClaimController::class, 'release', ['auth' => true, 'roles' => ['staff']]);

// ETL, exports, audit.
$router->add('POST', 'import', ImportController::class, 'store', ['auth' => true, 'roles' => ['staff']]);
$router->add('GET', 'imports', ImportController::class, 'index', ['auth' => true, 'roles' => ['staff']]);
$router->add('GET', 'reports/export', ReportController::class, 'export', ['auth' => true, 'roles' => ['staff']]);
$router->add('GET', 'audit', AuditController::class, 'index', ['auth' => true, 'roles' => ['staff']]);

try {
    $router->dispatch();
} catch (Throwable $e) {
    Response::exception($e);
}
