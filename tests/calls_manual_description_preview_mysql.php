<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\Core\Database;
use App\QueueCore\ManualSourceAuthorityService;
use App\Services\BusinessScopeContext;
use App\Services\ManualCampaignAdapterRegistry;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualCampaignService;
use App\Services\WorkQueueProjectionService;

final class CallsManualCountingPdo extends PDO
{
    public int $statementCount = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statementCount++;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statementCount++;
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->statementCount++;
        return parent::exec($statement);
    }
}

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label);
    }
    echo 'PASS:' . $label . "\n";
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $pdo->exec(
        "INSERT INTO catalog_description_jobs
         (id,catalog_id,status,total_items,current_account_id,next_run_at)
         VALUES (8801,1,'queued',4,9011,'2000-01-01')"
    );
    $item = $pdo->prepare(
        "INSERT INTO catalog_description_job_items
         (catalog_description_job_id,catalog_item_id,meli_item_id,meli_account_id,external_item_id,status)
         VALUES (8801,?,?,?,?,'pending')"
    );
    $authorizedItemIds = [];
    foreach ([
        [8811, 8821, 9011, 'MCO-C10-1'],
        [8812, 8822, 9011, 'MCO-C10-2'],
        [8813, 8823, 9011, 'MCO-C10-3'],
        [8814, 8824, 9012, 'MCO-FOREIGN'],
    ] as $params) {
        $item->execute($params);
        if ((int) $params[2] === 9011) {
            $authorizedItemIds[] = (int) $pdo->lastInsertId();
        }
    }
    (new WorkQueueProjectionService())->refreshQueue('catalog_descriptions');

    $preview = (new ManualCampaignPreviewService())->create(9007, [
        'scope' => 'descriptions',
        'account_id' => 9011,
        'physical_api_call_budget' => 1,
    ]);
    $assert(count($preview['rows']) === 3, 'description_job_expands_to_exact_authorized_children');
    $assert(
        array_column($preview['rows'], 'source_id') === array_map(
            static fn (int $itemId): string => '8801:' . $itemId,
            $authorizedItemIds,
        ),
        'description_snapshot_preserves_stable_child_ids',
    );
    $assert(
        array_column($preview['rows'], 'human_label') === [
            'Descripción de MCO-C10-1',
            'Descripción de MCO-C10-2',
            'Descripción de MCO-C10-3',
        ],
        'description_snapshot_preserves_reviewed_labels',
    );
    $assert(
        count(array_filter(
            $preview['rows'],
            static fn (array $row): bool => (int) ($row['company_id'] ?? 0) === 9001
                && (int) ($row['meli_account_id'] ?? 0) === 9011
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($row['selection_version'] ?? '')) === 1,
        )) === 3,
        'description_snapshot_is_tenant_bound_and_versioned',
    );
    $assert(
        !str_contains(implode('|', array_column($preview['rows'], 'human_label')), 'MCO-FOREIGN'),
        'description_expansion_excludes_foreign_account_child',
    );

    // Compare the old double-inspection shape with the extracted current
    // projection on the same warm fixture. This counts real PDO statements,
    // not wall-clock timings.
    $counting = new CallsManualCountingPdo(
        'mysql:host=127.0.0.1;port=33079;dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $counting->exec("SET time_zone='+00:00'");
    Database::setConnection($counting);
    $measureLegacy = static function (): void {
        $legacy = (new ManualCampaignService())->preview('descriptions', 60, 0, 0, 0, 0, 9011);
        $authority = new ManualSourceAuthorityService();
        foreach ((array) ($legacy['rows'] ?? []) as $row) {
            $queueKey = (string) ($row['queue_key'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            $accountId = (int) ($row['meli_account_id'] ?? 0);
            $companyId = (int) (new BusinessScopeContext())->account($accountId, 0, 9007)['company_id'];
            $adapter = (new ManualCampaignAdapterRegistry())->forQueue($queueKey);
            if ($adapter === null) {
                continue;
            }
            $state = $adapter->inspect($sourceId, $accountId);
            $authority->inspect($queueKey, $sourceId, $accountId, $companyId, $state);
        }
    };
    $current = new ReflectionMethod(ManualCampaignPreviewService::class, 'currentPreview');
    $service = new ManualCampaignPreviewService();
    $measureCurrent = static function () use ($current, $service): void {
        $current->invoke($service, 'descriptions', 9011, 9007);
    };
    $measureLegacy();
    $measureCurrent();
    $counting->statementCount = 0;
    $measureLegacy();
    $legacyStatements = $counting->statementCount;
    $counting->statementCount = 0;
    $measureCurrent();
    $currentStatements = $counting->statementCount;
    echo 'LEGACY_PROJECTION_SQL=' . $legacyStatements . "\n";
    echo 'CURRENT_PROJECTION_SQL=' . $currentStatements . "\n";
    $assert($currentStatements < $legacyStatements, 'current_projection_uses_fewer_sql_statements');

    echo "STATUS=PASS CALLS_MANUAL_DESCRIPTION_PREVIEW REAL_MELI_HTTP=0\n";
} finally {
    Database::setConnection($harness->pdo());
    $harness->cleanup();
}
