<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiIncidentMaterializerService;
use App\Services\TechnicalRetentionCliService;
use App\Core\Database;
use Throwable;

/** Local-only bounded maintenance owned by the single Queue V4 scheduler. */
final class QueueV4CleanMaintenanceService
{
    /** @return array{materialized:int,retained:int,warnings:int} */
    public function run(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $result = ['materialized' => 0, 'retained' => 0, 'warnings' => 0];
        try {
            $outer = OuterCronHttpReceipt::retainStep(Database::connection());
            $result['outer_http_receipts_deleted'] = $outer['deleted'];
            $result['outer_http_retention_deferred'] = $outer['deferred'];
        } catch (Throwable) {
            $result['warnings']++;
        }
        try {
            $incidentService = new ApiIncidentMaterializerService();
            $materialized = $incidentService->refreshStep($limit);
            $result['materialized'] = max(0, (int) ($materialized['processed'] ?? 0));
            $result['retained'] += $incidentService->retainStep(min(100, $limit));
        } catch (Throwable) {
            $result['warnings']++;
        }
        try {
            $retention = (new TechnicalRetentionCliService())->runStep(min(100, $limit));
            $result['retained'] = max(0, (int) ($retention['processed'] ?? 0));
            $result['warnings'] += max(0, (int) ($retention['errors'] ?? 0));
        } catch (Throwable) {
            $result['warnings']++;
        }
        return $result;
    }
}
