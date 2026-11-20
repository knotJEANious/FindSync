<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\ValidationException;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\ImportRepository;
use FindSync\Services\EtlService;

final class ImportController
{
    public function store(): never
    {
        AuthMiddleware::requireStaff();

        $file = $_FILES['file'] ?? $_FILES['csv_file'] ?? null;
        if (!is_array($file)) {
            throw new ValidationException('CSV file is required.');
        }

        $sourceType = $_POST['source_type'] ?? Request::input('source_type');

        Response::success(EtlService::import($file, (string) $sourceType, AuthMiddleware::userId()), 201);
    }

    public function index(): never
    {
        AuthMiddleware::requireStaff();

        Response::success(['batches' => (new ImportRepository())->all()]);
    }
}
