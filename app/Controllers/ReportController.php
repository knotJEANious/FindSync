<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Response;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Services\CsvExportService;

final class ReportController
{
    public function export(): never
    {
        AuthMiddleware::requireStaff();

        Response::csv(
            'findsync_unified_report_' . gmdate('Ymd_His') . '.csv',
            CsvExportService::build()
        );
    }
}
