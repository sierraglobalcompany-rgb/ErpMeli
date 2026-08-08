<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ProfitabilityService
{
    public function summary(array $filters, int $limit = 50, int $offset = 0): array
    {
        return (new DateReportService())->preview($filters, $limit, $offset);
    }
}
