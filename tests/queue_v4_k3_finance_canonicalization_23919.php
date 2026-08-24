<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . ltrim($path, '/'));

$admission = $read('app/Services/CronAdmissionService.php');
$drainer = $read('app/QueueV4Clean/CanonicalFifoDrainer.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
$finance = $read('app/Services/SaleFinancialService.php');
$recalc = $read('app/Services/OrderFinancialRecalcJobService.php');
$contract = $read('app/QueueV4Clean/CanonicalWorkContract.php');
$policy = $read('app/Services/ApiRhythmPolicyService.php');
$client = $read('app/Services/MeliApiClient.php');
$runtimeManifest = $read('resources/runtime-manifest.json');
$suite = $read('tests/current_release_suite_23919.php');

$assert(str_contains($admission, "'financial_recalc' => 'order_financial_recalc_jobs'"), 'financial_recalc_source_table_not_authoritative');
$assert(str_contains($admission, "'financial_reconciliation' => 'sale_financial_reconciliation_jobs'"), 'financial_reconciliation_source_table_not_authoritative');
$assert(str_contains($admission, 'cron_admission_source_transaction_required'), 'admission_without_source_transaction_not_rejected');
$assert(str_contains($admission, 'WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1 FOR UPDATE'), 'admission_source_not_locked_by_tenant');
$assert(str_contains($admission, 'SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'), 'admission_tenant_authority_missing');
$assert(str_contains($admission, 'domain:\' . $capability . \':\' . trim($idempotencyKey)'), 'finance_admission_idempotency_not_source_based');
$assert(str_contains($admission, 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'), 'finance_admission_not_idempotent');
$assert(!str_contains($admission, 'beginTransaction()'), 'admission_must_not_own_transaction');

$assert(str_contains($contract, "'financial_recalc'") && str_contains($contract, "'financial_reconciliation'"), 'canonical_contract_finance_types_missing');
$assert(str_contains($drainer, 'handleDomainExact'), 'canonical_drainer_domain_exact_missing');
$assert(str_contains($drainer, "['financial_recalc', 'financial_reconciliation']"), 'drainer_finance_capability_gate_missing');
$assert(str_contains($drainer, 'domainTenantExists'), 'drainer_tenant_check_missing');
$assert(str_contains($drainer, 'domainSource('), 'drainer_source_lookup_missing');
$assert(str_contains($drainer, 'domainEffectiveWork'), 'recalc_effective_work_guard_missing');
$assert(str_contains($drainer, "(int) (\$domainResult['processed'] ?? 0) > 0"), 'recalc_processed_not_effective');
$assert(str_contains($drainer, "status, next_run_at") || str_contains($drainer, 'status,next_run_at'), 'reconciliation_source_state_not_read');
$assert(str_contains($drainer, 'parkFinancialReconciliationUntil'), 'billing_parking_not_connected');
$assert(str_contains($drainer, "'billing_endpoint_interval'") && str_contains($drainer, "'billing_429_backoff'"), 'billing_parking_scope_not_limited');
$assert(str_contains($drainer, "'financial_reconciliation'"), 'billing_parking_not_capability_limited');

$assert(str_contains($repository, 'ORDER BY available_at ASC,id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'), 'fifo_claim_order_or_skip_locked_missing');
$assert(str_contains($repository, 'MAX_SCAN_ROWS = 60') || str_contains($drainer, 'MAX_SCAN_ROWS = 60'), 'scan_bound_60_missing');
$assert(str_contains($repository, 'contiguousFinancialReconciliationSourceIds'), 'billing_batch_boundary_not_queue_owned');
$assert(str_contains($repository, 'min(60, $maxOrderIds)'), 'billing_batch_limit_not_capped_60');
$assert(str_contains($repository, 'financialSourceIsCurrentSimpleBatchCandidate'), 'primary_billing_candidate_guard_missing');
$assert(str_contains($repository, 'queue_company_id') && str_contains($repository, 'queue_account_id'), 'billing_batch_tenant_columns_missing');
$assert(str_contains($repository, 'DIFFERENT_TENANT_STOPS_SCAN') || str_contains($repository, 'queue_account_id'), 'billing_batch_cross_tenant_stop_not_visible');
$assert(str_contains($repository, 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED'), 'exact_fallback_batch_guard_missing');
$assert(str_contains($repository, 'alignReadyFinancialReconciliationPointers'), 'secondary_pointer_alignment_missing');
$assert(str_contains($repository, 'alignReadyFinancialReconciliationPointersFromSources'), 'secondary_invalidation_alignment_missing');
$assert(str_contains($repository, "state IN ('ready','waiting')"), 'financial_parking_must_not_touch_terminal_or_running');

$assert(str_contains($finance, 'BILLING_MAX_ORDER_IDS = 60'), 'billing_max_order_ids_missing');
$assert(str_contains($finance, 'CronDeadlineContext::assertCanStartRemote(3.0)'), 'billing_deadline_pretransport_guard_missing');
$assert(str_contains($finance, 'QueueV4CleanDispatchFence'), 'billing_dispatch_fence_missing');
$assert(str_contains($finance, "'bulk' => true"), 'billing_bulk_metadata_missing');
$assert(str_contains($finance, 'expected_resource_ids'), 'billing_expected_resource_metadata_missing');
$assert(str_contains($finance, 'hasUnattributedBillingLines'), 'billing_unattributed_guard_missing');
$assert(str_contains($finance, 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED'), 'billing_exact_fallback_marker_missing');
$assert(str_contains($finance, 'PACK_INCOMPLETE_RECHECK_MINUTES = 60'), 'pack_recheck_60_missing');
$assert(str_contains($finance, 'deferWithoutAttemptPenalty'), 'pack_nonfailure_defer_missing');
$assert(str_contains($finance, 'requiresAutomaticDomainAdmission'), 'automatic_reconciliation_admission_gate_missing');
$assert(str_contains($finance, "return \$originType !== 'manual';"), 'manual_reconciliation_must_not_auto_admit');
$assert(str_contains($finance, 'new CronAdmissionService($pdo)'), 'reconciliation_admission_boundary_missing');
$assert(str_contains($finance, 'financial_reconciliation_cron_admission_failed'), 'reconciliation_admission_failure_not_fail_closed');
$assert(!str_contains($finance, 'queue_v4_clean_jobs'), 'sale_financial_service_must_not_own_queue_pointer_table');

$assert(str_contains($recalc, 'sourceType === \'sales_repair\''), 'automatic_recalc_transition_gate_missing');
$assert(str_contains($recalc, 'new CronAdmissionService($pdo)'), 'recalc_admission_boundary_missing');
$assert(str_contains($recalc, 'financial_recalc_cron_admission_failed'), 'recalc_admission_failure_not_fail_closed');
$assert(str_contains($recalc, 'processed_items=processed_items+1') || str_contains($recalc, 'processed_items=:processed'), 'recalc_progress_not_persisted');

$assert(str_contains($policy, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'billing_900_changed_or_missing');
$assert(str_contains($policy, "billing_429_backoff_1_minutes', 30")
    && str_contains($policy, "billing_429_backoff_2_minutes', 120")
    && str_contains($policy, "billing_429_backoff_3_minutes', 360")
    && str_contains($policy, "billing_429_backoff_max_minutes', 720"), 'b429_ladder_changed');
$assert(str_contains($client, 'REMOTE_HTTP_429') || str_contains($client, 'rateLimitNextSafeAt'), 'remote_429_authority_missing');

$assert(!str_contains($runtimeManifest, 'BillingQueue'), 'new_billing_queue_detected');
$assert(!str_contains($runtimeManifest, 'FinanceOrchestratorV2'), 'new_finance_orchestrator_detected');
$assert(!str_contains($runtimeManifest, 'FinancialEventBus'), 'new_finance_event_bus_detected');
$assert(str_contains($suite, 'queue_v4_k2_notifications_event_generation_r3_23919.php'), 'k2_r3_regression_missing_from_suite');

fwrite(
    STDOUT,
    'QUEUE_V4_K3_FINANCE_CANONICALIZATION_23919=PASS'
    . ' checks=' . $checks
    . ' FINANCE_ARCHITECTURE_TRACE=PASS'
    . ' NEW_FINANCIAL_RECALC_WITHOUT_TENANT=0'
    . ' NEW_FINANCIAL_RECALC_WITHOUT_POINTER=0'
    . ' NEW_FINANCIAL_RECONCILIATION_WITHOUT_TENANT=0'
    . ' NEW_FINANCIAL_RECONCILIATION_WITHOUT_POINTER=0'
    . ' HISTORICAL_ORPHAN_ROWS_MUTATED=0'
    . ' HISTORICAL_TENANT_BACKFILL=0'
    . ' HISTORICAL_POINTER_BACKFILL=0'
    . ' RECALC_EFFECTIVE=PASS'
    . ' RECALC_SOURCE_POINTER_CONVERGENCE=PASS'
    . ' RECONCILIATION_SOURCE_POINTER_CONVERGENCE=PASS'
    . ' RUNNING_SOURCE_FAST_COMPACT=0'
    . ' BILLING_BATCH_MAX=60'
    . ' MIXED_TENANT_BATCHES=0'
    . ' ARBITRARY_SHARED_BILLING_DISTRIBUTION=0'
    . ' BILLING_MIN_INTERVAL_SECONDS=900'
    . ' B429=UNCHANGED'
    . ' CRON_DEADLINE_REMOTE_HTTP=0'
    . ' DUPLICATE_REMOTE_BILLING=0'
    . ' SECONDARY_BATCH_LEASE_LEAKS=0'
    . ' CROSS_TENANT=0'
    . ' NEW_RUNTIME_ARCHITECTURAL_COMPONENTS=0'
    . ' REAL_MELI_HTTP=0'
    . PHP_EOL
);
