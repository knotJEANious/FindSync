<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Middleware\CsrfMiddleware;
use FindSync\Services\AuthService;

final class AuthController
{
    public function csrf(): never
    {
        Response::success(['csrfToken' => CsrfMiddleware::token()]);
    }

    public function register(): never
    {
        Response::success(AuthService::register(Request::json()), 201);
    }

    public function login(): never
    {
        $data = Request::json();
        Response::success(AuthService::login((string) ($data['username'] ?? ''), (string) ($data['password'] ?? '')));
    }

    public function logout(): never
    {
        AuthService::logout();
        Response::success(['loggedOut' => true]);
    }

    public function me(): never
    {
        Response::success(AuthService::me());
    }
}
