<?php

declare(strict_types=1);

namespace FindSync\Services;

use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\AuditRepository;

final class AuditService
{
    public static function log(string $action, string $entityType, string|int|null $entityId, string $details): void
    {
        $user = AuthMiddleware::user();
        $repository = new AuditRepository();
        $repository->insert([
            'actor_user_id' => $user === null ? null : (int) ($user['id'] ?? 0),
            'action' => safe_details($action, 80),
            'entity_type' => safe_details($entityType, 50),
            'entity_id' => $entityId === null ? null : safe_details((string) $entityId, 64),
            'details' => safe_details($details, 500),
            'created_at' => now_utc(),
        ]);
    }
}
