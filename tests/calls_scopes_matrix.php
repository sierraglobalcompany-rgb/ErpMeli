<?php
declare(strict_types=1);

/** Invoked after the HTTP negatives, reusing their one disposable schema301. */
function calls_scopes_matrix(PDO $pdo, Closure $request, Closure $login, Closure $assert, array $notification): void
{
    $sourceMap = ['notification_fallback' => [(string) $notification['source_id']]];
    $order = cap2_manual_orders($pdo, 1);
    $sourceMap['orders_sync'] = [(string) $order['source_id']];
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,status,total_items,processed_items,source_type,source_id)
        VALUES(9001,9011,'pending',1,0,'order',78103)");
    $sourceMap['financial_recalc'] = [(string) $pdo->lastInsertId()];
    $pdo->exec("INSERT INTO meli_item_sync_jobs(meli_account_id,phase,next_run_at) VALUES(9011,'discovering','2000-01-01')");
    $sourceMap['items_sync'] = [(string) $pdo->lastInsertId()];
    $pdo->exec("INSERT INTO sync_sales_audit_runs(company_id,meli_account_id,period_year,period_month,timezone_used,normalizer_version,local_from,local_to,utc_from,utc_to)
        VALUES(9001,9011,2026,9,'UTC','test','2026-09-01','2026-09-30','2026-09-01','2026-09-30')");
    $runId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO sync_sales_audit_jobs(sync_sales_audit_run_id,company_id,meli_account_id,status,next_run_at)
        VALUES($runId,9001,9011,'pending','2000-01-01')");
    $sourceMap['sales_audit'] = [(string) $pdo->lastInsertId()];
    $pdo->exec("INSERT INTO catalog_description_jobs(id,catalog_id,status,total_items,current_account_id,next_run_at)
        VALUES(8891,1,'queued',3,9011,'2000-01-01')");
    $description = $pdo->prepare("INSERT INTO catalog_description_job_items(catalog_description_job_id,catalog_item_id,meli_item_id,meli_account_id,external_item_id,status)
        VALUES(8891,?,?,?,?,'pending')");
    $sourceMap['catalog_descriptions'] = [];
    foreach ([[88911,88921,9011,'MCO-SCOPE-1'],[88912,88922,9011,'MCO-SCOPE-2'],[88913,88923,9012,'MCO-SCOPE-FOREIGN']] as $item) {
        $description->execute($item);
        if ($item[2] === 9011) $sourceMap['catalog_descriptions'][] = '8891:' . $pdo->lastInsertId();
    }
    foreach (array_keys($sourceMap) as $queue) (new App\Services\WorkQueueProjectionService())->refreshQueue($queue);
    // NotificationWorkItemService::enqueue already submits its canonical V4
    // pointer through CronAdmissionService. Reuse that real admission; adding
    // another test pointer would manufacture a duplicate and inflate counts.
    $pointer = $pdo->prepare("SELECT id FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011
        AND job_type='domain_exact' AND resource_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='notification_work_item'");
    $pointer->execute([$notification['source_id']]);
    $pointerIds = $pointer->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($pointerIds) === 1, 'scope_fixture_reuses_single_real_notification_admission');
    $pointerId = (int) $pointerIds[0];
    // Earlier negative cases deliberately retained their preview. Expire only
    // those fixture snapshots so this newly seeded catalog is freshly computed.
    $pdo->exec("UPDATE manual_campaign_previews SET expires_at='2000-01-01'");
    $scopes = [
        'recommended' => ['notification_fallback','orders_sync','financial_recalc'],
        'all' => array_keys($sourceMap),
        'sales' => ['notification_fallback','orders_sync'],
        'finance' => ['financial_recalc'],
        'audits' => ['sales_audit'],
        'products' => ['items_sync'],
        'descriptions' => ['catalog_descriptions'],
        'local' => ['financial_recalc'],
        'available_queue' => [],
    ];
    $businessHash = static function () use ($pdo): string {
        $state = [];
        foreach (['meli_notification_work_items','sync_batch_chunks','order_financial_recalc_jobs','meli_item_sync_jobs',
            'sync_sales_audit_runs','sync_sales_audit_jobs','catalog_description_jobs','catalog_description_job_items',
            'system_work_queue_projection','queue_core_jobs','queue_core_attempts','queue_v4_clean_jobs',
            'queue_v4_clean_runs','queue_v4_clean_attempts','queue_core_execution_leases','meli_tokens'] as $table) {
            $rows = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll());
            sort($rows);
            $state[$table] = $rows;
        }
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    };
    $before = $businessHash();
    $csrf = $login('admin');
    // Retired campaign engine must not be a dependency of actual preview or GET.
    $pdo->exec('RENAME TABLE manual_campaigns TO qa_retired_manual_campaigns');
    try {
        foreach ($scopes as $scope => $queues) {
            $requirements = App\Services\ManualCampaignPreviewService::schemaRequirements($scope);
            $assert(!isset($requirements['manual_campaigns']), 'scope_' . $scope . '_schema_without_legacy_campaigns');
            $page = $request('/settings/manual-processing?scope=' . $scope . '&account_id=9011');
            preg_match('/(?:[0-9]+ listos ahora|Disponibilidad no comprobada)/u', $page['body'], $availabilityLabel);
            echo 'SCOPE_DIAGNOSTIC=' . json_encode(['scope' => $scope, 'http_status' => $page['status'],
                'availability_label' => $availabilityLabel[0] ?? 'absent',
                'repository_eligible' => (new App\QueueV4Clean\QueueV4CleanRepository($pdo))->eligibleCount([9011], 9011),
                'schema_missing' => (new App\Services\SchemaInspectorService())->missingRequirements($requirements)], JSON_THROW_ON_ERROR) . PHP_EOL;
            $assert($page['status'] === 200 && str_contains($page['body'], '1 listos ahora'), 'scope_' . $scope . '_GET_shared_available_count');
            preg_match('/name="capacity_revision" value="([^"]+)"/', $page['body'], $revision);
            $response = $request('/settings/manual-processing/preview', ['_token' => $csrf, 'scope' => $scope,
                'account_id' => '9011', 'physical_api_call_budget' => '1', 'capacity_revision' => html_entity_decode($revision[1] ?? '')]);
            parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);
            $token = (string) ($query['preview'] ?? '');
            $assert($response['status'] === 302 && preg_match('/^[a-f0-9]{40}$/', $token) === 1, 'scope_' . $scope . '_actual_preview_created');
            $preview = (new App\Services\ManualCampaignPreviewService())->load($token, 9007);
            $rows = (array) $preview['rows'];
            if ($scope === 'available_queue') {
                $assert(count($rows) === 1 && (int) ($rows[0]['queue_job_id'] ?? 0) === $pointerId,
                    'scope_available_queue_exact_pointer_matches_availability');
            } else {
                $expected = [];
                foreach ($queues as $queue) foreach ($sourceMap[$queue] as $id) $expected[] = 'exact:' . $queue . ':' . $id;
                $actual = array_column($rows, 'selection_id');
                sort($expected); sort($actual);
                $assert($expected === $actual, 'scope_' . $scope . '_exact_expected_source_set');
            }
            foreach ($rows as $row) {
                $assert((int) $row['meli_account_id'] === 9011 && (int) $row['company_id'] === 9001,
                    'scope_' . $scope . '_row_tenant_bound');
            }
            $rendered = $request('/settings/manual-processing?scope=' . $scope . '&account_id=9011&preview=' . $token);
            preg_match_all('/<tr data-selection-id="/', $rendered['body'], $renderedRows);
            $assert($rendered['status'] === 200 && count($renderedRows[0]) === count($rows), 'scope_' . $scope . '_GET_persisted_row_count_parity');
            $assert(!str_contains($rendered['body'], 'MCO-SCOPE-FOREIGN'), 'scope_' . $scope . '_foreign_description_not_leaked');
            $assert(hash_equals($before, $businessHash()), 'scope_' . $scope . '_source_jobs_unchanged');
        }
        foreach (['modules','unknown'] as $invalid) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM manual_campaign_previews')->fetchColumn();
            $get = $request('/settings/manual-processing?scope=' . $invalid);
            $assert($get['status'] >= 400, 'scope_' . $invalid . '_GET_rejected');
            $post = $request('/settings/manual-processing/preview', ['_token' => $csrf, 'scope' => $invalid,
                'account_id' => '9011', 'physical_api_call_budget' => '1', 'capacity_revision' => html_entity_decode($revision[1] ?? '')]);
            $assert($post['status'] === 302 && !str_contains($post['location'], 'preview='), 'scope_' . $invalid . '_POST_no_success_token');
            $assert($count === (int) $pdo->query('SELECT COUNT(*) FROM manual_campaign_previews')->fetchColumn(), 'scope_' . $invalid . '_no_preview_insert');
            $errorPage = $request('/settings/manual-processing');
            $assert(str_contains($errorPage['body'], 'alert danger') && !str_contains($errorPage['body'], 'Cálculo terminado'), 'scope_' . $invalid . '_explicit_failure_message');
        }
    } finally {
        $pdo->exec('RENAME TABLE qa_retired_manual_campaigns TO manual_campaigns');
    }
    $assert(hash_equals($before, $businessHash()), 'scope_matrix_all_business_sources_unchanged');
    $financial = $pdo->prepare("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,status,total_items,processed_items,source_type,source_id)
        VALUES(9001,9011,'pending',1,0,'order',?)");
    for ($i = 0; $i < 60; $i++) $financial->execute([78200 + $i]);
    (new App\Services\WorkQueueProjectionService())->refreshQueue('financial_recalc');
    $pdo->exec("UPDATE manual_campaign_previews SET expires_at='2000-01-01'");
    $before = $businessHash();
    $response = $request('/settings/manual-processing/preview', ['_token' => $csrf, 'scope' => 'finance',
        'account_id' => '9011', 'physical_api_call_budget' => '1', 'capacity_revision' => html_entity_decode($revision[1] ?? '')]);
    parse_str((string) parse_url($response['location'], PHP_URL_QUERY), $query);
    $token = (string) ($query['preview'] ?? '');
    $preview = (new App\Services\ManualCampaignPreviewService())->load($token, 9007);
    $assert(count($preview['rows']) === 60 && (int) $preview['eligible_jobs'] === 61 && !empty($preview['has_more']),
        'scope_finance_61_sources_60_presentation_with_one_physical_call_budget');
    $rendered = $request('/settings/manual-processing?scope=finance&account_id=9011&preview=' . $token);
    preg_match_all('/<tr data-selection-id="/', $rendered['body'], $renderedRows);
    $assert($rendered['status'] === 200 && count($renderedRows[0]) === 60, 'scope_finance_GET_renders_all_60_confirmed_rows');
    $assert(hash_equals($before, $businessHash()), 'scope_finance_60_presentation_business_unchanged');
    clearstatcache(true, 'D:/Codex/tmp/erp-meli/calls-20260906/entrypoints/wire.jsonl');
    $assert(filesize('D:/Codex/tmp/erp-meli/calls-20260906/entrypoints/wire.jsonl') === 0, 'scope_matrix_wire_zero');
    echo "STATUS=PASS CALLS_SCOPES_MATRIX supported=9 invalid=2 REAL_HTTP_ROUTER=YES LEGACY_CAMPAIGN_TABLE=ABSENT_DURING_PREVIEWS\n";
}
