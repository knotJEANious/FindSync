<?php

declare(strict_types=1);

namespace FindSync\Repositories;

use FindSync\Database\Database;
use PDO;

abstract class Repository
{
    protected PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }
}
