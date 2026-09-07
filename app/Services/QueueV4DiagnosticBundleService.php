<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use App\Services\AutomationCallBudgetService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use ZipArchive;

final class QueueV4DiagnosticBundleService
{
    private const ROOT = 'queue-v4-diagnostics';
    private const BASE_RECEIPT_DIR = 'queue-v4-audit';
    private const DEBUG_MINUTES = [0, 15, 30, 60];
    private const SIGNED_URL_TTL_SECONDS = 1800;

    /** @var array<string,mixed> */
    private array $lastStale429Reconciliation = ['ran' => false, 'status' => 'NOT_RUN'];

    /** @return array<string,mixed> */
    public function status(): array
    {
        $this->cleanup();
        $queue = $this->queueCurrent();
        return [
            'ok' => true,
            'generated_at' => $this->now(),
            'debug' => $this->debugConfig(),
            'latest_bundle' => $this->latestBundle(),
            'queue' => [
                'control' => $queue['control'] ?? [],
                'counts' => $queue['counts'] ?? [],
            ],
            'base_receipts' => $this->baseReceiptStatus(),
            'retention' => [
                'base_receipts_days' => 14,
                'debug_artifacts_hours' => 48,
                'generated_zips_hours' => 24,
                'signed_download_minutes' => 30,
            ],
            'ssh_required_for_normal_audit' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function setDebugMinutes(int $minutes): array
    {
        if (!in_array($minutes, self::DEBUG_MINUTES, true)) {
            $minutes = 0;
        }

        $config = [
            'enabled' => $minutes > 0,
            'minutes' => $minutes,
            'enabled_at' => $minutes > 0 ? $this->now() : null,
            'expires_at' => $minutes > 0 ? gmdate('Y-m-d H:i:s', time() + ($minutes * 60)) : null,
            'mode' => $minutes > 0 ? 'extended_safe' : 'off',
            'stores_secrets' => false,
            'stores_raw_payloads' => false,
            'schema_changed' => false,
        ];
        $this->writeJsonFile($this->debugConfigPath(), $config);

        return [
            'ok' => true,
            'debug' => $this->debugConfig(),
        ];
    }

    /** @return array<string,mixed> */
    public function generateBundle(): array
    {
        $this->cleanup();
        $stamp = gmdate('Ymd_His');
        $base = $this->bundleDir($stamp);
        $this->ensureDir($base);

        $debug = $this->debugConfig();
        $diagnosticWarnings = [];
        $files = [];
        $this->lastStale429Reconciliation = $this->reconcileStaleBilling429BackoffState();
        $payloads = [
            '01_RUNTIME.json' => $this->diagnosticSection('runtime', fn (): array => $this->runtime(), $diagnosticWarnings),
            '02_QUEUE_CURRENT.json' => $this->diagnosticSection('queue_current', fn (): array => $this->queueCurrent(), $diagnosticWarnings),
            '03_WAITING_CURRENT.json' => $this->diagnosticSection('waiting_current', fn (): array => $this->waitingCurrent(), $diagnosticWarnings),
            '05_REVIEW_CURRENT.json' => $this->diagnosticSection('review_current', fn (): array => $this->reviewCurrent(), $diagnosticWarnings),
            '06_CRON_HEALTH.json' => $this->diagnosticSection('cron_health', fn (): array => $this->cronHealth(), $diagnosticWarnings),
            '10_PACK_PIPELINE.json' => $this->diagnosticSection('pack_pipeline', fn (): array => $this->packPipeline(), $diagnosticWarnings),
            '11_PACK_SOURCE_COVERAGE.json' => $this->diagnosticSection('pack_source_coverage', fn (): array => $this->packSourceCoverage(), $diagnosticWarnings),
            '12_FINANCE_PIPELINE.json' => $this->diagnosticSection('finance_pipeline', fn (): array => $this->financePipeline(), $diagnosticWarnings),
            '13_API_OUTCOMES.json' => $this->diagnosticSection('api_outcomes', fn (): array => $this->apiOutcomes(), $diagnosticWarnings),
            '14_THROUGHPUT.json' => $this->diagnosticSection('throughput', fn (): array => $this->throughput(), $diagnosticWarnings),
            '15_METRIC_DEFINITIONS.json' => $this->diagnosticSection('metric_definitions', fn (): array => $this->metricDefinitions(), $diagnosticWarnings),
        ];
        $payloads = ['00_SUMMARY.json' => $this->summaryFromPayloads($debug, $payloads, $diagnosticWarnings)] + $payloads;
        $payloads['16_DEBUG_CONFIG.json'] = $debug + ['diagnostic_warnings' => $diagnosticWarnings];

        foreach ($payloads as $name => $payload) {
            $this->writeJsonFile($base . '/' . $name, $payload);
            $files[] = $name;
        }

        $this->writeCsvFile($base . '/04_WAITING_CURRENT.csv', $this->diagnosticRows('waiting_current_csv', fn (): array => $this->waitingRowsForCsv(), $diagnosticWarnings));
        $files[] = '04_WAITING_CURRENT.csv';
        $this->writeJsonlFile($base . '/07_CRON_RECEIPTS.jsonl', $this->diagnosticRows('cron_receipts', fn (): array => $this->cronReceiptLines(), $diagnosticWarnings));
        $files[] = '07_CRON_RECEIPTS.jsonl';
        $this->writeJsonlFile($base . '/08_QUEUE_ATTEMPTS.jsonl', $this->diagnosticRows('queue_attempts', fn (): array => $this->queueAttemptLines(), $diagnosticWarnings));
        $files[] = '08_QUEUE_ATTEMPTS.jsonl';
        $this->writeJsonlFile($base . '/09_TRANSPORT_EVENTS.jsonl', $this->diagnosticRows('transport_events', fn (): array => $this->transportEventLines(), $diagnosticWarnings));
        $files[] = '09_TRANSPORT_EVENTS.jsonl';

        $manifestFiles = array_merge($files, ['17_MANIFEST.json', '18_SHA256SUMS.txt']);
        $manifest = [
            'generated_at_utc' => $this->now(),
            'bundle_version' => 'Queue V4 Diagnostic Bundle V1',
            'production_version_expected' => '2.40.1',
            'schema_changed' => false,
            'new_queue' => false,
            'new_cron' => false,
            'manual_cron_runs' => 0,
            'real_meli_http_by_diagnostic' => 0,
            'sanitization' => [
                'secrets' => 'excluded',
                'tokens' => 'excluded',
                'raw_payloads' => 'excluded',
                'business_ids' => 'stable_sha256_hash_only',
            ],
            'diagnostic_warnings' => $diagnosticWarnings,
            'section_failure_can_abort_bundle' => false,
            'bundle_generates_partial' => true,
            'files' => $manifestFiles,
        ];
        $this->writeJsonFile($base . '/17_MANIFEST.json', $manifest);
        $files[] = '17_MANIFEST.json';

        $shaLines = [];
        foreach ($files as $file) {
            $shaLines[] = hash_file('sha256', $base . '/' . $file) . '  ' . $file;
        }
        sort($shaLines);
        file_put_contents($base . '/18_SHA256SUMS.txt', implode("\n", $shaLines) . "\n", LOCK_EX);
        $files[] = '18_SHA256SUMS.txt';

        $zipPath = $this->zipDir() . '/queue-v4-diagnostic-' . $stamp . '.zip';
        $this->zipDirectory($base, $zipPath, $files);
        $token = $this->createDownloadToken($zipPath);

        $latest = [
            'created_at' => $this->now(),
            'zip_path' => $zipPath,
            'zip_sha256' => hash_file('sha256', $zipPath),
            'zip_bytes' => filesize($zipPath) ?: 0,
            'signed_url' => $token['url'],
            'signed_url_expires_at' => $token['expires_at'],
            'manifest_files' => count($files),
        ];
        $this->writeJsonFile($this->latestBundlePath(), $latest);

        return [
            'ok' => true,
            'bundle' => $latest,
        ];
    }

    /** @return array<string,mixed> */
    private function reconcileStaleBilling429BackoffState(): array
    {
        $result = [
            'ran' => true,
            'status' => 'SKIPPED',
            'policy' => 'P1_STALE_12H_BILLING_429_BACKOFF_RECONCILE',
            'production_changed' => 'NO',
            'real_meli_http_by_reconciliation' => 0,
            'manual_cron_runs' => 0,
            'original_429_at_utc' => '2026-08-31 07:55:28',
            'old_buggy_backoff_until_utc' => '2026-08-31 19:55:29',
            'corrected_backoff_until_utc' => '2026-08-31 08:10:29',
            'current_duplicate_429_at_utc' => '2026-08-31 19:56:24',
            'current_duplicate_backoff_until_utc' => '2026-08-31 20:26:25',
            'current_corrected_backoff_until_utc' => '2026-08-31 20:11:25',
            'current_duplicate_backoff_can_be_released' => 'NO',
            'newer_real_billing_429_found' => null,
            'explicit_retry_after_active' => null,
            'newer_real_429_after_original' => null,
            'original_retry_after_seconds' => null,
            'billing_429_backoff_active' => null,
            'stale_penalties_expired' => 0,
            'finance_waiting_rows_released' => 0,
            'stale_429_queue_pointers_found' => 0,
            'stale_429_queue_pointers_released' => 0,
            'stale_429_queue_pointers_skipped' => 0,
            'first_false_predicate' => null,
            'representative_predicates' => [],
            'future_rows_touched' => 0,
            'review_rows_touched' => 0,
            'running_rows_touched' => 0,
            'duplicate_pointers_created' => 0,
            'hard_stop_reason' => null,
        ];

        try {
            $versionFile = is_file(AppPaths::releaseRoot() . '/VERSION')
                ? trim((string) file_get_contents(AppPaths::releaseRoot() . '/VERSION'))
                : 'NOT_FOUND';
            if ($versionFile !== '2.40.1') {
                $result['hard_stop_reason'] = 'version_file_not_2401:' . $versionFile;
                return $result;
            }

            $billing429 = (new ApiRhythmPolicyService())->billing429BackoffDiagnostic();
            $result['billing_429_backoff_active'] = !empty($billing429['backoff_active']) ? 'YES' : 'NO';
            $result['billing_429_backoff_until'] = $billing429['backoff_until'] ?? null;
            $currentBackoffUntil = (string) ($billing429['backoff_until'] ?? '');
            $currentBackoffDuplicateActive = !empty($billing429['backoff_active'])
                && str_starts_with($currentBackoffUntil, '2026-08-31 20:26:25');
            if (!empty($billing429['backoff_active']) && !$currentBackoffDuplicateActive) {
                $result['hard_stop_reason'] = 'current_billing_429_backoff_still_active';
                return $result;
            }

            $newer429 = $this->countBilling429After('2026-08-31 19:56:30');
            $result['newer_real_billing_429_found'] = $newer429 > 0 ? 'YES' : 'NO';
            $result['newer_real_429_after_original'] = $newer429;
            if ($newer429 > 0) {
                $result['hard_stop_reason'] = 'newer_real_429_present';
                return $result;
            }

            $retryAfter = $this->maxBillingRetryAfterBetween('2026-08-31 19:56:23', '2026-08-31 19:56:27');
            $result['original_retry_after_seconds'] = $retryAfter;
            $result['explicit_retry_after_active'] = $retryAfter > 900 ? 'YES' : 'NO';
            if ($retryAfter > 900) {
                $result['hard_stop_reason'] = 'original_retry_after_longer_than_corrected_policy';
                return $result;
            }
            $result['current_duplicate_backoff_can_be_released'] = 'YES';

            $pdo = $this->pdo();
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $diagnosis = $this->staleBilling429FinancePointerDiagnosis('2026-08-31 20:26:24', '2026-08-31 20:26:26.999');
                $result['stale_429_queue_pointers_found'] = (int) ($diagnosis['found'] ?? 0);
                $result['stale_429_queue_pointers_skipped'] = (int) ($diagnosis['skipped'] ?? 0);
                $result['first_false_predicate'] = $diagnosis['first_false_predicate'] ?? null;
                $result['representative_predicates'] = $diagnosis['predicates'] ?? [];
                $result['stale_penalties_expired'] = $this->expireStaleBilling429Penalties('2026-08-31 20:26:24', '2026-08-31 20:26:26.999');
                $result['finance_waiting_rows_released'] = $this->releaseStaleBilling429FinanceWaitingRows('2026-08-31 20:26:24', '2026-08-31 20:26:26.999');
                $result['stale_429_queue_pointers_released'] = (int) $result['finance_waiting_rows_released'];
                $result['stale_429_queue_pointers_skipped'] = max(0, (int) $result['stale_429_queue_pointers_found'] - (int) $result['stale_429_queue_pointers_released']);
                if ($ownTransaction) {
                    $pdo->commit();
                }
                $result['production_changed'] = ((int) $result['stale_penalties_expired'] > 0 || (int) $result['finance_waiting_rows_released'] > 0) ? 'YES' : 'NO';
                $result['status'] = 'PASS';
                return $result;
            } catch (Throwable $error) {
                if ($ownTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } catch (Throwable $error) {
            $result['status'] = 'ERROR';
            $result['hard_stop_reason'] = $error::class . ':' . $error->getMessage();
            $result['production_changed'] = 'NO_OR_ROLLED_BACK';
            return $result;
        }
    }

    private function countBilling429After(string $utc): int
    {
        $total = 0;
        if ($this->hasTable('api_request_logs')) {
            $cols = $this->columns('api_request_logs');
            if (in_array('endpoint_path', $cols, true) && in_array('http_status', $cols, true) && in_array('created_at', $cols, true)) {
                $remoteClause = in_array('reached_remote', $cols, true) ? ' AND `reached_remote`=1' : '';
                $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM `api_request_logs` WHERE `endpoint_path`=? AND `http_status`=429' . $remoteClause . ' AND `created_at`>?');
                $stmt->execute(['/billing/integration/group/ML/order/details', $utc]);
                $total += (int) $stmt->fetchColumn();
            }
        }
        if ($this->hasTable('api_remote_permits')) {
            $cols = $this->columns('api_remote_permits');
            if (in_array('endpoint_key', $cols, true) && in_array('http_status', $cols, true) && in_array('dispatched_at', $cols, true)) {
                $timeExpr = in_array('completed_at', $cols, true) ? 'COALESCE(`completed_at`,`dispatched_at`)' : '`dispatched_at`';
                $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM `api_remote_permits` WHERE `endpoint_key`=? AND `http_status`=429 AND `dispatched_at` IS NOT NULL AND ' . $timeExpr . '>?');
                $stmt->execute(['billing_orders', $utc]);
                $total += (int) $stmt->fetchColumn();
            }
        }
        return $total;
    }

    private function maxBillingRetryAfterBetween(string $fromUtc, string $toUtc): int
    {
        $max = 0;
        if ($this->hasTable('api_request_logs')) {
            $cols = $this->columns('api_request_logs');
            if (in_array('endpoint_path', $cols, true) && in_array('http_status', $cols, true) && in_array('created_at', $cols, true) && in_array('retry_after_seconds', $cols, true)) {
                $remoteClause = in_array('reached_remote', $cols, true) ? ' AND `reached_remote`=1' : '';
                $stmt = $this->pdo()->prepare('SELECT MAX(COALESCE(`retry_after_seconds`,0)) FROM `api_request_logs` WHERE `endpoint_path`=? AND `http_status`=429' . $remoteClause . ' AND `created_at` BETWEEN ? AND ?');
                $stmt->execute(['/billing/integration/group/ML/order/details', $fromUtc, $toUtc]);
                $max = max($max, (int) ($stmt->fetchColumn() ?: 0));
            }
        }
        if ($this->hasTable('api_remote_permits')) {
            $cols = $this->columns('api_remote_permits');
            if (in_array('endpoint_key', $cols, true) && in_array('http_status', $cols, true) && in_array('dispatched_at', $cols, true) && in_array('retry_after_seconds', $cols, true)) {
                $timeExpr = in_array('completed_at', $cols, true) ? 'COALESCE(`completed_at`,`dispatched_at`)' : '`dispatched_at`';
                $stmt = $this->pdo()->prepare('SELECT MAX(COALESCE(`retry_after_seconds`,0)) FROM `api_remote_permits` WHERE `endpoint_key`=? AND `http_status`=429 AND `dispatched_at` IS NOT NULL AND ' . $timeExpr . ' BETWEEN ? AND ?');
                $stmt->execute(['billing_orders', $fromUtc, $toUtc]);
                $max = max($max, (int) ($stmt->fetchColumn() ?: 0));
            }
        }
        return $max;
    }

    /** @return array{found:int,releasable:int,skipped:int,first_false_predicate:?string,predicates:array<string,int|string>} */
    private function staleBilling429FinancePointerDiagnosis(string $availableFromUtc, string $availableToUtc): array
    {
        $out = [
            'found' => 0,
            'releasable' => 0,
            'skipped' => 0,
            'first_false_predicate' => null,
            'predicates' => [],
        ];
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasTable('sale_financial_reconciliation_jobs')) {
            $out['first_false_predicate'] = 'REQUIRED_TABLES_PRESENT';
            return $out;
        }
        $qCols = $this->columns('queue_v4_clean_jobs');
        $sCols = $this->columns('sale_financial_reconciliation_jobs');
        foreach (['state', 'payload_json', 'available_at', 'company_id', 'meli_account_id'] as $required) {
            if (!in_array($required, $qCols, true)) {
                $out['first_false_predicate'] = 'QUEUE_COLUMN_' . strtoupper($required);
                return $out;
            }
        }
        foreach (['id', 'company_id', 'meli_account_id', 'status', 'next_run_at'] as $required) {
            if (!in_array($required, $sCols, true)) {
                $out['first_false_predicate'] = 'SOURCE_COLUMN_' . strtoupper($required);
                return $out;
            }
        }

        $source = $this->financeSourceIdSql($qCols);
        $financePredicate = $this->financeQueuePredicate($qCols, 'q');
        $leasePredicate = $this->queueLeasePredicate($qCols, 'q');
        $baseParams = [$availableFromUtc, $availableToUtc];

        $predicates = [];
        $where = "q.`state`='waiting' AND q.`available_at` BETWEEN ? AND ?";
        $predicates['STATE_WAITING_AND_OLD_195529_WINDOW'] = $this->countStalePointerPredicate($where, $baseParams);

        $where .= " AND {$financePredicate}";
        $predicates['FINANCE_QUEUE_POINTER_MATCH'] = $this->countStalePointerPredicate($where, $baseParams);
        $out['found'] = (int) $predicates['FINANCE_QUEUE_POINTER_MATCH'];

        $where .= ' AND ' . $source['predicate'];
        $predicates['SOURCE_ID_PRESENT'] = $this->countStalePointerPredicate($where, $baseParams);

        $join = "INNER JOIN `sale_financial_reconciliation_jobs` s
                    ON s.`id`={$source['sql']}
                   AND s.`company_id`=q.`company_id`
                   AND s.`meli_account_id`=q.`meli_account_id`";
        $predicates['SOURCE_JOIN_MATCH_COMPANY_ACCOUNT'] = $this->countStalePointerPredicate($where, $baseParams, $join);

        $whereStatus = $where . " AND s.`status` IN ('pending','retry','ready','waiting','running','awaiting_remote')";
        $predicates['SOURCE_STATUS_EXECUTABLE'] = $this->countStalePointerPredicate($whereStatus, $baseParams, $join);

        $whereDue = $whereStatus . " AND s.`next_run_at` IS NOT NULL AND s.`next_run_at`<=UTC_TIMESTAMP(3)";
        $predicates['SOURCE_NEXT_RUN_DUE'] = $this->countStalePointerPredicate($whereDue, $baseParams, $join);

        $whereLease = $whereDue . ($leasePredicate === '' ? '' : ' ' . $leasePredicate);
        $predicates['NO_LIVE_QUEUE_LEASE'] = $this->countStalePointerPredicate($whereLease, $baseParams, $join);

        $out['releasable'] = (int) $predicates['NO_LIVE_QUEUE_LEASE'];
        $out['skipped'] = max(0, $out['found'] - $out['releasable']);
        $out['predicates'] = $predicates;
        $previous = null;
        foreach ($predicates as $name => $count) {
            $count = (int) $count;
            if ($previous !== null && $previous > 0 && $count === 0) {
                $out['first_false_predicate'] = $name;
                break;
            }
            $previous = $count;
        }
        if ($out['first_false_predicate'] === null && $out['found'] > 0 && $out['releasable'] > 0) {
            $out['first_false_predicate'] = 'NONE_RELEASABLE';
        }
        return $out;
    }

    /** @param list<mixed> $params */
    private function countStalePointerPredicate(string $where, array $params, string $join = ''): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM `queue_v4_clean_jobs` q {$join} WHERE {$where}");
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function expireStaleBilling429Penalties(string $blockedFromUtc, string $blockedToUtc): int
    {
        if (!$this->hasTable('api_rhythm_penalties')) {
            return 0;
        }
        $cols = $this->columns('api_rhythm_penalties');
        if (!in_array('scope_key', $cols, true) || !in_array('blocked_until', $cols, true)) {
            return 0;
        }
        $set = ['`blocked_until`=UTC_TIMESTAMP(3)'];
        if (in_array('updated_at', $cols, true)) {
            $set[] = '`updated_at`=UTC_TIMESTAMP(3)';
        }
        $scopeKeys = [
            'endpoint:billing_orders',
            'endpoint:shared:' . hash('sha256', 'billing_orders'),
        ];
        $stmt = $this->pdo()->prepare(
            'UPDATE `api_rhythm_penalties` SET ' . implode(',', $set)
            . ' WHERE `scope_key` IN (?,?)'
            . ' AND `blocked_until` BETWEEN ? AND ?'
        );
        $stmt->execute([$scopeKeys[0], $scopeKeys[1], $blockedFromUtc, $blockedToUtc]);
        return $stmt->rowCount();
    }

    private function releaseStaleBilling429FinanceWaitingRows(string $availableFromUtc, string $availableToUtc): int
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasTable('sale_financial_reconciliation_jobs')) {
            return 0;
        }
        $qCols = $this->columns('queue_v4_clean_jobs');
        $sCols = $this->columns('sale_financial_reconciliation_jobs');
        foreach (['state', 'payload_json', 'available_at', 'company_id', 'meli_account_id'] as $required) {
            if (!in_array($required, $qCols, true)) {
                return 0;
            }
        }
        foreach (['id', 'company_id', 'meli_account_id', 'status', 'next_run_at'] as $required) {
            if (!in_array($required, $sCols, true)) {
                return 0;
            }
        }

        $source = $this->financeSourceIdSql($qCols);
        $sourceIdSql = $source['sql'];
        $sourceIdPredicate = $source['predicate'];
        $financePredicate = $this->financeQueuePredicate($qCols, 'q');
        $leasePredicate = (in_array('lease_owner', $qCols, true) && in_array('lease_expires_at', $qCols, true))
            ? "AND (q.`lease_owner` IS NULL OR q.`lease_expires_at` IS NULL OR q.`lease_expires_at`<=UTC_TIMESTAMP(3))"
            : '';
        $set = [
            "q.`state`='ready'",
            'q.`available_at`=UTC_TIMESTAMP(3)',
        ];
        if (in_array('last_error_class', $qCols, true)) {
            $set[] = 'q.`last_error_class`=NULL';
        }
        if (in_array('last_error_message', $qCols, true)) {
            $set[] = 'q.`last_error_message`=NULL';
        }
        if (in_array('updated_at', $qCols, true)) {
            $set[] = 'q.`updated_at`=UTC_TIMESTAMP(3)';
        }

        $statement = $this->pdo()->prepare(
            "UPDATE `queue_v4_clean_jobs` q
                INNER JOIN `sale_financial_reconciliation_jobs` s
                        ON s.`id`={$sourceIdSql}
                       AND s.`company_id`=q.`company_id`
                       AND s.`meli_account_id`=q.`meli_account_id`
                   SET " . implode(',', $set) . "
                 WHERE q.`state`='waiting'
                   AND q.`available_at` BETWEEN ? AND ?
                   {$leasePredicate}
                   AND {$financePredicate}
                   AND {$sourceIdPredicate}
                   AND s.`status` IN ('pending','retry','ready','waiting','running','awaiting_remote')
                   AND s.`next_run_at` IS NOT NULL
                   AND s.`next_run_at`<=UTC_TIMESTAMP(3)"
        );
        $statement->execute([$availableFromUtc, $availableToUtc]);
        return $statement->rowCount();
    }

    /** @param list<string> $qCols @return array{sql:string,predicate:string} */
    private function financeSourceIdSql(array $qCols): array
    {
        $sourceIdCases = [
            "WHEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id')) REGEXP '^[0-9]+$'
                THEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id'))",
            "WHEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.sourceId')) REGEXP '^[0-9]+$'
                THEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.sourceId'))",
        ];
        $sourceIdPredicates = [
            "JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id')) REGEXP '^[0-9]+$'",
            "JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.sourceId')) REGEXP '^[0-9]+$'",
        ];
        if (in_array('source_id', $qCols, true)) {
            $sourceIdCases[] = "WHEN q.`source_id` REGEXP '^[0-9]+$' THEN q.`source_id`";
            $sourceIdPredicates[] = "q.`source_id` REGEXP '^[0-9]+$'";
        }
        if (in_array('resource_id', $qCols, true)) {
            $sourceIdCases[] = "WHEN q.`resource_id` REGEXP '^[0-9]+$' THEN q.`resource_id`";
            $sourceIdPredicates[] = "q.`resource_id` REGEXP '^[0-9]+$'";
        }
        return [
            'sql' => 'CAST((CASE ' . implode(' ', $sourceIdCases) . ' ELSE NULL END) AS UNSIGNED)',
            'predicate' => '(' . implode(' OR ', $sourceIdPredicates) . ')',
        ];
    }

    /** @param list<string> $qCols */
    private function financeQueuePredicate(array $qCols, string $alias): string
    {
        $q = preg_match('/^[A-Za-z0-9_]+$/', $alias) === 1 ? $alias : 'q';
        $parts = [
            "JSON_UNQUOTE(JSON_EXTRACT({$q}.`payload_json`,'$.capability'))='financial_reconciliation'",
            "JSON_UNQUOTE(JSON_EXTRACT({$q}.`payload_json`,'$.work_type')) LIKE '%financial_reconciliation%'",
            "JSON_UNQUOTE(JSON_EXTRACT({$q}.`payload_json`,'$.type')) LIKE '%financial_reconciliation%'",
        ];
        foreach (['capability', 'work_type', 'job_type', 'source_table'] as $col) {
            if (in_array($col, $qCols, true)) {
                $parts[] = "{$q}.`{$col}`='financial_reconciliation'";
                $parts[] = "{$q}.`{$col}` LIKE '%financial_reconciliation%'";
                if ($col === 'source_table') {
                    $parts[] = "{$q}.`{$col}`='sale_financial_reconciliation_jobs'";
                }
            }
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param list<string> $qCols */
    private function queueLeasePredicate(array $qCols, string $alias): string
    {
        $q = preg_match('/^[A-Za-z0-9_]+$/', $alias) === 1 ? $alias : 'q';
        if (!in_array('lease_owner', $qCols, true) || !in_array('lease_expires_at', $qCols, true)) {
            return '';
        }
        return "AND ({$q}.`lease_owner` IS NULL OR {$q}.`lease_expires_at` IS NULL OR {$q}.`lease_expires_at`<=UTC_TIMESTAMP(3))";
    }

    /** @return null|array{path:string,filename:string,sha256:string,bytes:int,expires_at:string} */
    public function resolveDownloadToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 160 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return null;
        }
        $path = $this->tokenDir() . '/' . hash('sha256', $token) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            @unlink($path);
            return null;
        }
        if (strtotime((string) ($data['expires_at'] ?? '')) < time()) {
            @unlink($path);
            return null;
        }
        $zipPath = (string) ($data['zip_path'] ?? '');
        $realRoot = realpath($this->zipDir());
        $realZip = $zipPath !== '' ? realpath($zipPath) : false;
        if ($realRoot === false || $realZip === false || !str_starts_with(str_replace('\\', '/', $realZip), str_replace('\\', '/', $realRoot) . '/')) {
            return null;
        }
        if (!is_file($realZip)) {
            return null;
        }
        return [
            'path' => $realZip,
            'filename' => basename($realZip),
            'sha256' => hash_file('sha256', $realZip),
            'bytes' => filesize($realZip) ?: 0,
            'expires_at' => (string) $data['expires_at'],
        ];
    }

    /** @param callable():array $producer @param list<array<string,string>> $warnings @return array<mixed> */
    private function diagnosticSection(string $section, callable $producer, array &$warnings): array
    {
        try {
            $payload = $producer();
            return is_array($payload) ? $payload : [];
        } catch (Throwable $error) {
            $warning = $this->diagnosticWarning($section, $error);
            $warnings[] = $warning;
            return [
                'generated_at_utc' => $this->now(),
                'section' => $section,
                'status' => 'ERROR',
                'error_class' => $warning['error_class'],
                'safe_code' => $warning['safe_code'],
            ];
        }
    }

    /** @param callable():array $producer @param list<array<string,string>> $warnings @return array<mixed> */
    private function diagnosticRows(string $section, callable $producer, array &$warnings): array
    {
        try {
            $rows = $producer();
            return is_array($rows) ? $rows : [];
        } catch (Throwable $error) {
            $warnings[] = $this->diagnosticWarning($section, $error);
            return [];
        }
    }

    /** @return array<string,string> */
    private function diagnosticWarning(string $section, Throwable $error): array
    {
        return [
            'section' => $section,
            'status' => 'ERROR',
            'error_class' => get_class($error),
            'safe_code' => $section . '_snapshot_failed',
            'message_safe' => $this->safeDiagnosticErrorMessage($error->getMessage()),
        ];
    }

    private function safeDiagnosticErrorMessage(string $message): string
    {
        $message = preg_replace('/([A-Za-z0-9+\\/.=_-]{24,})/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/(password|passwd|token|secret|authorization|credential)\\s*[:=]\\s*\\S+/i', '$1=[redacted]', $message) ?? $message;
        return substr($message, 0, 240);
    }

    /** @param array<string,array<mixed>> $payloads @param list<array<string,string>> $warnings @return array<string,mixed> */
    private function summaryFromPayloads(array $debug, array $payloads, array $warnings): array
    {
        $queue = $payloads['02_QUEUE_CURRENT.json'] ?? [];
        $throughput = $payloads['14_THROUGHPUT.json'] ?? [];
        $waiting = $payloads['03_WAITING_CURRENT.json'] ?? [];
        $packCoverage = $payloads['11_PACK_SOURCE_COVERAGE.json'] ?? [];
        $finance = $payloads['12_FINANCE_PIPELINE.json'] ?? [];
        $financeStatus = (string) ($finance['status'] ?? 'OK');
        $financeOk = $financeStatus === 'OK';
        return [
            'generated_at_utc' => $this->now(),
            'status' => 'QUEUE_V4_DIAGNOSTIC_BUNDLE_READY',
            'normal_audit_requires_ssh' => false,
            'debug_mode' => $debug['mode'] ?? 'off',
            'debug_enabled' => (bool) ($debug['enabled'] ?? false),
            'debug_expires_at' => $debug['expires_at'] ?? null,
            'diagnostic_warnings' => $warnings,
            'section_failure_can_abort_bundle' => false,
            'bundle_generates_partial' => true,
            'control_unit' => 'PHYSICAL_API_CALL',
            'cron_max_calls' => (new AutomationCallBudgetService())->resolve(null, null)['max_calls'],
            'cron_legacy_max_jobs_alias_accepted' => false,
            'legacy_max_jobs_argument_removed' => true,
            'queue_active' => (string) ($queue['control']['engine_state'] ?? '') === 'ACTIVE',
            'queue_certified' => (string) ($queue['control']['readiness_state'] ?? '') === 'CERTIFIED',
            'ready' => $queue['counts']['ready'] ?? 0,
            'waiting' => $queue['counts']['waiting'] ?? 0,
            'review' => $queue['counts']['review'] ?? 0,
            'running' => $queue['counts']['running'] ?? 0,
            'last_15m' => $throughput['windows']['15m'] ?? [],
            'last_60m' => $throughput['windows']['60m'] ?? [],
            'effective_completion_ratio_60m' => $throughput['windows']['60m']['completion_ratio'] ?? 0,
            'top_waiting_reason' => $waiting['top_reason'] ?? null,
            'finance' => [
                'status' => $financeStatus,
                'billing_order_ids_per_call_limit' => $financeOk ? ($finance['BILLING_ORDER_IDS_PER_CALL_LIMIT'] ?? null) : null,
                'waiting' => $financeOk ? ($finance['FINANCE_WAITING_TOTAL'] ?? null) : null,
                'future' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['FINANCE_NEXT_RUN_FUTURE'] ?? 0) : null,
                'billing_interval' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['BILLING_ENDPOINT_INTERVAL'] ?? 0) : null,
                'remote_uncertain' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['REMOTE_RESULT_UNCERTAIN'] ?? 0) : null,
                'pack_incomplete' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['PACK_INCOMPLETE'] ?? 0) : null,
                'ready_but_still_waiting' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['READY_BUT_STILL_WAITING'] ?? 0) : null,
                'wakeup' => $financeOk ? ($finance['FINANCE_WAKEUP'] ?? null) : null,
                'wakeup_metric_source' => $financeOk ? ($finance['FINANCE_WAKEUP_METRIC_SOURCE'] ?? null) : null,
                'wakeup_runtime_15m' => $financeOk ? ($finance['FINANCE_WAKE_RUNTIME_15M'] ?? null) : null,
                'wakeup_runtime_60m' => $financeOk ? ($finance['FINANCE_WAKE_RUNTIME_60M'] ?? null) : null,
            ],
            'broad_unknown_packs' => $packCoverage['BROAD_UNKNOWN_PACKS'] ?? null,
            'blocking_unknown_packs' => $packCoverage['BLOCKING_UNKNOWN_PACKS'] ?? ($packCoverage['BLOCKING_UNKNOWN_TOTAL'] ?? null),
            'actionable_unknown_packs' => $packCoverage['ACTIONABLE_UNKNOWN_PACKS'] ?? null,
            'blocking_packs_with_usable_source' => $packCoverage['BLOCKING_WITH_USABLE_SOURCE'] ?? null,
            'blocking_packs_without_usable_source' => $packCoverage['BLOCKING_WITHOUT_USABLE_SOURCE'] ?? null,
            'pack_source_coverage_percent' => $packCoverage['PACK_SOURCE_COVERAGE_PERCENT'] ?? null,
            'warnings' => array_values(array_filter(array_merge(
                $warnings,
                $waiting['warnings'] ?? [],
                $packCoverage['warnings'] ?? []
            ))),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(array $debug): array
    {
        $queue = $this->queueCurrent();
        $throughput = $this->throughput();
        $waiting = $this->waitingCurrent();
        $packCoverage = $this->packSourceCoverage();
        $finance = $this->financePipeline();
        $financeStatus = (string) ($finance['status'] ?? 'OK');
        $financeOk = $financeStatus === 'OK';
        return [
            'generated_at_utc' => $this->now(),
            'status' => 'QUEUE_V4_DIAGNOSTIC_BUNDLE_READY',
            'normal_audit_requires_ssh' => false,
            'debug_mode' => $debug['mode'] ?? 'off',
            'debug_enabled' => (bool) ($debug['enabled'] ?? false),
            'debug_expires_at' => $debug['expires_at'] ?? null,
            'control_unit' => 'PHYSICAL_API_CALL',
            'cron_max_calls' => (new AutomationCallBudgetService())->resolve(null, null)['max_calls'],
            'cron_legacy_max_jobs_alias_accepted' => false,
            'legacy_max_jobs_argument_removed' => true,
            'queue_active' => (string) ($queue['control']['engine_state'] ?? '') === 'ACTIVE',
            'queue_certified' => (string) ($queue['control']['readiness_state'] ?? '') === 'CERTIFIED',
            'ready' => $queue['counts']['ready'] ?? 0,
            'waiting' => $queue['counts']['waiting'] ?? 0,
            'review' => $queue['counts']['review'] ?? 0,
            'running' => $queue['counts']['running'] ?? 0,
            'last_15m' => $throughput['windows']['15m'] ?? [],
            'last_60m' => $throughput['windows']['60m'] ?? [],
            'effective_completion_ratio_60m' => $throughput['windows']['60m']['completion_ratio'] ?? 0,
            'top_waiting_reason' => $waiting['top_reason'] ?? null,
            'finance' => [
                'status' => $financeStatus,
                'billing_order_ids_per_call_limit' => $financeOk ? ($finance['BILLING_ORDER_IDS_PER_CALL_LIMIT'] ?? null) : null,
                'waiting' => $financeOk ? ($finance['FINANCE_WAITING_TOTAL'] ?? null) : null,
                'future' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['FINANCE_NEXT_RUN_FUTURE'] ?? 0) : null,
                'billing_interval' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['BILLING_ENDPOINT_INTERVAL'] ?? 0) : null,
                'remote_uncertain' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['REMOTE_RESULT_UNCERTAIN'] ?? 0) : null,
                'pack_incomplete' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['PACK_INCOMPLETE'] ?? 0) : null,
                'ready_but_still_waiting' => $financeOk ? ($finance['FINANCE_WAITING_REASON_COUNTS']['READY_BUT_STILL_WAITING'] ?? 0) : null,
                'wakeup' => $financeOk ? ($finance['FINANCE_WAKEUP'] ?? null) : null,
                'wakeup_metric_source' => $financeOk ? ($finance['FINANCE_WAKEUP_METRIC_SOURCE'] ?? null) : null,
                'wakeup_runtime_15m' => $financeOk ? ($finance['FINANCE_WAKE_RUNTIME_15M'] ?? null) : null,
                'wakeup_runtime_60m' => $financeOk ? ($finance['FINANCE_WAKE_RUNTIME_60M'] ?? null) : null,
            ],
            'broad_unknown_packs' => $packCoverage['BROAD_UNKNOWN_PACKS'] ?? null,
            'blocking_unknown_packs' => $packCoverage['BLOCKING_UNKNOWN_PACKS'] ?? ($packCoverage['BLOCKING_UNKNOWN_TOTAL'] ?? null),
            'actionable_unknown_packs' => $packCoverage['ACTIONABLE_UNKNOWN_PACKS'] ?? null,
            'blocking_packs_with_usable_source' => $packCoverage['BLOCKING_WITH_USABLE_SOURCE'] ?? null,
            'blocking_packs_without_usable_source' => $packCoverage['BLOCKING_WITHOUT_USABLE_SOURCE'] ?? null,
            'pack_source_coverage_percent' => $packCoverage['PACK_SOURCE_COVERAGE_PERCENT'] ?? null,
            'warnings' => array_values(array_filter(array_merge(
                $waiting['warnings'] ?? [],
                $packCoverage['warnings'] ?? []
            ))),
        ];
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        return [
            'generated_at_utc' => $this->now(),
            'php_version' => PHP_VERSION,
            'version_file' => is_file(AppPaths::releaseRoot() . '/VERSION') ? trim((string) file_get_contents(AppPaths::releaseRoot() . '/VERSION')) : 'NOT_FOUND',
            'app_version' => class_exists(AppVersionService::class) ? (new AppVersionService())->installedVersion() : 'NOT_AVAILABLE',
            'release_root_hash' => $this->hashString(AppPaths::releaseRoot()),
            'storage_root_hash' => $this->hashString(AppPaths::storage()),
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false) ? 'true' : 'false',
            'diagnostic_db_writes' => 0,
            'real_meli_http_by_diagnostic' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function queueCurrent(): array
    {
        $pdo = $this->pdo();
        $counts = [];
        foreach ($this->groupCounts('queue_v4_clean_jobs', 'state') as $row) {
            $counts[(string) $row['key']] = (int) $row['count'];
        }
        $oldest = [];
        if ($this->hasTable('queue_v4_clean_jobs') && $this->hasColumn('queue_v4_clean_jobs', 'available_at')) {
            foreach (['ready', 'waiting', 'running', 'review'] as $state) {
                $stmt = $pdo->prepare('SELECT MIN(available_at) FROM queue_v4_clean_jobs WHERE state=?');
                $stmt->execute([$state]);
                $oldest[$state] = $stmt->fetchColumn() ?: null;
            }
        }
        return [
            'generated_at_utc' => $this->now(),
            'control' => $this->controlRow(),
            'counts' => $counts,
            'oldest_available_at_by_state' => $oldest,
            'by_work_type' => $this->queueByWorkType(),
        ];
    }

    /** @return array<string,mixed> */
    private function waitingCurrent(): array
    {
        $rows = $this->waitingRows(500);
        $counts = $this->stateReasonCounts('waiting');
        $total = (int) ($this->stateCount('waiting'));
        return [
            'generated_at_utc' => $this->now(),
            'WAITING_TOTAL' => $total,
            'WAITING_REASON_COUNTS' => $counts,
            'WAITING_REASON_COUNTS_TOTAL' => array_sum($counts),
            'ROWS_TOTAL' => $total,
            'ROWS_EXPORTED' => count($rows),
            'total' => $total,
            'sample_size' => count($rows),
            'reason_counts_all' => $counts,
            'top_reason' => array_key_first($counts),
            'rows_sample' => $rows,
            'rows' => $rows,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function waitingRowsForCsv(): array
    {
        return $this->waitingRows(1000);
    }

    /** @return list<array<string,mixed>> */
    private function waitingRows(int $limit): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs')) {
            return [];
        }
        $columns = $this->columns('queue_v4_clean_jobs');
        $select = [];
        foreach (['id','company_id','meli_account_id','job_type','work_type','source_table','source_id','state','available_at','attempt_count','last_error_class','created_at','updated_at','payload_json'] as $col) {
            if (in_array($col, $columns, true)) {
                $select[] = $col;
            }
        }
        if ($select === []) {
            return [];
        }
        $order = in_array('available_at', $columns, true) ? 'available_at ASC' : (in_array('updated_at', $columns, true) ? 'updated_at ASC' : '1');
        $stmt = $this->pdo()->query('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_jobs WHERE state="waiting" ORDER BY ' . $order . ' LIMIT ' . max(1, min(1000, $limit)));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reasonCode = $this->classifyWaiting($row);
            $rows[] = $this->sanitizeJobRow($row) + $this->reasonMeta($reasonCode);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function reviewCurrent(): array
    {
        $rows = $this->stateRows('review', 200);
        $counts = $this->stateReasonCounts('review');
        $total = $this->stateCount('review');
        return [
            'generated_at_utc' => $this->now(),
            'REVIEW_TOTAL' => $total,
            'REVIEW_REASON_COUNTS' => $counts,
            'REVIEW_REASON_COUNTS_TOTAL' => array_sum($counts),
            'ROWS_TOTAL' => $total,
            'ROWS_EXPORTED' => count($rows),
            'total' => $total,
            'sample' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function cronHealth(): array
    {
        return [
            'generated_at_utc' => $this->now(),
            'control' => $this->controlRow(),
            'control_unit' => 'PHYSICAL_API_CALL',
            'canonical_entrypoint' => 'jobs/queue_v4_clean.php --runtime=45',
            'advanced_override_accepted' => 'jobs/queue_v4_clean.php --runtime=45 --max-calls=1',
            'legacy_hpanel_alias_accepted' => false,
            'legacy_max_jobs_argument_removed' => true,
            'base_receipt_status' => $this->baseReceiptStatus(),
            'manual_cron_runs_by_diagnostic' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function packPipeline(): array
    {
        $coverage = $this->packSourceCoverage();
        return [
            'generated_at_utc' => $this->now(),
            'queue_pack_counts' => $this->queueCapabilityCounts('order_enrichment_pack'),
            'pack_queue_classification_authority' => 'queue_v4_clean_jobs capability=order_enrichment_pack, including domain_exact rows whose capability is stored in payload_json',
            'meli_packs_by_integrity' => $this->hasTable('meli_packs') && $this->hasColumn('meli_packs', 'integrity_status')
                ? $this->groupCounts('meli_packs', 'integrity_status')
                : [],
            'waiting_pack_sample' => array_values(array_filter($this->waitingRows(300), static fn (array $row): bool => str_contains((string) ($row['work_type'] ?? ''), 'pack') || str_contains((string) ($row['job_type'] ?? ''), 'pack') || str_contains(json_encode($row['payload_shape'] ?? [], JSON_UNESCAPED_SLASHES) ?: '', 'order_enrichment_pack') || str_contains((string) ($row['reason'] ?? ''), 'PACK'))),
            'source_coverage' => [
                'broad_unknown_packs' => $coverage['BROAD_UNKNOWN_PACKS'] ?? 0,
                'blocking_unknown_packs' => $coverage['BLOCKING_UNKNOWN_PACKS'] ?? 0,
                'actionable_unknown_packs' => $coverage['ACTIONABLE_UNKNOWN_PACKS'] ?? 0,
                'blocking_unknown_total' => $coverage['BLOCKING_UNKNOWN_PACKS'] ?? ($coverage['BLOCKING_UNKNOWN_TOTAL'] ?? 0),
                'blocking_with_usable_source' => $coverage['BLOCKING_WITH_USABLE_SOURCE'] ?? 0,
                'blocking_without_usable_source' => $coverage['BLOCKING_WITHOUT_USABLE_SOURCE'] ?? 0,
                'coverage_percent' => $coverage['PACK_SOURCE_COVERAGE_PERCENT'] ?? 0.0,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function packSourceCoverage(): array
    {
        $out = [
            'generated_at_utc' => $this->now(),
            'metric_scope' => 'diagnostic truth fix: broad unknown packs are separated from unknown packs that actually block current finance waiting work',
            'PACK_SOURCE_AUTHORITY' => 'order_resource_enrichment_jobs',
            'PACK_QUEUE_POINTER_AUTHORITY' => 'queue_v4_clean_jobs capability=order_enrichment_pack',
            'BROAD_UNKNOWN_PACKS' => 0,
            'BLOCKING_UNKNOWN_PACKS' => 0,
            'ACTIONABLE_UNKNOWN_PACKS' => 0,
            'BLOCKING_UNKNOWN_TOTAL' => 0,
            'BLOCKING_WITH_USABLE_SOURCE' => 0,
            'BLOCKING_WITHOUT_USABLE_SOURCE' => 0,
            'PACK_SOURCE_COVERAGE_PERCENT' => 0.0,
            'SOURCE_ABSENCE_REASON_COUNTS' => [],
            'LOCAL_ORDER_IDENTITY_AVAILABLE_COUNT' => 0,
            'rows_total' => 0,
            'rows_sampled' => 0,
            'rows' => [],
            'warnings' => [],
        ];

        if (!$this->hasTable('meli_packs')) {
            $out['warnings'][] = 'meli_packs_table_missing';
            return $out;
        }

        $packCols = $this->columns('meli_packs');
        $packPk = $this->firstExistingColumn($packCols, ['id', 'pack_id', 'meli_pack_id', 'external_pack_id']);
        $accountCol = $this->firstExistingColumn($packCols, ['meli_account_id', 'account_id']);
        $companyCol = $this->firstExistingColumn($packCols, ['company_id']);
        $integrityCol = $this->firstExistingColumn($packCols, ['integrity_status', 'pack_integrity_status']);
        $expectedJsonCol = $this->firstExistingColumn($packCols, ['expected_orders_json', 'expected_order_ids_json', 'expected_children_json']);
        $expectedCountCol = $this->firstExistingColumn($packCols, ['expected_order_count', 'expected_orders_count', 'expected_count']);
        $externalPackCol = $this->firstExistingColumn($packCols, ['external_pack_id', 'pack_id', 'meli_pack_id']);

        if ($packPk === null) {
            $out['warnings'][] = 'pack_identity_column_missing';
            return $out;
        }

        $unknownWhere = $this->packUnknownWhere($packCols, $integrityCol, $expectedJsonCol, $expectedCountCol);
        if ($unknownWhere['warnings'] !== []) {
            array_push($out['warnings'], ...$unknownWhere['warnings']);
        }
        $whereSql = $unknownWhere['sql'] !== '' ? ' WHERE ' . $unknownWhere['sql'] : '';

        try {
            $out['BROAD_UNKNOWN_PACKS'] = (int) $this->pdo()->query('SELECT COUNT(*) FROM meli_packs' . $whereSql)->fetchColumn();
        } catch (Throwable $error) {
            $out['warnings'][] = 'broad_unknown_pack_count_failed:' . get_class($error);
        }

        $blocking = $this->blockingFinanceCountsByPack();
        $blockingRows = array_slice(array_values($blocking), 0, 500);
        $select = array_values(array_filter(array_unique([
            $packPk,
            $externalPackCol,
            $accountCol,
            $companyCol,
            $integrityCol,
            $expectedJsonCol,
            $expectedCountCol,
            $this->firstExistingColumn($packCols, ['created_at']),
            $this->firstExistingColumn($packCols, ['updated_at']),
        ])));

        $absenceCounts = [];
        $usable = 0;
        $actionable = 0;
        $localIdentityCount = 0;
        $sanitizedRows = [];

        foreach ($blockingRows as $row) {
            $packIdentity = (string) ($row[$externalPackCol ?? $packPk] ?? $row[$packPk] ?? '');
            $pkValue = (string) ($row[$packPk] ?? '');
            $accountValue = $accountCol !== null ? (string) ($row[$accountCol] ?? '') : '';
            $companyValue = $companyCol !== null ? (string) ($row[$companyCol] ?? '') : '';
            $localIdentityAvailable = $this->packHasLocalOrderIdentity($pkValue, $packIdentity);
            if ($localIdentityAvailable) {
                $localIdentityCount++;
            }

            $source = $this->findPackDomainSource($packIdentity, $pkValue, $accountValue, $companyValue);
            $sourceExists = $source !== null;
            $sourceUsable = $sourceExists && $this->isUsablePackDomainSource($source);
            $sourceClaimable = $sourceExists && $this->isClaimablePackDomainSource($source);
            if ($sourceUsable) {
                $usable++;
            }
            if ($sourceClaimable) {
                $actionable++;
            }
            $absenceReason = $sourceExists ? null : ($localIdentityAvailable ? 'NO_ORDER_RESOURCE_ENRICHMENT_SOURCE_FOR_PACK' : 'NO_LOCAL_ORDER_IDENTITY_FOR_PACK');
            if ($absenceReason !== null) {
                $absenceCounts[$absenceReason] = ($absenceCounts[$absenceReason] ?? 0) + 1;
            }

            $item = [
                'pack_hash' => $this->hashString($packIdentity !== '' ? $packIdentity : $pkValue),
                'account_hash' => $accountValue !== '' ? $this->hashString($accountValue) : null,
                'company_hash' => $companyValue !== '' ? $this->hashString($companyValue) : null,
                'blocking_finance_count' => (int) ($row['_blocking_finance_count'] ?? 0),
                'local_order_identity_available' => $localIdentityAvailable,
                'pack_source_exists' => $sourceExists,
            ];
            if ($sourceExists) {
                $item += [
                    'source_hash' => $this->hashString((string) ($source['source_identity'] ?? $source['id'] ?? 'source')),
                    'source_status' => $source['state'] ?? null,
                    'source_next_run_at' => $source['available_at'] ?? null,
                    'source_attempts' => $source['attempt_count'] ?? null,
                    'source_failure' => $source['last_error_class'] ?? null,
                    'source_error' => $source['last_error_class'] ?? null,
                    'source_usable' => $sourceUsable,
                    'source_claimable' => $sourceClaimable,
                ];
            } else {
                $item['source_absence_reason_code'] = $absenceReason;
            }
            $sanitizedRows[] = $item;
        }

        $total = count($blocking);
        $out['BLOCKING_UNKNOWN_PACKS'] = $total;
        $out['ACTIONABLE_UNKNOWN_PACKS'] = $actionable;
        $out['BLOCKING_UNKNOWN_TOTAL'] = $total;
        $out['BLOCKING_WITH_USABLE_SOURCE'] = $usable;
        $out['BLOCKING_WITHOUT_USABLE_SOURCE'] = max(0, $total - $usable);
        $out['PACK_SOURCE_COVERAGE_PERCENT'] = $total > 0 ? round(($usable / $total) * 100, 2) : 0.0;
        $out['SOURCE_ABSENCE_REASON_COUNTS'] = $absenceCounts;
        $out['LOCAL_ORDER_IDENTITY_AVAILABLE_COUNT'] = $localIdentityCount;
        $out['rows_total'] = $total;
        $out['rows_sampled'] = count($sanitizedRows);
        $out['blocking_finance_sum'] = array_sum(array_map(static fn (array $row): int => (int) ($row['_blocking_finance_count'] ?? 0), $blocking));
        $out['rows'] = $sanitizedRows;
        return $out;
    }

    /** @return array<string,mixed> */
    private function financePipeline(): array
    {
        $t0 = hrtime(true);
        $generatedAt = $this->now();
        $meta = [
            'queue_rows' => 0,
            'unique_source_ids' => 0,
            'source_batch_size' => 100,
            'source_batch_queries' => 0,
            'source_missing' => 0,
            'source_id_invalid' => 0,
            'queue_query_ms' => 0,
            'source_fetch_ms' => 0,
            'classification_ms' => 0,
            'warnings' => [],
        ];

        $api = [];
        try {
            $api = $this->apiOutcomes();
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('finance_billing_transport_metrics', $error);
        }
        $billing15 = $this->billingApiCounters($api, '15m');
        $billing60 = $this->billingApiCounters($api, '60m');
        $billingBlock = $this->currentBillingBlockState($generatedAt, $meta);
        $billing429Backoff = $this->billing429BackoffStateForDiagnostic($meta);

        $attempt15 = ['claimed' => 0, 'completed' => 0];
        $attempt60 = ['claimed' => 0, 'completed' => 0];
        try {
            $attempt15 = $this->financeAttemptWindow(15);
            $attempt60 = $this->financeAttemptWindow(60);
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('finance_attempt_window', $error);
        }

        try {
            $rows = $this->financeWaitingRows($generatedAt, $billingBlock, $meta);
            $status = empty($meta['warnings']) ? 'OK' : 'PARTIAL';
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('finance_waiting_source_snapshot', $error);
            $rows = [];
            $status = 'PARTIAL';
        }

        $reasonCounts = [];
        $horizon = [
            'DUE_NOW' => 0,
            '0_TO_2_MIN' => 0,
            '2_TO_5_MIN' => 0,
            '5_TO_15_MIN' => 0,
            '15_TO_60_MIN' => 0,
            '1_TO_6_HOURS' => 0,
            'OVER_6_HOURS' => 0,
        ];
        $byAccount = [];
        foreach ($rows as $row) {
            $reason = (string) ($row['finance_waiting_reason'] ?? 'OTHER');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $account = (string) ($row['account_hash'] ?? 'unknown');
            $byAccount[$account] = ($byAccount[$account] ?? 0) + 1;
            $bucket = $this->financeNextRunHorizonBucket($row['source_next_run_at'] ?? null, $generatedAt);
            $horizon[$bucket] = ($horizon[$bucket] ?? 0) + 1;
        }
        arsort($reasonCounts);
        arsort($byAccount);
        $financeWakeCandidates = (int) ($reasonCounts['READY_BUT_STILL_WAITING'] ?? 0);
        $financeWakeSkippedFuture = (int) ($reasonCounts['FINANCE_NEXT_RUN_FUTURE'] ?? 0);
        $financeWakeSkippedBlocked = max(0, count($rows) - $financeWakeCandidates - $financeWakeSkippedFuture);
        $financeWakeReleasedCurrentReady = $this->financeReadyClaimableCount($meta);
        $financeWakeup = [
            'candidates' => $financeWakeCandidates,
            'released' => $financeWakeReleasedCurrentReady,
            'skipped_future' => $financeWakeSkippedFuture,
            'skipped_blocked' => $financeWakeSkippedBlocked,
            'errors' => 0,
        ];
        $financeWakeRuntime15 = $this->financeWakeupRuntimeWindow(15);
        $financeWakeRuntime60 = $this->financeWakeupRuntimeWindow(60);
        $financeWakeupMetricSource = ((int) ($financeWakeRuntime15['runs'] ?? 0) > 0 || (int) ($financeWakeRuntime60['runs'] ?? 0) > 0)
            ? 'MIXED'
            : 'DIAGNOSTIC_SNAPSHOT';
        $billingPolicy = ApiRhythmPolicyService::billingPacingDiagnosticPolicy();
        $totalMs = (int) round((hrtime(true) - $t0) / 1000000);
        return [
            'status' => $status,
            'generated_at_utc' => $generatedAt,
            'finance_source_authority' => 'sale_financial_reconciliation_jobs',
            'finance_queue_pointer_authority' => 'queue_v4_clean_jobs payload capability=financial_reconciliation and payload source_id',
            'BILLING_INTERVAL_SECONDS' => (int) $billingPolicy['interval_seconds'],
            'BILLING_INTERVAL_SCOPE' => (string) $billingPolicy['scope'],
            'BILLING_ORDER_IDS_PER_CALL_LIMIT' => SaleFinancialService::billingOrderIdsPerCallLimit(),
            'ONE_ACCOUNT_BILLING_CALL_BLOCKS_OTHER_ACCOUNTS' => 'YES',
            'BILLING_INTERVAL_AUTHORITY' => 'App\\Services\\ApiRhythmPolicyService::billingBlock endpoint_key=billing_orders',
            'BILLING_POLICY_RUNTIME_SOURCE' => (string) $billingPolicy['runtime_source'],
            'BILLING_POLICY_FILE_SHA256' => $billingPolicy['file_sha256'],
            'BILLING_BLOCK_ACTIVE' => $billingBlock['active'] ? 'YES' : 'NO',
            'BILLING_BLOCK_UNTIL' => $billingBlock['until'],
            'BILLING_BLOCK_REMAINING_SECONDS' => $billingBlock['remaining_seconds'],
            'BILLING_BLOCK_SOURCE' => $billingBlock['source'],
            'BILLING_INTERVAL_BLOCK_ACTIVE' => $billingBlock['active'] ? 'YES' : 'NO',
            'BILLING_INTERVAL_BLOCK_UNTIL' => $billingBlock['until'],
            'BILLING_INTERVAL_BLOCK_REMAINING_SECONDS' => $billingBlock['remaining_seconds'],
            'BILLING_429_BACKOFF_UNTIL' => $billing429Backoff['backoff_until'] ?? null,
            'BILLING_429_LAST_REAL_AT' => $billing429Backoff['last_real_at'] ?? null,
            'BILLING_429_LAST_SUCCESS_AT' => $billing429Backoff['last_success_at'] ?? null,
            ...$this->billingEvidenceProjection($billing429Backoff),
            'BILLING_429_PHYSICAL_CORRELATION_KEY' => (string) ($billing429Backoff['physical_correlation_key'] ?? 'UNKNOWN'),
            'BILLING_429_FALLBACK_DEDUPE_TOLERANCE_MS' => 0,
            'API_REMOTE_PERMITS_RETRY_AFTER_FALLBACK' => (string) ($billing429Backoff['api_remote_permits_retry_after_fallback'] ?? 'UNKNOWN'),
            'STALE_12H_429_RECONCILIATION' => $this->lastStale429Reconciliation,
            'CURRENT_DUPLICATE_BACKOFF_CAN_BE_RELEASED' => (string) ($this->lastStale429Reconciliation['current_duplicate_backoff_can_be_released'] ?? 'NO'),
            'NEWER_REAL_BILLING_429_FOUND' => (string) ($this->lastStale429Reconciliation['newer_real_billing_429_found'] ?? 'UNKNOWN'),
            'EXPLICIT_RETRY_AFTER_ACTIVE' => (string) ($this->lastStale429Reconciliation['explicit_retry_after_active'] ?? 'UNKNOWN'),
            'STALE_429_QUEUE_POINTERS_FOUND' => (int) ($this->lastStale429Reconciliation['stale_429_queue_pointers_found'] ?? 0),
            'STALE_429_QUEUE_POINTERS_RELEASED' => (int) ($this->lastStale429Reconciliation['stale_429_queue_pointers_released'] ?? 0),
            'STALE_429_QUEUE_POINTERS_SKIPPED' => (int) ($this->lastStale429Reconciliation['stale_429_queue_pointers_skipped'] ?? 0),
            'STALE_429_FIRST_FALSE_PREDICATE' => $this->lastStale429Reconciliation['first_false_predicate'] ?? null,
            'STALE_429_REPRESENTATIVE_PREDICATES' => $this->lastStale429Reconciliation['representative_predicates'] ?? [],
            'STALE_429_FUTURE_ROWS_TOUCHED' => (int) ($this->lastStale429Reconciliation['future_rows_touched'] ?? 0),
            'STALE_429_REVIEW_ROWS_TOUCHED' => (int) ($this->lastStale429Reconciliation['review_rows_touched'] ?? 0),
            'STALE_429_RUNNING_ROWS_TOUCHED' => (int) ($this->lastStale429Reconciliation['running_rows_touched'] ?? 0),
            'STALE_429_DUPLICATE_POINTERS_CREATED' => (int) ($this->lastStale429Reconciliation['duplicate_pointers_created'] ?? 0),
            'BILLING_ENDPOINT_INTERVAL_CURRENT_COUNT' => (int) ($reasonCounts['BILLING_ENDPOINT_INTERVAL'] ?? 0),
            'FINANCE_TIMEOUT_QUERY_PURPOSE' => 'classify current Queue V4 waiting financial_reconciliation rows by their source-domain retry truth',
            'FINANCE_TIMEOUT_QUERY_PATTERN' => 'JSON_EXTRACT_JOIN+PACK_RELATION_JOIN+N_PLUS_ONE_TRANSPORT_LOOKUP',
            'FINANCE_TIMEOUT_QUERY_PATTERN_REMOVED' => 'JSON_EXTRACT_JOIN+PACK_RELATION_JOIN+N_PLUS_ONE_TRANSPORT_LOOKUP',
            'FINANCE_DIAGNOSTIC_QUERY_KISS' => true,
            'FINANCE_WAITING_QUEUE_ROWS' => (int) $meta['queue_rows'],
            'FINANCE_UNIQUE_SOURCE_IDS' => (int) $meta['unique_source_ids'],
            'FINANCE_SOURCE_BATCH_SIZE' => (int) $meta['source_batch_size'],
            'FINANCE_SOURCE_BATCH_QUERIES' => (int) $meta['source_batch_queries'],
            'FINANCE_WAITING_TOTAL' => count($rows),
            'FINANCE_WAITING_REASON_COUNTS' => $reasonCounts,
            'FINANCE_WAITING_REASON_COUNTS_TOTAL' => array_sum($reasonCounts),
            'FINANCE_NEXT_RUN_HORIZON' => $horizon,
            'FINANCE_WAITING_BY_ACCOUNT' => $byAccount,
            'FINANCE_WAKEUP' => $financeWakeup,
            'FINANCE_WAKEUP_METRIC_SOURCE' => $financeWakeupMetricSource,
            'FINANCE_WAKE_CANDIDATES_CURRENT' => $financeWakeCandidates,
            'FINANCE_WAKE_RELEASED_CURRENT_READY' => $financeWakeReleasedCurrentReady,
            'FINANCE_WAKE_SKIPPED_FUTURE_CURRENT' => $financeWakeSkippedFuture,
            'FINANCE_WAKE_SKIPPED_BLOCKED_CURRENT' => $financeWakeSkippedBlocked,
            'FINANCE_WAKE_RUNTIME_15M' => $financeWakeRuntime15,
            'FINANCE_WAKE_RUNTIME_60M' => $financeWakeRuntime60,
            'FINANCE_WAKE_RUNTIME_RUNS_15M' => (int) ($financeWakeRuntime15['runs'] ?? 0),
            'FINANCE_WAKE_RUNTIME_CANDIDATES_15M' => (int) ($financeWakeRuntime15['candidates'] ?? 0),
            'FINANCE_WAKE_RUNTIME_RELEASED_15M' => (int) ($financeWakeRuntime15['released'] ?? 0),
            'FINANCE_WAKE_RUNTIME_ERRORS_15M' => (int) ($financeWakeRuntime15['errors'] ?? 0),
            'FINANCE_WAKE_RUNTIME_RUNS_60M' => (int) ($financeWakeRuntime60['runs'] ?? 0),
            'FINANCE_WAKE_RUNTIME_CANDIDATES_60M' => (int) ($financeWakeRuntime60['candidates'] ?? 0),
            'FINANCE_WAKE_RUNTIME_RELEASED_60M' => (int) ($financeWakeRuntime60['released'] ?? 0),
            'FINANCE_WAKE_RUNTIME_ERRORS_60M' => (int) ($financeWakeRuntime60['errors'] ?? 0),
            'FINANCE_SOURCE_MISSING' => (int) $meta['source_missing'],
            'FINANCE_SOURCE_ID_INVALID' => (int) $meta['source_id_invalid'],
            'FINANCE_CLAIMED_15M' => $attempt15['claimed'],
            'FINANCE_COMPLETED_15M' => $attempt15['completed'],
            'FINANCE_CLAIMED_60M' => $attempt60['claimed'],
            'FINANCE_COMPLETED_60M' => $attempt60['completed'],
            'BILLING_PHYSICAL_HTTP_15M' => $billing15['physical_http_calls'],
            'BILLING_2XX_15M' => $billing15['http_2xx'],
            'BILLING_429_15M' => $billing15['http_429'],
            'BILLING_5XX_15M' => $billing15['http_5xx'],
            'BILLING_REMOTE_UNCERTAIN_15M' => $billing15['remote_uncertain'],
            'BILLING_PHYSICAL_HTTP_60M' => $billing60['physical_http_calls'],
            'BILLING_2XX_60M' => $billing60['http_2xx'],
            'BILLING_429_60M' => $billing60['http_429'],
            'BILLING_5XX_60M' => $billing60['http_5xx'],
            'BILLING_REMOTE_UNCERTAIN_60M' => $billing60['remote_uncertain'],
            'FINANCE_SNAPSHOT_MS' => $totalMs,
            'FINANCE_QUEUE_QUERY_MS' => (int) $meta['queue_query_ms'],
            'FINANCE_SOURCE_FETCH_MS' => (int) $meta['source_fetch_ms'],
            'FINANCE_CLASSIFICATION_MS' => (int) $meta['classification_ms'],
            'performance' => [
                'total_ms' => $totalMs,
                'queue_query_ms' => (int) $meta['queue_query_ms'],
                'source_fetch_ms' => (int) $meta['source_fetch_ms'],
                'classification_ms' => (int) $meta['classification_ms'],
                'source_batch_queries' => (int) $meta['source_batch_queries'],
            ],
            'diagnostic_warnings' => $meta['warnings'],
            'FINANCE_SECTION_FAILURE_CAN_ABORT_BUNDLE' => 'NO',
            'rows_total' => count($rows),
            'rows_sampled' => min(500, count($rows)),
            'rows' => array_slice($rows, 0, 500),
        ];
    }

    /** @param array<string,mixed> $meta */
    private function financeReadyClaimableCount(array &$meta): int
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasTable('sale_financial_reconciliation_jobs')) {
            return 0;
        }

        $qCols = $this->columns('queue_v4_clean_jobs');
        $sCols = $this->columns('sale_financial_reconciliation_jobs');
        foreach (['state', 'payload_json', 'available_at', 'company_id', 'meli_account_id'] as $required) {
            if (!in_array($required, $qCols, true)) {
                return 0;
            }
        }
        foreach (['id', 'company_id', 'meli_account_id', 'status', 'next_run_at'] as $required) {
            if (!in_array($required, $sCols, true)) {
                return 0;
            }
        }

        $sourceIdCases = [
            "WHEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id')) REGEXP '^[0-9]+$'
                THEN JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id'))",
        ];
        $sourceIdPredicates = ["JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.source_id')) REGEXP '^[0-9]+$'"];
        if (in_array('resource_id', $qCols, true)) {
            $sourceIdCases[] = "WHEN q.`resource_id` REGEXP '^[0-9]+$' THEN q.`resource_id`";
            $sourceIdPredicates[] = "q.`resource_id` REGEXP '^[0-9]+$'";
        }
        $sourceIdSql = 'CAST((CASE ' . implode(' ', $sourceIdCases) . ' ELSE NULL END) AS UNSIGNED)';
        $sourceIdPredicate = '(' . implode(' OR ', $sourceIdPredicates) . ')';
        $jobTypePredicate = in_array('job_type', $qCols, true) ? "AND q.`job_type`='domain_exact'" : '';
        $leasePredicate = (in_array('lease_owner', $qCols, true) && in_array('lease_expires_at', $qCols, true))
            ? "AND (q.`lease_owner` IS NULL OR q.`lease_expires_at` IS NULL OR q.`lease_expires_at`<=UTC_TIMESTAMP(3))"
            : '';
        $packPredicate = in_array('sale_key', $sCols, true) && $this->hasTable('meli_packs')
            ? "AND (
                   s.`sale_key` NOT LIKE 'P:%'
                   OR COALESCE((
                       SELECT p.`integrity_status`
                         FROM `meli_packs` p
                        WHERE p.`meli_account_id`=s.`meli_account_id`
                          AND p.`external_pack_id`=SUBSTRING(s.`sale_key`,3)
                        LIMIT 1
                   ), '')='complete'
               )"
            : '';

        try {
            $statement = $this->pdo()->prepare(
                "SELECT COUNT(*)
                   FROM `queue_v4_clean_jobs` q
                   INNER JOIN `sale_financial_reconciliation_jobs` s
                           ON s.`id`={$sourceIdSql}
                          AND s.`company_id`=q.`company_id`
                          AND s.`meli_account_id`=q.`meli_account_id`
                  WHERE q.`state`='ready'
                    AND q.`available_at`<=UTC_TIMESTAMP(3)
                    {$leasePredicate}
                    {$jobTypePredicate}
                    AND JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`,'$.capability'))='financial_reconciliation'
                    AND {$sourceIdPredicate}
                    AND s.`status` IN ('pending','retry','ready','waiting','running','awaiting_remote')
                    AND s.`next_run_at` IS NOT NULL
                    AND s.`next_run_at`<=UTC_TIMESTAMP(3)
                    {$packPredicate}"
            );
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('finance_ready_claimable_count', $error);
            return 0;
        }
    }

    /** @param array<string,mixed> $billingBlock @param array<string,mixed> $meta @return list<array<string,mixed>> */
    private function financeWaitingRows(string $generatedAt, array $billingBlock, array &$meta): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasTable('sale_financial_reconciliation_jobs')) {
            return [];
        }

        $qCols = $this->columns('queue_v4_clean_jobs');
        $sCols = $this->columns('sale_financial_reconciliation_jobs');
        if (!in_array('state', $qCols, true) || !in_array('payload_json', $qCols, true) || !in_array('id', $sCols, true)) {
            return [];
        }

        $queueSelect = array_values(array_unique(array_filter([
            in_array('id', $qCols, true) ? 'id' : null,
            'state',
            'payload_json',
            in_array('company_id', $qCols, true) ? 'company_id' : null,
            in_array('meli_account_id', $qCols, true) ? 'meli_account_id' : null,
            in_array('available_at', $qCols, true) ? 'available_at' : null,
            in_array('last_error_class', $qCols, true) ? 'last_error_class' : null,
            in_array('last_error_message', $qCols, true) ? 'last_error_message' : null,
            in_array('capability', $qCols, true) ? 'capability' : null,
            in_array('work_type', $qCols, true) ? 'work_type' : null,
            in_array('job_type', $qCols, true) ? 'job_type' : null,
            in_array('source_id', $qCols, true) ? 'source_id' : null,
        ], static fn ($v): bool => is_string($v) && $v !== '')));

        $orderParts = [];
        if (in_array('available_at', $qCols, true)) {
            $orderParts[] = '`available_at` ASC';
        }
        if (in_array('id', $qCols, true)) {
            $orderParts[] = '`id` ASC';
        }
        $orderSql = $orderParts === [] ? '' : ' ORDER BY ' . implode(',', $orderParts);
        $queueStart = hrtime(true);
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', array_map([$this, 'qi'], $queueSelect)) . ' FROM `queue_v4_clean_jobs` WHERE `state` = ?' . $orderSql . ' LIMIT 10000');
        $stmt->execute(['waiting']);
        $queueRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $meta['queue_query_ms'] = (int) round((hrtime(true) - $queueStart) / 1000000);

        $pointers = [];
        $sourceIds = [];
        foreach ($queueRows as $queueRow) {
            $payload = json_decode((string) ($queueRow['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $capability = $this->queueDiagnosticCapability($queueRow, $payload);
            if ($capability !== 'financial_reconciliation') {
                continue;
            }
            $sourceId = $this->queueDiagnosticSourceId($queueRow, $payload);
            if ($sourceId === null) {
                $meta['source_id_invalid'] = (int) $meta['source_id_invalid'] + 1;
            } else {
                $sourceIds[$sourceId] = $sourceId;
            }
            $pointers[] = ['queue' => $queueRow, 'source_id' => $sourceId];
        }

        $meta['queue_rows'] = count($pointers);
        $meta['unique_source_ids'] = count($sourceIds);
        $sources = $this->financeSourcesByIds(array_values($sourceIds), $sCols, $meta);
        $transports = $this->lastFinanceTransportsForQueueJobs(array_values(array_filter(array_map(
            static fn (array $pointer): mixed => $pointer['queue']['id'] ?? null,
            $pointers
        ), static fn ($id): bool => $id !== null && (string) $id !== '')), $meta);

        $classifyStart = hrtime(true);
        $out = [];
        foreach ($pointers as $pointer) {
            $queueRow = $pointer['queue'];
            $sourceId = $pointer['source_id'];
            $source = $sourceId !== null ? ($sources[$sourceId] ?? null) : null;
            if ($sourceId !== null && !is_array($source)) {
                $meta['source_missing'] = (int) $meta['source_missing'] + 1;
            }
            $transport = $transports[(string) ($queueRow['id'] ?? '')] ?? ['reached_remote' => 'UNKNOWN', 'last_transport_endpoint' => null, 'last_http_status' => null];
            $row = [
                'queue_job_id' => $queueRow['id'] ?? null,
                'queue_account_id' => $queueRow['meli_account_id'] ?? null,
                'queue_company_id' => $queueRow['company_id'] ?? null,
                'queue_available_at' => $queueRow['available_at'] ?? null,
                'queue_last_error_class' => $queueRow['last_error_class'] ?? null,
                'queue_last_error_message' => $queueRow['last_error_message'] ?? null,
                'source_id' => $sourceId,
                'source_account_id' => is_array($source) ? ($source['meli_account_id'] ?? null) : null,
                'source_company_id' => is_array($source) ? ($source['company_id'] ?? null) : null,
                'source_status' => is_array($source) ? ($source['status'] ?? null) : null,
                'source_next_run_at' => is_array($source) ? ($source['next_run_at'] ?? null) : null,
                'source_attempts' => is_array($source) ? (($source['attempts'] ?? null) ?: ($source['attempt_count'] ?? null)) : null,
                'source_failure_class' => is_array($source) ? (($source['last_error_class'] ?? null) ?: ($source['error_class'] ?? null)) : null,
                'source_last_error_code' => is_array($source) ? (($source['last_error_code'] ?? null) ?: ($source['error_code'] ?? null)) : null,
                'source_safe_message' => is_array($source) ? ($source['safe_message'] ?? null) : null,
                'pack_integrity_status' => null,
                'source_missing' => $sourceId !== null && !is_array($source),
                'source_id_invalid' => $sourceId === null,
            ];
            $sanitized = [
                'queue_job_hash' => $this->hashString((string) ($row['queue_job_id'] ?? '')),
                'source_hash' => $this->hashString((string) ($row['source_id'] ?? '')),
                'account_hash' => $this->hashString((string) (($row['source_account_id'] ?? null) ?: ($row['queue_account_id'] ?? ''))),
                'queue_available_at' => $row['queue_available_at'] ?? null,
                'source_status' => $row['source_status'] ?? null,
                'source_next_run_at' => $row['source_next_run_at'] ?? null,
                'source_attempts' => $row['source_attempts'] ?? null,
                'source_failure_class' => $row['source_failure_class'] ?? null,
                'source_last_error_code' => $row['source_last_error_code'] ?? null,
                'pack_integrity_ready' => (($row['pack_integrity_status'] ?? null) === 'complete') ? 'YES' : ((($row['pack_integrity_status'] ?? null) === null) ? 'UNKNOWN' : 'NO'),
                'reached_remote' => $transport['reached_remote'] ?? 'UNKNOWN',
                'last_transport_endpoint' => $transport['last_transport_endpoint'] ?? null,
                'last_http_status' => $transport['last_http_status'] ?? null,
            ];
            $sanitized['finance_waiting_reason'] = $this->classifyFinanceWaitingRow(array_merge($row, $transport), $generatedAt, $billingBlock);
            $out[] = $sanitized;
        }
        $meta['classification_ms'] = (int) round((hrtime(true) - $classifyStart) / 1000000);

        return $out;
    }

    /** @param array<string,mixed> $queueRow @param array<string,mixed> $payload */
    private function queueDiagnosticCapability(array $queueRow, array $payload): ?string
    {
        foreach (['capability', 'work_type', 'job_type'] as $key) {
            $value = $payload[$key] ?? ($queueRow[$key] ?? null);
            if (!is_scalar($value)) {
                continue;
            }
            $normalized = strtolower((string) $value);
            if ($normalized === 'financial_reconciliation' || str_contains($normalized, 'financial_reconciliation')) {
                return 'financial_reconciliation';
            }
        }
        return null;
    }

    /** @param array<string,mixed> $queueRow @param array<string,mixed> $payload */
    private function queueDiagnosticSourceId(array $queueRow, array $payload): ?string
    {
        $value = $payload['source_id'] ?? ($queueRow['source_id'] ?? null);
        if (!is_scalar($value)) {
            return null;
        }
        $sourceId = trim((string) $value);
        return $sourceId === '' ? null : $sourceId;
    }

    /** @param list<string> $sourceIds @param list<string> $sCols @param array<string,mixed> $meta @return array<string,array<string,mixed>> */
    private function financeSourcesByIds(array $sourceIds, array $sCols, array &$meta): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $select = array_values(array_unique(array_filter([
            'id',
            in_array('company_id', $sCols, true) ? 'company_id' : null,
            in_array('meli_account_id', $sCols, true) ? 'meli_account_id' : null,
            in_array('status', $sCols, true) ? 'status' : null,
            in_array('next_run_at', $sCols, true) ? 'next_run_at' : null,
            in_array('attempts', $sCols, true) ? 'attempts' : null,
            in_array('attempt_count', $sCols, true) ? 'attempt_count' : null,
            in_array('last_error_class', $sCols, true) ? 'last_error_class' : null,
            in_array('error_class', $sCols, true) ? 'error_class' : null,
            in_array('last_error_code', $sCols, true) ? 'last_error_code' : null,
            in_array('error_code', $sCols, true) ? 'error_code' : null,
            in_array('safe_message', $sCols, true) ? 'safe_message' : null,
        ], static fn ($v): bool => is_string($v) && $v !== '')));
        $out = [];
        $batchSize = (int) ($meta['source_batch_size'] ?? 100);
        $fetchStart = hrtime(true);
        foreach (array_chunk($sourceIds, max(1, $batchSize)) as $batch) {
            $meta['source_batch_queries'] = (int) $meta['source_batch_queries'] + 1;
            $stmt = $this->pdo()->prepare('SELECT ' . implode(',', array_map([$this, 'qi'], $select)) . ' FROM `sale_financial_reconciliation_jobs` WHERE `id` IN (' . implode(',', array_fill(0, count($batch), '?')) . ')');
            $stmt->execute($batch);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(string) ($row['id'] ?? '')] = $row;
            }
        }
        $meta['source_fetch_ms'] = (int) round((hrtime(true) - $fetchStart) / 1000000);
        return $out;
    }

    /** @param list<mixed> $queueJobIds @param array<string,mixed> $meta @return array<string,array{reached_remote:string,last_transport_endpoint:?string,last_http_status:mixed}> */
    private function lastFinanceTransportsForQueueJobs(array $queueJobIds, array &$meta): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map('strval', array_filter($queueJobIds, static fn ($id): bool => $id !== null && (string) $id !== ''))));
        if ($ids === [] || !$this->hasTable('queue_v4_clean_transport_events')) {
            return $out;
        }
        $cols = $this->columns('queue_v4_clean_transport_events');
        if (!in_array('job_id', $cols, true)) {
            return $out;
        }
        $select = [
            '`job_id`',
            in_array('endpoint_key', $cols, true) ? '`endpoint_key`' : 'NULL AS endpoint_key',
            in_array('http_status', $cols, true) ? '`http_status`' : 'NULL AS http_status',
            in_array('reached_remote', $cols, true) ? '`reached_remote`' : 'NULL AS reached_remote',
        ];
        $orderParts = ['`job_id` ASC'];
        if (in_array('created_at', $cols, true)) {
            $orderParts[] = '`created_at` DESC';
        }
        if (in_array('id', $cols, true)) {
            $orderParts[] = '`id` DESC';
        }
        try {
            foreach (array_chunk($ids, 200) as $batch) {
                $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM `queue_v4_clean_transport_events` WHERE `job_id` IN (' . implode(',', array_fill(0, count($batch), '?')) . ') ORDER BY ' . implode(',', $orderParts));
                $stmt->execute($batch);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $jobId = (string) ($row['job_id'] ?? '');
                    if ($jobId === '' || isset($out[$jobId])) {
                        continue;
                    }
                    $httpStatus = $row['http_status'] ?? null;
                    $reached = ($row['reached_remote'] ?? null);
                    $out[$jobId] = [
                        'reached_remote' => ((string) $reached === '1' || (is_numeric($httpStatus) && (int) $httpStatus > 0)) ? 'YES' : 'NO',
                        'last_transport_endpoint' => $row['endpoint_key'] !== null ? (string) $row['endpoint_key'] : null,
                        'last_http_status' => $httpStatus,
                    ];
                }
            }
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('finance_transport_lookup', $error);
        }
        return $out;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $billingBlock */
    private function classifyFinanceWaitingRow(array $row, string $generatedAt, array $billingBlock): string
    {
        $nextRun = strtotime((string) ($row['source_next_run_at'] ?? ''));
        $queueAvailable = strtotime((string) ($row['queue_available_at'] ?? ''));
        $now = strtotime($generatedAt) ?: time();
        if (!empty($row['source_id_invalid'])) {
            return 'FINANCE_SOURCE_ID_INVALID';
        }
        if (!empty($row['source_missing'])) {
            return 'FINANCE_SOURCE_MISSING';
        }
        if ($nextRun !== false && $nextRun > $now) {
            return 'FINANCE_NEXT_RUN_FUTURE';
        }

        $http = is_numeric($row['last_http_status'] ?? null) ? (int) $row['last_http_status'] : 0;
        $text = strtolower(json_encode([
            $row['source_status'] ?? null,
            $row['source_failure_class'] ?? null,
            $row['source_last_error_code'] ?? null,
            $row['source_safe_message'] ?? null,
            $row['queue_last_error_class'] ?? null,
            $row['queue_last_error_message'] ?? null,
            $row['last_transport_endpoint'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $billingIntervalText = str_contains($text, 'billing')
            && (str_contains($text, 'interval') || str_contains($text, 'permit') || str_contains($text, 'rhythm'));
        if ($queueAvailable !== false && $queueAvailable > $now) {
            return $billingIntervalText ? 'BILLING_ENDPOINT_INTERVAL' : 'OTHER';
        }

        if ($billingIntervalText) {
            return 'BILLING_ENDPOINT_INTERVAL';
        }
        if (str_contains($text, 'retry-after') || str_contains($text, 'retry_after')) {
            return 'REMOTE_RETRY_AFTER';
        }
        if ($http === 429 || str_contains($text, '429')) {
            return 'REMOTE_429';
        }
        if ($http >= 500 && $http <= 599) {
            return 'REMOTE_5XX';
        }
        if (str_contains($text, 'remote_uncertain') || str_contains($text, 'remote_result_uncertain') || str_contains($text, 'uncertain')) {
            return 'REMOTE_RESULT_UNCERTAIN';
        }
        if (($row['pack_integrity_status'] ?? null) !== null && (string) $row['pack_integrity_status'] !== 'complete') {
            return 'PACK_INCOMPLETE';
        }
        if (str_contains($text, 'pack_incomplete') || str_contains($text, 'expected_count') || str_contains($text, 'pack integrity')) {
            return 'PACK_INCOMPLETE';
        }
        if (str_contains($text, 'auth') || str_contains($text, 'oauth')) {
            return 'AUTH_ACTION_REQUIRED';
        }
        if (str_contains($text, 'not_due') || str_contains($text, 'not due') || str_contains($text, 'source_not_due')) {
            return 'SOURCE_NOT_DUE';
        }
        if (($billingBlock['active'] ?? false) === true) {
            return 'BILLING_ENDPOINT_INTERVAL';
        }
        $sourceStatus = strtolower((string) ($row['source_status'] ?? ''));
        if (in_array($sourceStatus, ['failed', 'error', 'review'], true) || str_contains($text, 'source_error')) {
            return 'SOURCE_ERROR';
        }
        if (in_array($sourceStatus, ['pending', 'retry', 'ready', 'waiting', 'running', 'awaiting_remote'], true)) {
            return 'READY_BUT_STILL_WAITING';
        }
        return 'OTHER';
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function billingEvidenceProjection(array $evidence): array
    {
        $state = (string) ($evidence['status'] ?? 'UNKNOWN');
        $number = static fn (string $key): ?int => isset($evidence[$key]) && is_int($evidence[$key])
            && $evidence[$key] >= 0 ? $evidence[$key] : null;
        $flag = static fn (string $key): string => $state !== 'ERROR' && is_bool($evidence[$key] ?? null)
            ? ($evidence[$key] ? 'YES' : 'NO') : 'UNKNOWN';
        return [
            'BILLING_429_EVIDENCE_STATE' => in_array($state, ['OK', 'UNKNOWN', 'ERROR'], true) ? $state : 'UNKNOWN',
            'BILLING_429_STREAK' => $state === 'OK' ? $number('streak') : null,
            'BILLING_429_BACKOFF_LEVEL' => $state === 'OK' ? $number('level') : null,
            'BILLING_429_BACKOFF_SECONDS' => $state !== 'ERROR' ? $number('backoff_seconds') : null,
            'BILLING_429_BACKOFF_ACTIVE' => $state !== 'ERROR' && is_bool($evidence['backoff_active'] ?? null)
                ? ($evidence['backoff_active'] ? 'YES' : 'NO') : 'UNKNOWN',
            'BILLING_429_RETRY_AFTER_SOURCE' => $state !== 'ERROR' ? ($evidence['retry_after_source'] ?? 'UNKNOWN') : 'UNKNOWN',
            'BILLING_429_RETRY_AFTER_SECONDS' => $state !== 'ERROR' ? $number('retry_after_seconds') : null,
            'BILLING_429_CORRELATION_KEY_AVAILABLE' => $flag('correlation_key_available'),
            'API_REQUEST_LOGS_HAS_RETRY_AFTER_SECONDS' => $flag('api_request_logs_has_retry_after_seconds'),
            'API_REMOTE_PERMITS_HAS_RETRY_AFTER_SECONDS' => $flag('api_remote_permits_has_retry_after_seconds'),
            'BILLING_429_RAW_EVENT_ROWS' => $number('raw_event_rows'),
            'BILLING_429_UNIQUE_PHYSICAL_EVENTS' => $state === 'OK' ? $number('unique_physical_events') : null,
            'BILLING_429_KNOWN_PHYSICAL_EVENTS' => $number('known_physical_events'),
            'BILLING_429_UNKNOWN_ROWS' => $number('unknown_rows'),
            'BILLING_429_DUPLICATE_ROWS_DEDUPED' => $number('duplicate_rows_deduped'),
        ];
    }

    private function billing429BackoffStateForDiagnostic(array &$meta): array
    {
        try {
            return (new ApiRhythmPolicyService())->billing429BackoffDiagnostic();
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('billing_429_backoff_state', $error);
            return [
                'status' => 'ERROR',
                'streak' => 0,
                'level' => 0,
                'backoff_seconds' => 0,
                'backoff_until' => null,
                'backoff_active' => false,
                'last_real_at' => null,
                'last_success_at' => null,
                'retry_after_source' => 'none',
                'retry_after_seconds' => 0,
            ];
        }
    }

    /** @param array<string,mixed> $meta @return array{active:bool,until:?string,remaining_seconds:int,source:string} */
    private function currentBillingBlockState(string $generatedAt, array &$meta): array
    {
        $now = strtotime($generatedAt . ' UTC');
        if ($now === false) {
            $now = time();
        }
        $billingPolicy = ApiRhythmPolicyService::billingPacingDiagnosticPolicy();
        $billingIntervalSeconds = max(1, (int) $billingPolicy['interval_seconds']);

        $state = [
            'active' => false,
            'until' => null,
            'remaining_seconds' => 0,
            'source' => 'NONE',
        ];

        try {
            $nextEpoch = 0;
            $source = 'NONE';

            if ($this->hasTable('api_remote_permits')) {
                $permitCols = $this->columns('api_remote_permits');
                if (in_array('endpoint_key', $permitCols, true) && in_array('dispatched_at', $permitCols, true)) {
                    $stmt = $this->pdo()->prepare(
                        'SELECT UNIX_TIMESTAMP(MAX(`dispatched_at`)) FROM `api_remote_permits` WHERE `endpoint_key`=? AND `dispatched_at` IS NOT NULL'
                    );
                    $stmt->execute(['billing_orders']);
                    $lastDispatch = (float) ($stmt->fetchColumn() ?: 0);
                    if ($lastDispatch > 0) {
                        $nextEpoch = max($nextEpoch, (int) ceil($lastDispatch + $billingIntervalSeconds));
                        $source = 'api_remote_permits.billing_orders.last_dispatch_plus_interval';
                    }
                }
            }

            if ($nextEpoch > $now) {
                $state['active'] = true;
                $state['until'] = gmdate('Y-m-d H:i:s', $nextEpoch);
                $state['remaining_seconds'] = max(0, $nextEpoch - $now);
                $state['source'] = $source;
            }
        } catch (Throwable $error) {
            $meta['warnings'][] = $this->diagnosticWarning('current_billing_block_state', $error);
        }

        return $state;
    }

    private function financeNextRunHorizonBucket(mixed $value, string $generatedAt): string
    {
        $nextRun = strtotime((string) $value);
        $now = strtotime($generatedAt) ?: time();
        if ($nextRun === false || $nextRun <= $now) {
            return 'DUE_NOW';
        }
        $minutes = (int) ceil(($nextRun - $now) / 60);
        if ($minutes <= 2) {
            return '0_TO_2_MIN';
        }
        if ($minutes <= 5) {
            return '2_TO_5_MIN';
        }
        if ($minutes <= 15) {
            return '5_TO_15_MIN';
        }
        if ($minutes <= 60) {
            return '15_TO_60_MIN';
        }
        if ($minutes <= 360) {
            return '1_TO_6_HOURS';
        }
        return 'OVER_6_HOURS';
    }

    /** @param array<string,mixed> $api @return array<string,int> */
    private function billingApiCounters(array $api, string $window): array
    {
        $empty = ['physical_http_calls' => 0, 'http_2xx' => 0, 'http_429' => 0, 'http_5xx' => 0, 'remote_uncertain' => 0];
        $family = $api['windows_by_family'][$window]['billing'] ?? [];
        if (!is_array($family)) {
            return $empty;
        }
        foreach ($empty as $key => $value) {
            $empty[$key] = (int) ($family[$key] ?? 0);
        }
        return $empty;
    }

    /** @return array{claimed:int,completed:int} */
    private function financeAttemptWindow(int $minutes): array
    {
        if (!$this->hasTable('queue_v4_clean_attempts') || !$this->hasTable('queue_v4_clean_jobs')) {
            return ['claimed' => 0, 'completed' => 0];
        }
        $aCols = $this->columns('queue_v4_clean_attempts');
        $qCols = $this->columns('queue_v4_clean_jobs');
        if (!in_array('job_id', $aCols, true) || !in_array('id', $qCols, true) || !in_array('payload_json', $qCols, true)) {
            return ['claimed' => 0, 'completed' => 0];
        }
        $timeCol = $this->firstExistingColumn($aCols, ['created_at', 'started_at', 'claimed_at', 'finished_at']);
        if ($timeCol === null) {
            return ['claimed' => 0, 'completed' => 0];
        }
        $outcomeCol = $this->firstExistingColumn($aCols, ['outcome', 'status', 'state']);
        $completedExpr = $outcomeCol !== null ? 'SUM(CASE WHEN a.' . $this->qi($outcomeCol) . ' = "completed" THEN 1 ELSE 0 END) AS completed' : '0 AS completed';
        $capabilityPredicates = ['JSON_UNQUOTE(JSON_EXTRACT(q.`payload_json`, "$.capability")) = ?'];
        $args = ['financial_reconciliation'];
        foreach (['capability', 'work_type', 'job_type'] as $column) {
            if (in_array($column, $qCols, true)) {
                $capabilityPredicates[] = 'q.' . $this->qi($column) . ' = ?';
                $args[] = 'financial_reconciliation';
            }
        }
        $sql = 'SELECT COUNT(*) AS claimed, ' . $completedExpr
            . ' FROM `queue_v4_clean_attempts` a'
            . ' INNER JOIN `queue_v4_clean_jobs` q ON q.`id` = a.`job_id`'
            . ' WHERE a.' . $this->qi($timeCol) . ' >= UTC_TIMESTAMP() - INTERVAL ' . max(1, $minutes) . ' MINUTE'
            . ' AND (' . implode(' OR ', $capabilityPredicates) . ')';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['claimed' => (int) ($row['claimed'] ?? 0), 'completed' => (int) ($row['completed'] ?? 0)];
    }

    /** @return array<string,mixed> */
    private function apiOutcomes(): array
    {
        if ($this->hasTable('queue_v4_clean_transport_events')) {
            return [
                'generated_at_utc' => $this->now(),
                'available' => true,
                'primary_authority' => 'queue_v4_clean_transport_events.endpoint_key',
                'fallback_authority' => $this->hasTable('api_request_logs') ? 'api_request_logs' : null,
                'latency_policy' => 'latency is null when physical_http_calls=0 in the selected window',
                'endpoint_families' => ['pack_exact', 'order_exact', 'orders_search', 'billing', 'oauth', 'other'],
                'windows_by_family' => [
                    '15m' => $this->transportOutcomeFamilyWindow(15),
                    '60m' => $this->transportOutcomeFamilyWindow(60),
                    '24h' => $this->transportOutcomeFamilyWindow(1440),
                ],
                'last_24h_by_status' => $this->transportStatusCounts(24),
                'last_30d_by_status' => $this->transportStatusCounts(720),
            ];
        }
        if (!$this->hasTable('api_request_logs')) {
            return ['generated_at_utc' => $this->now(), 'available' => false];
        }
        $columns = $this->columns('api_request_logs');
        $timeColumn = in_array('created_at', $columns, true) ? 'created_at' : (in_array('logged_at', $columns, true) ? 'logged_at' : null);
        $statusColumn = in_array('http_status', $columns, true) ? 'http_status' : (in_array('status_code', $columns, true) ? 'status_code' : null);
        if ($timeColumn === null || $statusColumn === null) {
            return ['generated_at_utc' => $this->now(), 'available' => false, 'reason' => 'required_columns_missing'];
        }
        return [
            'generated_at_utc' => $this->now(),
            'available' => true,
            'latency_policy' => 'latency is null when physical_http_calls=0 in the selected window',
            'endpoint_families' => ['pack_exact', 'order_exact', 'orders_search', 'billing', 'oauth', 'other'],
            'windows_by_family' => [
                '15m' => $this->apiOutcomeFamilyWindow($timeColumn, $statusColumn, 15),
                '60m' => $this->apiOutcomeFamilyWindow($timeColumn, $statusColumn, 60),
                '24h' => $this->apiOutcomeFamilyWindow($timeColumn, $statusColumn, 1440),
            ],
            'last_24h_by_status' => $this->apiStatusCounts($timeColumn, $statusColumn, 24),
            'last_30d_by_status' => $this->apiStatusCounts($timeColumn, $statusColumn, 720),
        ];
    }

    /** @return array<string,mixed> */
    private function throughput(): array
    {
        return [
            'generated_at_utc' => $this->now(),
            'windows' => [
                '15m' => $this->attemptWindow(15),
                '60m' => $this->attemptWindow(60),
                '120m' => $this->attemptWindow(120),
            ],
        ];
    }

    /** @return list<array<string,string>> */
    private function metricDefinitions(): array
    {
        $defs = [
            ['QUEUE_READY', 'Current rows in queue_v4_clean_jobs with state=ready.', 'queue_v4_clean_jobs.state', 'snapshot', 'instant'],
            ['QUEUE_CONTROL_UNIT', 'Current Queue V4 execution budget unit. Cron and Manual capacity are limited by physical Mercado Libre API calls, not by local queue jobs.', 'QueueV4CleanCycleBudget + QueueV4CleanDispatchFence', 'snapshot', 'instant'],
            ['QUEUE_CRON_MAX_CALLS', 'ERP setting authority for natural cron capacity per cycle.', 'automation.max_api_calls_per_cycle', 'snapshot', 'instant'],
            ['QUEUE_ADVANCED_MAX_CALLS_OVERRIDE', 'Technical support override only; not the primary Hostinger command.', 'jobs/queue_v4_clean.php --max-calls', 'snapshot', 'instant'],
            ['QUEUE_LEGACY_MAX_JOBS_ARGUMENT', 'The old --max-jobs argument is rejected; capacity is configured only as physical API calls.', 'jobs/queue_v4_clean.php argument parser', 'snapshot', 'instant'],
            ['QUEUE_WAITING', 'Current rows in queue_v4_clean_jobs with state=waiting.', 'queue_v4_clean_jobs.state', 'snapshot', 'instant'],
            ['QUEUE_REVIEW', 'Current rows in queue_v4_clean_jobs with state=review.', 'queue_v4_clean_jobs.state', 'snapshot', 'instant'],
            ['BROAD_UNKNOWN_PACKS', 'Packs whose local expected child order authority is unknown or empty.', 'meli_packs expected-count/expected-orders authority', 'snapshot', 'instant'],
            ['BLOCKING_UNKNOWN_PACKS', 'Unknown packs actually referenced by current Finance waiting sources/jobs whose blocker is pack integrity/expected-count unknown.', 'current Finance source -> local order -> meli_pack_orders -> meli_packs', 'snapshot', 'instant'],
            ['ACTIONABLE_UNKNOWN_PACKS', 'Blocking unknown packs that have a current canonical pack enrichment source usable under order enrichment semantics.', 'order_resource_enrichment_jobs resource_type=pack', 'snapshot', 'instant'],
            ['BLOCKING_PACKS_WITH_SOURCE', 'Blocking unknown packs with a usable canonical pack enrichment source.', 'order_resource_enrichment_jobs resource_type=pack', 'snapshot', 'instant'],
            ['BLOCKING_PACKS_WITHOUT_SOURCE', 'Blocking unknown packs without a usable canonical pack enrichment source.', 'order_resource_enrichment_jobs resource_type=pack', 'snapshot', 'instant'],
            ['PACK_SOURCE_COVERAGE_PERCENT', 'BLOCKING_PACKS_WITH_SOURCE / BLOCKING_UNKNOWN_PACKS * 100.', '11_PACK_SOURCE_COVERAGE.json', 'snapshot', 'instant'],
            ['PACK_QUEUE_CLAIMED', 'Pack-family Queue V4 attempts claimed in the window.', 'queue_v4_clean_attempts capability=order_enrichment_pack or queue pointer payload capability', 'event', 'windowed'],
            ['PACK_QUEUE_COMPLETED', 'Pack-family Queue V4 attempts completed in the window.', 'queue_v4_clean_attempts outcome', 'event', 'windowed'],
            ['PACK_PHYSICAL_HTTP', 'Pack-family transport events that reached a real remote HTTP call.', 'queue_v4_clean_transport_events.endpoint_key=pack_exact with physical response evidence; api_request_logs only fallback', 'event', 'windowed'],
            ['PACK_RESPONSE_KNOWN', 'Pack-family remote attempts whose response was classified as known/decidable.', 'queue_v4_clean_attempts + transport events', 'event', 'windowed'],
            ['PACK_EXPECTED_COUNT_RESOLVED', 'Packs whose expected child order count transitioned from unknown to known.', 'meli_packs expected-count authority', 'transition', 'windowed'],
            ['PACK_COMPLETE', 'Packs whose integrity status is complete after all child orders are locally linked.', 'meli_packs.integrity_status', 'transition', 'windowed'],
            ['REMOTE_429', 'Real remote HTTP 429 responses only; local policy/rhythm defers are excluded.', 'api_request_logs/http_status or transport events', 'event', 'windowed'],
            ['REMOTE_5XX', 'Real remote HTTP 5xx responses only.', 'api_request_logs/http_status or transport events', 'event', 'windowed'],
            ['REMOTE_UNCERTAIN', 'Remote attempt where the local process cannot prove the response outcome.', 'queue_v4_clean_attempts failure/error class', 'event', 'windowed'],
            ['WAITING_IN', 'Rows moved into waiting during the window.', 'queue_v4_clean_attempts/outcome or state transition evidence', 'transition', 'windowed'],
            ['WAITING_OUT', 'Rows leaving waiting during the window.', 'queue_v4_clean_attempts/outcome or state transition evidence', 'transition', 'windowed'],
            ['WAITING_NET', 'WAITING_IN - WAITING_OUT during the window.', 'derived from WAITING_IN and WAITING_OUT', 'transition', 'windowed'],
            ['FINANCE_WAITING_TOTAL', 'Current waiting Queue V4 rows whose canonical queue payload points to a sale financial reconciliation source.', 'queue_v4_clean_jobs payload_json.source_id -> sale_financial_reconciliation_jobs.id', 'snapshot', 'instant'],
            ['FINANCE_WAITING_REASON_COUNTS', 'Finance waiting classified only from actual source-domain truth and recent transport evidence, never from capability name alone.', 'sale_financial_reconciliation_jobs + queue_v4_clean_transport_events', 'snapshot', 'instant'],
            ['BILLING_INTERVAL_SECONDS', 'Current local preventive minimum interval for Billing physical HTTP calls.', 'App\\Services\\ApiRhythmPolicyService::billingPacingDiagnosticPolicy().interval_seconds', 'snapshot', 'instant'],
            ['BILLING_INTERVAL_SCOPE', 'Scope affected by one successful Billing physical HTTP call.', 'App\\Services\\ApiRhythmPolicyService::billingPacingDiagnosticPolicy().scope', 'snapshot', 'instant'],
            ['BILLING_ORDER_IDS_PER_CALL_LIMIT', 'Maximum order IDs sent in a single Billing physical HTTP call.', 'App\\Services\\SaleFinancialService::billingOrderIdsPerCallLimit()', 'snapshot', 'instant'],
            ['BILLING_POLICY_FILE_SHA256', 'SHA-256 of the runtime ApiRhythmPolicyService.php file used as Billing policy authority.', 'hash_file(sha256, App\\Services\\ApiRhythmPolicyService.php)', 'snapshot', 'instant'],
            ['BILLING_INTERVAL_DEFER', 'Current Finance waiting rows whose source is due but Queue availability is held by Billing endpoint interval.', 'FINANCE_WAITING_REASON_COUNTS.BILLING_ENDPOINT_INTERVAL', 'snapshot', 'instant'],
            ['FINANCE_NEXT_RUN_HORIZON', 'Buckets current finance waiting rows by the source next_run_at relative to bundle generation time.', 'sale_financial_reconciliation_jobs.next_run_at', 'snapshot', 'instant'],
            ['FINANCE_WAKEUP', 'Current snapshot of Finance waiting rows: due candidates, ready released rows, future skips, blocked skips, and diagnostic errors.', 'queue_v4_clean_jobs + sale_financial_reconciliation_jobs', 'snapshot', 'instant'],
            ['FINANCE_WAKE_RUNTIME_15M', 'Actual natural scheduler executions of releaseDueWaiting() observed in Queue V4 receipt JSONL over 15 minutes.', 'storage/queue-v4-audit/*.jsonl finance_wakeup_runtime', 'event', 'windowed'],
            ['FINANCE_WAKE_RUNTIME_60M', 'Actual natural scheduler executions of releaseDueWaiting() observed in Queue V4 receipt JSONL over 60 minutes.', 'storage/queue-v4-audit/*.jsonl finance_wakeup_runtime', 'event', 'windowed'],
            ['BILLING_PHYSICAL_HTTP', 'Billing-family transport events keyed by endpoint_key=billing_orders or other billing/invoice endpoint keys.', 'queue_v4_clean_transport_events.endpoint_key', 'event', 'windowed'],
        ];
        return array_map(static fn (array $row): array => [
            'metric_name' => $row[0],
            'definition' => $row[1],
            'db_or_source_authority' => $row[2],
            'measurement_type' => $row[3],
            'time_window_semantics' => $row[4],
        ], $defs);
    }

    /** @return array{runs:int,candidates:int,released:int,skipped_future:int,skipped_blocked:int,errors:int,latest_at:?string} */
    private function financeWakeupRuntimeWindow(int $minutes): array
    {
        $out = [
            'runs' => 0,
            'candidates' => 0,
            'released' => 0,
            'skipped_future' => 0,
            'skipped_blocked' => 0,
            'errors' => 0,
            'latest_at' => null,
        ];
        $dir = AppPaths::storage(self::BASE_RECEIPT_DIR);
        $cutoff = time() - (max(1, $minutes) * 60);
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $date) {
            $file = $dir . '/' . $date . '.jsonl';
            if (!is_file($file)) {
                continue;
            }
            $fileLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($fileLines, -500) as $line) {
                $receipt = json_decode((string) $line, true);
                if (!is_array($receipt)) {
                    continue;
                }
                $startedAt = (string) (($receipt['started_at'] ?? null) ?: ($receipt['ended_at'] ?? ''));
                $startedTs = strtotime($startedAt . ' UTC');
                if ($startedTs === false || $startedTs < $cutoff) {
                    continue;
                }
                $runtime = $receipt['finance_wakeup_runtime'] ?? null;
                if (!is_array($runtime) || empty($runtime['ran'])) {
                    continue;
                }
                $out['runs']++;
                $out['candidates'] += (int) ($runtime['candidates'] ?? 0);
                $out['released'] += (int) ($runtime['released'] ?? 0);
                $out['skipped_future'] += (int) ($runtime['skipped_future'] ?? 0);
                $out['skipped_blocked'] += (int) ($runtime['skipped_blocked'] ?? 0);
                $out['errors'] += (int) ($runtime['errors'] ?? 0);
                if ($out['latest_at'] === null || strcmp($startedAt, $out['latest_at']) > 0) {
                    $out['latest_at'] = $startedAt;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function cronReceiptLines(): array
    {
        $lines = [];
        $dir = AppPaths::storage(self::BASE_RECEIPT_DIR);
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $date) {
            $file = $dir . '/' . $date . '.jsonl';
            if (!is_file($file)) {
                continue;
            }
            $fileLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($fileLines, -200) as $line) {
                $decoded = json_decode((string) $line, true);
                $lines[] = is_array($decoded) ? json_encode($this->sanitizeArray($this->enrichReceiptFromQueueEvidence($decoded)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}' : '{}';
            }
        }
        return array_slice($lines, -300);
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function enrichReceiptFromQueueEvidence(array $receipt): array
    {
        if (!$this->hasTable('queue_v4_clean_transport_events')) {
            return $receipt;
        }
        $text = strtolower(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        if (!str_contains($text, 'order_enrichment_pack') && !str_contains($text, 'pack_exact')) {
            return $receipt;
        }
        $cols = $this->columns('queue_v4_clean_transport_events');
        if (!in_array('endpoint_key', $cols, true)) {
            return $receipt;
        }
        $timeCol = $this->firstExistingColumn($cols, ['created_at', 'response_known_at', 'finished_at', 'started_at']);
        $statusCol = $this->firstExistingColumn($cols, ['http_status', 'status_code']);
        if ($timeCol === null || $statusCol === null) {
            return $receipt;
        }
        $attemptId = $this->firstScalarFromNested($receipt, ['attempt_id', 'queue_attempt_id']);
        $jobId = $this->firstScalarFromNested($receipt, ['job_id', 'queue_job_id']);
        if ($attemptId === null && $jobId === null) {
            return $receipt;
        }
        $select = ['endpoint_key', $statusCol . ' AS http_status'];
        foreach (['reached_remote', 'attempt_id', 'job_id', 'event_type', 'created_at', 'response_known_at'] as $col) {
            if (in_array($col, $cols, true) && !in_array($col, $select, true)) {
                $select[] = $col;
            }
        }
        $where = ['endpoint_key="pack_exact"', $this->qi($statusCol) . ' IS NOT NULL'];
        $args = [];
        if ($attemptId !== null && in_array('attempt_id', $cols, true)) {
            $where[] = 'CAST(attempt_id AS CHAR)=?';
            $args[] = (string) $attemptId;
        } elseif ($jobId !== null && in_array('job_id', $cols, true)) {
            $where[] = 'CAST(job_id AS CHAR)=?';
            $args[] = (string) $jobId;
        } else {
            return $receipt;
        }
        try {
            $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_transport_events WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $this->qi($timeCol) . ' DESC LIMIT 1');
            $stmt->execute($args);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $row = false;
        }
        if (!is_array($row)) {
            return $receipt;
        }
        $status = (int) ($row['http_status'] ?? 0);
        $receipt['_diagnostic_correlation'] = [
            'authority' => 'queue_v4_clean_transport_events.endpoint_key=pack_exact',
            'physical_http_calls' => 1,
            'reached_remote' => true,
            'http_status' => $status,
            'endpoint_key' => 'pack_exact',
            'response_known' => $status > 0,
        ];
        return $receipt;
    }

    /** @return list<string> */
    private function queueAttemptLines(): array
    {
        if (!$this->hasTable('queue_v4_clean_attempts')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_attempts');
        $select = [];
        foreach (['id','job_id','run_id','company_id','meli_account_id','outcome','error_class','dispatch_state','transport_method','endpoint_key','physical_http_calls','http_status','started_at','finished_at','next_safe_at','created_at'] as $col) {
            if (in_array($col, $cols, true)) {
                $select[] = $col;
            }
        }
        $orderCol = in_array('started_at', $cols, true) ? 'started_at' : (in_array('created_at', $cols, true) ? 'created_at' : (in_array('id', $cols, true) ? 'id' : null));
        if ($select === [] || $orderCol === null) {
            return [];
        }
        $stmt = $this->pdo()->query('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_attempts ORDER BY ' . $orderCol . ' DESC LIMIT 500');
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $lines[] = json_encode($this->sanitizeArray($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        return $lines;
    }

    /** @return list<string> */
    private function transportEventLines(): array
    {
        if (!$this->hasTable('queue_v4_clean_transport_events')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_transport_events');
        $select = array_slice(array_values(array_intersect($cols, [
            'id','attempt_id','job_id','company_id','meli_account_id','event_type','endpoint_key','http_status','method','origin','created_at','started_at','finished_at','reached_remote','response_known_at'
        ])), 0, 16);
        if ($select === []) {
            return [];
        }
        $orderCol = in_array('created_at', $cols, true) ? 'created_at' : (in_array('id', $cols, true) ? 'id' : $select[0]);
        $stmt = $this->pdo()->query('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_transport_events ORDER BY ' . $orderCol . ' DESC LIMIT 500');
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $lines[] = json_encode($this->sanitizeArray($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        return $lines;
    }

    /** @return array<string,mixed> */
    private function controlRow(): array
    {
        if (!$this->hasTable('queue_v4_clean_control')) {
            return ['available' => false];
        }
        try {
            $row = $this->pdo()->query("SELECT * FROM queue_v4_clean_control WHERE control_key='primary' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            return $this->sanitizeArray($row);
        } catch (Throwable $error) {
            return ['available' => false, 'error' => get_class($error)];
        }
    }

    /** @return list<array{key:string,count:int}> */
    private function groupCounts(string $table, string $column): array
    {
        if (!$this->hasTable($table) || !$this->hasColumn($table, $column)) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT ' . $column . ' AS k, COUNT(*) AS c FROM ' . $table . ' GROUP BY ' . $column . ' ORDER BY c DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['key' => (string) $r['k'], 'count' => (int) $r['c']], $rows);
    }

    /** @return array<string,array<string,int>> */
    private function queueByWorkType(): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_jobs');
        $typeCol = in_array('work_type', $cols, true) ? 'work_type' : (in_array('job_type', $cols, true) ? 'job_type' : null);
        if ($typeCol === null || !in_array('state', $cols, true)) {
            return [];
        }
        $rows = $this->pdo()->query('SELECT ' . $typeCol . ' AS t, state, COUNT(*) AS c FROM queue_v4_clean_jobs GROUP BY ' . $typeCol . ',state ORDER BY c DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['t'] ?? 'unknown');
            $state = (string) ($row['state'] ?? 'unknown');
            $out[$type][$state] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function queueTypeLikeCounts(array $terms): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_jobs');
        $typeCol = in_array('work_type', $cols, true) ? 'work_type' : (in_array('job_type', $cols, true) ? 'job_type' : null);
        if ($typeCol === null || !in_array('state', $cols, true)) {
            return [];
        }
        $predicates = [];
        $args = [];
        foreach ($terms as $term) {
            $predicates[] = $typeCol . ' LIKE ?';
            $args[] = '%' . $term . '%';
            if (in_array('payload_json', $cols, true)) {
                $predicates[] = 'payload_json LIKE ?';
                $args[] = '%' . $term . '%';
            }
            if (in_array('source_table', $cols, true)) {
                $predicates[] = 'source_table LIKE ?';
                $args[] = '%' . $term . '%';
            }
        }
        $stmt = $this->pdo()->prepare('SELECT state, COUNT(*) AS c FROM queue_v4_clean_jobs WHERE ' . implode(' OR ', $predicates) . ' GROUP BY state');
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['state']] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function queueCapabilityCounts(string $capability): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasColumn('queue_v4_clean_jobs', 'state')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_jobs');
        $predicates = [];
        $args = [];
        foreach (['capability', 'job_type', 'work_type'] as $col) {
            if (in_array($col, $cols, true)) {
                $predicates[] = $col . '=?';
                $args[] = $capability;
            }
        }
        if (in_array('payload_json', $cols, true)) {
            $predicates[] = 'payload_json LIKE ?';
            $args[] = '%"' . $capability . '"%';
            $predicates[] = 'payload_json LIKE ?';
            $args[] = '%' . $capability . '%';
        }
        if ($predicates === []) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT state, COUNT(*) AS c FROM queue_v4_clean_jobs WHERE (' . implode(' OR ', $predicates) . ') GROUP BY state');
        $stmt->execute($args);
        $out = ['ready' => 0, 'running' => 0, 'waiting' => 0, 'review' => 0, 'completed' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['state']] = (int) $row['c'];
        }
        return $out;
    }

    /** @return array<string,int> */
    private function stateReasonCounts(string $state): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasColumn('queue_v4_clean_jobs', 'state')) {
            return [];
        }
        $columns = $this->columns('queue_v4_clean_jobs');
        $select = [];
        foreach (['job_type','work_type','source_table','state','available_at','attempt_count','last_error_class','last_error_message','safe_error_code','payload_json','created_at','updated_at'] as $col) {
            if (in_array($col, $columns, true)) {
                $select[] = $col;
            }
        }
        if ($select === []) {
            return [];
        }
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_jobs WHERE state=?');
        $stmt->execute([$state]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reason = $this->classifyWaiting($row);
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /** @return array{sql:string,warnings:list<string>} */
    private function packUnknownWhere(array $packCols, ?string $integrityCol, ?string $expectedJsonCol, ?string $expectedCountCol, string $prefix = ''): array
    {
        $where = [];
        $warnings = [];
        if ($integrityCol !== null) {
            $col = $prefix . $this->qi($integrityCol);
            $where[] = '(' . $col . ' IS NULL OR ' . $col . ' NOT IN ("complete","completed","ok"))';
        }
        if ($expectedJsonCol !== null || $expectedCountCol !== null) {
            $unknown = [];
            if ($expectedJsonCol !== null) {
                $col = $prefix . $this->qi($expectedJsonCol);
                $unknown[] = '(' . $col . ' IS NULL OR ' . $col . '="" OR ' . $col . '="[]" OR ' . $col . '="null")';
            }
            if ($expectedCountCol !== null) {
                $col = $prefix . $this->qi($expectedCountCol);
                $unknown[] = '(' . $col . ' IS NULL OR ' . $col . '<=0)';
            }
            $where[] = '(' . implode(' OR ', $unknown) . ')';
        } else {
            $warnings[] = 'expected_count_authority_columns_missing_broad_unknown_count_may_include_all_incomplete_packs';
        }
        return ['sql' => implode(' AND ', $where), 'warnings' => $warnings];
    }

    /** @return array<string,array<string,mixed>> */
    private function blockingFinanceCountsByPack(): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasTable('meli_packs') || !$this->hasTable('meli_pack_orders')) {
            return [];
        }
        $qCols = $this->columns('queue_v4_clean_jobs');
        $pCols = $this->columns('meli_packs');
        $poCols = $this->columns('meli_pack_orders');
        if (!in_array('state', $qCols, true) || !in_array('source_table', $qCols, true) || !in_array('source_id', $qCols, true)) {
            return [];
        }

        $packPk = $this->firstExistingColumn($pCols, ['id', 'pack_id', 'meli_pack_id', 'external_pack_id']);
        $packExternal = $this->firstExistingColumn($pCols, ['external_pack_id', 'pack_id', 'meli_pack_id']);
        $packAccount = $this->firstExistingColumn($pCols, ['meli_account_id', 'account_id']);
        $packCompany = $this->firstExistingColumn($pCols, ['company_id']);
        $integrityCol = $this->firstExistingColumn($pCols, ['integrity_status', 'pack_integrity_status']);
        $expectedJsonCol = $this->firstExistingColumn($pCols, ['expected_orders_json', 'expected_order_ids_json', 'expected_children_json']);
        $expectedCountCol = $this->firstExistingColumn($pCols, ['expected_order_count', 'expected_orders_count', 'expected_count']);
        $poPack = $this->firstExistingColumn($poCols, ['pack_id', 'meli_pack_id', 'external_pack_id']);
        $poOrder = $this->firstExistingColumn($poCols, ['order_id', 'meli_order_id', 'external_order_id', 'local_order_id']);
        $poAccount = $this->firstExistingColumn($poCols, ['meli_account_id', 'account_id']);
        $poCompany = $this->firstExistingColumn($poCols, ['company_id']);
        if ($packPk === null || $poPack === null || $poOrder === null) {
            return [];
        }

        $unknownWhere = $this->packUnknownWhere($pCols, $integrityCol, $expectedJsonCol, $expectedCountCol, 'p.');
        $financeTables = ['order_financial_recalc_jobs', 'order_financial_reconciliation_jobs', 'financial_reconciliation_jobs', 'sales_financial_reconciliation_jobs'];
        foreach ($financeTables as $financeTable) {
            if (!$this->hasTable($financeTable)) {
                continue;
            }
            $fCols = $this->columns($financeTable);
            $financePk = $this->firstExistingColumn($fCols, ['id', 'job_id']);
            $financeOrder = $this->firstExistingColumn($fCols, ['order_id', 'local_order_id', 'meli_order_id', 'external_order_id']);
            $financeAccount = $this->firstExistingColumn($fCols, ['meli_account_id', 'account_id']);
            $financeCompany = $this->firstExistingColumn($fCols, ['company_id']);
            if ($financePk === null || $financeOrder === null) {
                continue;
            }

            $join = [
                'q.' . $this->qi('source_table') . '=?',
                'CAST(q.' . $this->qi('source_id') . ' AS CHAR)=CAST(f.' . $this->qi($financePk) . ' AS CHAR)',
                'CAST(po.' . $this->qi($poOrder) . ' AS CHAR)=CAST(f.' . $this->qi($financeOrder) . ' AS CHAR)',
                'CAST(p.' . $this->qi($packPk) . ' AS CHAR)=CAST(po.' . $this->qi($poPack) . ' AS CHAR)',
            ];
            if ($packExternal !== null && $packExternal !== $packPk) {
                $join[3] = '(CAST(p.' . $this->qi($packPk) . ' AS CHAR)=CAST(po.' . $this->qi($poPack) . ' AS CHAR) OR CAST(p.' . $this->qi($packExternal) . ' AS CHAR)=CAST(po.' . $this->qi($poPack) . ' AS CHAR))';
            }
            if ($packAccount !== null && $financeAccount !== null) {
                $join[] = 'p.' . $this->qi($packAccount) . '=f.' . $this->qi($financeAccount);
            }
            if ($packCompany !== null && $financeCompany !== null) {
                $join[] = 'p.' . $this->qi($packCompany) . '=f.' . $this->qi($financeCompany);
            }
            if ($packAccount !== null && $poAccount !== null) {
                $join[] = 'p.' . $this->qi($packAccount) . '=po.' . $this->qi($poAccount);
            }
            if ($packCompany !== null && $poCompany !== null) {
                $join[] = 'p.' . $this->qi($packCompany) . '=po.' . $this->qi($poCompany);
            }
            $where = ['q.' . $this->qi('state') . ' IN ("ready","waiting","running")'];
            if ($unknownWhere['sql'] !== '') {
                $where[] = $unknownWhere['sql'];
            }
            $select = [
                'p.' . $this->qi($packPk) . ' AS ' . $this->qi($packPk),
                $packExternal !== null ? 'p.' . $this->qi($packExternal) . ' AS ' . $this->qi($packExternal) : 'p.' . $this->qi($packPk) . ' AS external_pack_identity',
            ];
            if ($packAccount !== null) {
                $select[] = 'p.' . $this->qi($packAccount) . ' AS ' . $this->qi($packAccount);
            }
            if ($packCompany !== null) {
                $select[] = 'p.' . $this->qi($packCompany) . ' AS ' . $this->qi($packCompany);
            }
            $select[] = 'COUNT(DISTINCT q.' . $this->qi('id') . ') AS _blocking_finance_count';
            $group = ['p.' . $this->qi($packPk)];
            if ($packExternal !== null) {
                $group[] = 'p.' . $this->qi($packExternal);
            }
            if ($packAccount !== null) {
                $group[] = 'p.' . $this->qi($packAccount);
            }
            if ($packCompany !== null) {
                $group[] = 'p.' . $this->qi($packCompany);
            }
            $sql = 'SELECT ' . implode(',', $select)
                . ' FROM queue_v4_clean_jobs q'
                . ' JOIN ' . $this->qi($financeTable) . ' f ON ' . implode(' AND ', array_slice($join, 0, 2))
                . ' JOIN meli_pack_orders po ON ' . $join[2]
                . ' JOIN meli_packs p ON ' . implode(' AND ', array_slice($join, 3))
                . ' WHERE ' . implode(' AND ', $where)
                . ' GROUP BY ' . implode(',', $group)
                . ' ORDER BY _blocking_finance_count DESC LIMIT 5000';
            try {
                $stmt = $this->pdo()->prepare($sql);
                $stmt->execute([$financeTable]);
                $out = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $key = (string) ($row[$packPk] ?? $row[$packExternal ?? $packPk] ?? count($out));
                    $out[$key] = $row;
                }
                return $out;
            } catch (Throwable) {
                continue;
            }
        }
        return [];
    }

    private function packHasLocalOrderIdentity(string $packPk, string $packIdentity): bool
    {
        if (!$this->hasTable('meli_pack_orders')) {
            return false;
        }
        $cols = $this->columns('meli_pack_orders');
        $packCol = $this->firstExistingColumn($cols, ['pack_id', 'meli_pack_id', 'external_pack_id']);
        $orderCol = $this->firstExistingColumn($cols, ['order_id', 'meli_order_id', 'external_order_id', 'local_order_id']);
        if ($packCol === null || $orderCol === null) {
            return false;
        }
        $candidates = array_values(array_filter(array_unique([$packPk, $packIdentity]), static fn (string $v): bool => $v !== ''));
        if ($candidates === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM meli_pack_orders WHERE ' . $packCol . ' IN (' . $placeholders . ') AND ' . $orderCol . ' IS NOT NULL LIMIT 1');
        $stmt->execute($candidates);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string,mixed>|null */
    private function findPackQueueSource(string $packIdentity, string $packPk, string $accountValue, string $companyValue): ?array
    {
        return $this->findPackDomainSource($packIdentity, $packPk, $accountValue, $companyValue);
    }

    /** @return array<string,mixed>|null */
    private function findPackDomainSource(string $packIdentity, string $packPk, string $accountValue, string $companyValue): ?array
    {
        if (!$this->hasTable('order_resource_enrichment_jobs')) {
            return null;
        }
        $cols = $this->columns('order_resource_enrichment_jobs');
        $identityCol = $this->firstExistingColumn($cols, ['external_resource_id', 'resource_id', 'meli_resource_id', 'external_id']);
        $typeCol = $this->firstExistingColumn($cols, ['resource_type', 'type']);
        if ($identityCol === null || $typeCol === null) {
            return null;
        }
        $stateCol = $this->firstExistingColumn($cols, ['status', 'state']);
        $availableCol = $this->firstExistingColumn($cols, ['next_run_at', 'available_at', 'next_safe_at']);
        $attemptCol = $this->firstExistingColumn($cols, ['attempt_count', 'attempts']);
        $errorCol = $this->firstExistingColumn($cols, ['last_error_class', 'error_class', 'last_error_code']);
        $accountCol = $this->firstExistingColumn($cols, ['meli_account_id', 'account_id']);
        $companyCol = $this->firstExistingColumn($cols, ['company_id']);
        $select = array_values(array_filter(array_unique([
            $this->firstExistingColumn($cols, ['id']),
            $accountCol,
            $companyCol,
            $typeCol,
            $identityCol,
            $stateCol,
            $availableCol,
            $attemptCol,
            $errorCol,
            $this->firstExistingColumn($cols, ['created_at']),
            $this->firstExistingColumn($cols, ['updated_at']),
        ])));
        $ids = array_values(array_filter(array_unique([$packIdentity, $packPk]), static fn (string $v): bool => $v !== ''));
        if ($select === [] || $ids === []) {
            return null;
        }
        $predicates = ['LOWER(CAST(' . $this->qi($typeCol) . ' AS CHAR))=?', $this->qi($identityCol) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
        $args = ['pack', ...$ids];
        if ($accountCol !== null && $accountValue !== '') {
            $predicates[] = $this->qi($accountCol) . '=?';
            $args[] = $accountValue;
        }
        if ($companyCol !== null && $companyValue !== '') {
            $predicates[] = $this->qi($companyCol) . '=?';
            $args[] = $companyValue;
        }
        $fallbackOrder = $this->firstExistingColumn($cols, ['updated_at', 'id']);
        $order = $availableCol !== null ? $this->qi($availableCol) . ' ASC' : ($fallbackOrder !== null ? $this->qi($fallbackOrder) . ' DESC' : '1');
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', array_map([$this, 'qi'], $select)) . ' FROM order_resource_enrichment_jobs WHERE ' . implode(' AND ', $predicates) . ' ORDER BY ' . $order . ' LIMIT 1');
        try {
            $stmt->execute($args);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $row['source_identity'] = (string) ($row['id'] ?? ($row['source_id'] ?? 'source'));
            $row['state'] = $stateCol !== null ? (string) ($row[$stateCol] ?? '') : '';
            $row['available_at'] = $availableCol !== null ? ($row[$availableCol] ?? null) : null;
            $row['attempt_count'] = $attemptCol !== null ? ($row[$attemptCol] ?? null) : null;
            $row['last_error_class'] = $errorCol !== null ? ($row[$errorCol] ?? null) : null;
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $source */
    private function isUsablePackDomainSource(array $source): bool
    {
        $state = strtolower((string) ($source['state'] ?? ''));
        return in_array($state, ['pending', 'retry', 'ready', 'waiting', 'running', 'queued'], true);
    }

    /** @param array<string,mixed> $source */
    private function isClaimablePackDomainSource(array $source): bool
    {
        if (!$this->isUsablePackDomainSource($source)) {
            return false;
        }
        $availableAt = (string) ($source['available_at'] ?? '');
        if ($availableAt === '') {
            return true;
        }
        $ts = strtotime($availableAt . ' UTC');
        return $ts === false || $ts <= time();
    }

    private function blockingFinanceCountForPack(string $packIdentity, string $packPk, string $accountValue): int
    {
        foreach ($this->blockingFinanceCountsByPack() as $row) {
            $text = implode('|', array_map(static fn ($v): string => is_scalar($v) || $v === null ? (string) $v : '', $row));
            if (($packIdentity !== '' && str_contains($text, $packIdentity)) || ($packPk !== '' && str_contains($text, $packPk))) {
                return (int) ($row['_blocking_finance_count'] ?? 0);
            }
        }
        return 0;
    }

    private function stateCount(string $state): int
    {
        if (!$this->hasTable('queue_v4_clean_jobs') || !$this->hasColumn('queue_v4_clean_jobs', 'state')) {
            return 0;
        }
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state=?');
        $stmt->execute([$state]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function stateRows(string $state, int $limit): array
    {
        if (!$this->hasTable('queue_v4_clean_jobs')) {
            return [];
        }
        $cols = $this->columns('queue_v4_clean_jobs');
        $select = array_values(array_intersect(['id','company_id','meli_account_id','job_type','work_type','source_table','source_id','state','available_at','attempt_count','last_error_class','created_at','updated_at'], $cols));
        if ($select === []) {
            return [];
        }
        $order = in_array('updated_at', $cols, true) ? 'updated_at DESC' : (in_array('id', $cols, true) ? 'id DESC' : '1');
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_jobs WHERE state=? ORDER BY ' . $order . ' LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute([$state]);
        return array_map(fn (array $row): array => $this->sanitizeJobRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    private function attemptWindow(int $minutes): array
    {
        if (!$this->hasTable('queue_v4_clean_attempts')) {
            return ['claimed' => 0, 'completed' => 0, 'deferred' => 0, 'completion_ratio' => 0.0, 'defer_rate' => 0.0];
        }
        $cols = $this->columns('queue_v4_clean_attempts');
        $timeCol = in_array('started_at', $cols, true) ? 'started_at' : (in_array('created_at', $cols, true) ? 'created_at' : null);
        $outcomeCol = in_array('outcome', $cols, true) ? 'outcome' : null;
        if ($timeCol === null || $outcomeCol === null) {
            return ['claimed' => 0, 'completed' => 0, 'deferred' => 0, 'completion_ratio' => 0.0, 'defer_rate' => 0.0];
        }
        $stmt = $this->pdo()->prepare('SELECT ' . $outcomeCol . ' AS outcome, COUNT(*) AS c FROM queue_v4_clean_attempts WHERE ' . $timeCol . ' >= (UTC_TIMESTAMP() - INTERVAL ' . $minutes . ' MINUTE) GROUP BY ' . $outcomeCol);
        $stmt->execute();
        $claimed = $completed = $deferred = 0;
        $outcomes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $count = (int) $row['c'];
            $outcome = (string) $row['outcome'];
            $outcomes[$outcome] = $count;
            $claimed += $count;
            if ($outcome === 'completed') {
                $completed += $count;
            } else {
                $deferred += $count;
            }
        }
        return [
            'claimed' => $claimed,
            'completed' => $completed,
            'deferred' => $deferred,
            'completion_ratio' => $claimed > 0 ? round(($completed / $claimed) * 100, 2) : 0.0,
            'defer_rate' => $claimed > 0 ? round(($deferred / $claimed) * 100, 2) : 0.0,
            'outcomes' => $outcomes,
        ];
    }

    /** @return list<array{http_status:string,count:int}> */
    private function apiStatusCounts(string $timeColumn, string $statusColumn, int $hours): array
    {
        $stmt = $this->pdo()->query('SELECT COALESCE(CAST(' . $statusColumn . ' AS CHAR), "NULL") AS s, COUNT(*) AS c FROM api_request_logs WHERE ' . $timeColumn . ' >= (UTC_TIMESTAMP() - INTERVAL ' . $hours . ' HOUR) GROUP BY s ORDER BY c DESC LIMIT 50');
        return array_map(static fn (array $r): array => ['http_status' => (string) $r['s'], 'count' => (int) $r['c']], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,array<string,mixed>> */
    private function apiOutcomeFamilyWindow(string $timeColumn, string $statusColumn, int $minutes): array
    {
        $cols = $this->columns('api_request_logs');
        $familyCols = array_values(array_intersect(['endpoint_family','endpoint_key','operation','resource','path','url','route'], $cols));
        $durationCol = $this->firstExistingColumn($cols, ['duration_ms', 'elapsed_ms', 'latency_ms']);
        $select = [$statusColumn . ' AS http_status'];
        foreach ($familyCols as $col) {
            $select[] = $col;
        }
        if ($durationCol !== null) {
            $select[] = $durationCol . ' AS duration_ms';
        }
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM api_request_logs WHERE ' . $timeColumn . ' >= (UTC_TIMESTAMP() - INTERVAL ' . max(1, $minutes) . ' MINUTE) ORDER BY ' . $timeColumn . ' DESC LIMIT 2000');
        $stmt->execute();
        $out = [];
        foreach (['pack_exact', 'order_exact', 'orders_search', 'billing', 'oauth', 'other'] as $family) {
            $out[$family] = [
                'physical_http_calls' => 0,
                'http_2xx' => 0,
                'http_429' => 0,
                'http_5xx' => 0,
                'remote_uncertain' => 0,
                'latency_ms_p50' => null,
                'latency_ms_p95' => null,
            ];
        }
        $durations = array_fill_keys(array_keys($out), []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $familyText = strtolower(implode(' ', array_map('strval', array_intersect_key($row, array_flip($familyCols)))));
            $family = 'other';
            if (str_contains($familyText, 'pack')) {
                $family = 'pack_exact';
            } elseif (str_contains($familyText, 'order') && str_contains($familyText, 'search')) {
                $family = 'orders_search';
            } elseif (str_contains($familyText, 'order')) {
                $family = 'order_exact';
            } elseif (str_contains($familyText, 'billing') || str_contains($familyText, 'invoice')) {
                $family = 'billing';
            } elseif (str_contains($familyText, 'oauth') || str_contains($familyText, 'token')) {
                $family = 'oauth';
            }
            $status = (int) ($row['http_status'] ?? 0);
            $out[$family]['physical_http_calls']++;
            if ($status >= 200 && $status < 300) {
                $out[$family]['http_2xx']++;
            } elseif ($status === 429) {
                $out[$family]['http_429']++;
            } elseif ($status >= 500 && $status < 600) {
                $out[$family]['http_5xx']++;
            } elseif ($status === 0) {
                $out[$family]['remote_uncertain']++;
            }
            if ($durationCol !== null && is_numeric($row['duration_ms'] ?? null)) {
                $durations[$family][] = (int) $row['duration_ms'];
            }
        }
        foreach ($durations as $family => $values) {
            sort($values);
            if ($values === []) {
                continue;
            }
            $out[$family]['latency_ms_p50'] = $values[(int) floor((count($values) - 1) * 0.50)];
            $out[$family]['latency_ms_p95'] = $values[(int) floor((count($values) - 1) * 0.95)];
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function transportOutcomeFamilyWindow(int $minutes): array
    {
        $cols = $this->columns('queue_v4_clean_transport_events');
        $timeCol = $this->firstExistingColumn($cols, ['created_at', 'response_known_at', 'finished_at', 'started_at']);
        $statusCol = $this->firstExistingColumn($cols, ['http_status', 'status_code']);
        $endpointCol = in_array('endpoint_key', $cols, true) ? 'endpoint_key' : null;
        if ($timeCol === null || $statusCol === null || $endpointCol === null) {
            return [];
        }
        $durationCol = $this->firstExistingColumn($cols, ['duration_ms', 'elapsed_ms', 'latency_ms']);
        $select = [$endpointCol . ' AS endpoint_key', $statusCol . ' AS http_status'];
        foreach (['reached_remote', 'event_type'] as $col) {
            if (in_array($col, $cols, true)) {
                $select[] = $col;
            }
        }
        if ($durationCol !== null) {
            $select[] = $durationCol . ' AS duration_ms';
        }
        $stmt = $this->pdo()->prepare('SELECT ' . implode(',', $select) . ' FROM queue_v4_clean_transport_events WHERE ' . $this->qi($timeCol) . ' >= (UTC_TIMESTAMP() - INTERVAL ' . max(1, $minutes) . ' MINUTE) ORDER BY ' . $this->qi($timeCol) . ' DESC LIMIT 5000');
        $stmt->execute();
        $out = [];
        foreach (['pack_exact', 'order_exact', 'orders_search', 'billing', 'oauth', 'other'] as $family) {
            $out[$family] = [
                'physical_http_calls' => 0,
                'http_2xx' => 0,
                'http_429' => 0,
                'http_5xx' => 0,
                'remote_uncertain' => 0,
                'latency_ms_p50' => null,
                'latency_ms_p95' => null,
            ];
        }
        $durations = array_fill_keys(array_keys($out), []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $family = $this->endpointFamily((string) ($row['endpoint_key'] ?? ''));
            $statusRaw = $row['http_status'] ?? null;
            $status = is_numeric($statusRaw) ? (int) $statusRaw : 0;
            $reachedRemote = $status > 0 || in_array(strtolower((string) ($row['reached_remote'] ?? '')), ['1', 'true', 'yes'], true);
            if (!$reachedRemote) {
                $out[$family]['remote_uncertain']++;
                continue;
            }
            $out[$family]['physical_http_calls']++;
            if ($status >= 200 && $status < 300) {
                $out[$family]['http_2xx']++;
            } elseif ($status === 429) {
                $out[$family]['http_429']++;
            } elseif ($status >= 500 && $status < 600) {
                $out[$family]['http_5xx']++;
            } elseif ($status === 0) {
                $out[$family]['remote_uncertain']++;
            }
            if ($durationCol !== null && is_numeric($row['duration_ms'] ?? null)) {
                $durations[$family][] = (int) $row['duration_ms'];
            }
        }
        foreach ($durations as $family => $values) {
            sort($values);
            if ($values === []) {
                continue;
            }
            $out[$family]['latency_ms_p50'] = $values[(int) floor((count($values) - 1) * 0.50)];
            $out[$family]['latency_ms_p95'] = $values[(int) floor((count($values) - 1) * 0.95)];
        }
        return $out;
    }

    /** @return list<array{http_status:string,count:int}> */
    private function transportStatusCounts(int $hours): array
    {
        $cols = $this->columns('queue_v4_clean_transport_events');
        $timeCol = $this->firstExistingColumn($cols, ['created_at', 'response_known_at', 'finished_at', 'started_at']);
        $statusCol = $this->firstExistingColumn($cols, ['http_status', 'status_code']);
        if ($timeCol === null || $statusCol === null) {
            return [];
        }
        $stmt = $this->pdo()->query('SELECT COALESCE(CAST(' . $this->qi($statusCol) . ' AS CHAR), "NULL") AS s, COUNT(*) AS c FROM queue_v4_clean_transport_events WHERE ' . $this->qi($timeCol) . ' >= (UTC_TIMESTAMP() - INTERVAL ' . max(1, $hours) . ' HOUR) GROUP BY s ORDER BY c DESC LIMIT 50');
        return array_map(static fn (array $r): array => ['http_status' => (string) $r['s'], 'count' => (int) $r['c']], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function endpointFamily(string $endpointKey): string
    {
        $text = strtolower($endpointKey);
        if (str_contains($text, 'billing') || str_contains($text, 'invoice')) {
            return 'billing';
        }
        if ($text === 'pack_exact' || str_contains($text, 'pack')) {
            return 'pack_exact';
        }
        if (str_contains($text, 'order') && str_contains($text, 'search')) {
            return 'orders_search';
        }
        if (str_contains($text, 'order')) {
            return 'order_exact';
        }
        if (str_contains($text, 'oauth') || str_contains($text, 'token')) {
            return 'oauth';
        }
        return 'other';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function sanitizeJobRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['id','company_id','meli_account_id','source_id','job_id','run_id','attempt_id'], true)) {
                $out[$key . '_hash'] = $this->hashString((string) $value);
                continue;
            }
            if ($key === 'payload_json') {
                $out['payload_shape'] = $this->payloadShape((string) $value);
                continue;
            }
            $out[$key] = is_scalar($value) || $value === null ? $value : '[complex]';
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function classifyWaiting(array $row): string
    {
        $text = strtolower(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        if (str_contains($text, 'expected_count_unknown') || str_contains($text, 'pack_expected_count_unknown')) {
            return 'PACK_EXPECTED_COUNT_UNKNOWN';
        }
        if (str_contains($text, 'rhythm') || str_contains($text, 'permit_busy') || str_contains($text, 'permit')) {
            return 'RHYTHM_PERMIT_BUSY';
        }
        if (str_contains($text, 'local_interval') || str_contains($text, 'local_policy') || str_contains($text, 'pretransport')) {
            return 'LOCAL_INTERVAL';
        }
        if (str_contains($text, 'retry-after') || str_contains($text, 'retry_after')) {
            return 'REMOTE_RETRY_AFTER';
        }
        if (str_contains($text, 'remote_result_uncertain')) {
            return 'REMOTE_RESULT_UNCERTAIN';
        }
        if (str_contains($text, 'domain_source_waiting:financial') || str_contains($text, 'financial_reconciliation')) {
            return 'FINANCE_FUTURE_NEXT_RUN';
        }
        if (str_contains($text, 'http_status":429') || str_contains($text, ' 429') || str_contains($text, ':429')) {
            return 'REMOTE_429';
        }
        if (str_contains($text, '5xx') || str_contains($text, 'http_status":5')) {
            return 'REMOTE_5XX';
        }
        if (str_contains($text, 'oauth') || str_contains($text, 'auth')) {
            return 'AUTH_ACTION_REQUIRED';
        }
        if (str_contains($text, 'source_not_due') || str_contains($text, 'not_due')) {
            return 'SOURCE_NOT_DUE';
        }
        if (str_contains($text, 'source_error') || str_contains($text, 'domain_source_error')) {
            return 'SOURCE_ERROR';
        }
        if (str_contains($text, 'deadline') || str_contains($text, 'cron_deadline')) {
            return 'DEADLINE';
        }
        if (str_contains($text, 'pack')) {
            return 'PACK_EXPECTED_COUNT_UNKNOWN';
        }
        return 'OTHER';
    }

    /** @return array<string,string> */
    private function reasonMeta(string $reasonCode): array
    {
        $catalog = $this->reasonCatalog();
        $meta = $catalog[$reasonCode] ?? $catalog['OTHER'];
        return [
            'reason' => $reasonCode,
            'reason_code' => $reasonCode,
            'reason_human' => $meta['reason_human'],
            'exit_condition_human' => $meta['exit_condition_human'],
        ];
    }

    /** @return array<string,array{reason_human:string,exit_condition_human:string}> */
    private function reasonCatalog(): array
    {
        return [
            'PACK_EXPECTED_COUNT_UNKNOWN' => [
                'reason_human' => 'El pack todavía no tiene autoridad local completa de cantidad/órdenes hijas esperadas.',
                'exit_condition_human' => 'La autoridad de pack descubre y persiste el conteo esperado o completa los vínculos de órdenes hijas.',
            ],
            'FINANCE_FUTURE_NEXT_RUN' => [
                'reason_human' => 'Finanzas ya conoce un próximo momento legítimo de reintento.',
                'exit_condition_human' => 'El reloj llega al next_run_at/available_at de la fuente financiera.',
            ],
            'RHYTHM_PERMIT_BUSY' => [
                'reason_human' => 'El permiso local de ritmo/capacidad aún no libera esta familia de trabajo.',
                'exit_condition_human' => 'El permiso local vence o se libera sin ejecutar Cron manual.',
            ],
            'LOCAL_INTERVAL' => [
                'reason_human' => 'Regla local de intervalo evita repetir la misma acción demasiado pronto.',
                'exit_condition_human' => 'Vence el intervalo local configurado.',
            ],
            'REMOTE_RETRY_AFTER' => [
                'reason_human' => 'Mercado Libre indicó un Retry-After real.',
                'exit_condition_human' => 'Vence el Retry-After remoto.',
            ],
            'REMOTE_RESULT_UNCERTAIN' => [
                'reason_human' => 'El transporte remoto no pudo confirmar una respuesta decidible.',
                'exit_condition_human' => 'Nuevo ciclo natural obtiene respuesta conocida o deriva a revisión con evidencia.',
            ],
            'REMOTE_429' => [
                'reason_human' => 'Mercado Libre respondió HTTP 429 real.',
                'exit_condition_human' => 'Vence el enfriamiento derivado del 429/Retry-After.',
            ],
            'REMOTE_5XX' => [
                'reason_human' => 'Mercado Libre respondió HTTP 5xx real.',
                'exit_condition_human' => 'Vence el reintento seguro para error remoto temporal.',
            ],
            'AUTH_ACTION_REQUIRED' => [
                'reason_human' => 'La cuenta requiere acción de autorización/OAuth o intervención humana.',
                'exit_condition_human' => 'El operador corrige autorización o se marca resolución humana.',
            ],
            'SOURCE_NOT_DUE' => [
                'reason_human' => 'La fuente canónica aún no está vencida para ejecución.',
                'exit_condition_human' => 'La fuente canónica alcanza su próximo vencimiento.',
            ],
            'SOURCE_ERROR' => [
                'reason_human' => 'La fuente de dominio quedó en error verificable.',
                'exit_condition_human' => 'Se corrige la fuente o se clasifica a revisión con ruta accionable.',
            ],
            'DEADLINE' => [
                'reason_human' => 'El ciclo natural alcanzó su límite de tiempo antes de terminar este trabajo.',
                'exit_condition_human' => 'Un ciclo natural posterior reclama el trabajo con presupuesto disponible.',
            ],
            'OTHER' => [
                'reason_human' => 'Causa no clasificada por las autoridades conocidas del bundle.',
                'exit_condition_human' => 'Requiere inspección del detalle sanitizado o ampliación del clasificador.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function payloadShape(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['valid_json' => false];
        }
        $keys = array_values(array_filter(array_keys($data), static fn ($key): bool => is_string($key) && preg_match('/token|secret|authorization|password/i', $key) !== 1));
        sort($keys);
        return [
            'valid_json' => true,
            'keys' => array_slice($keys, 0, 40),
            'bytes' => strlen($json),
        ];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function sanitizeArray(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? $key : (string) $key;
            if (preg_match('/token|secret|authorization|password|credential|access/i', $keyString) === 1) {
                $out[$keyString] = '[redacted]';
                continue;
            }
            if (in_array($keyString, ['id','job_id','run_id','attempt_id','company_id','meli_account_id','source_id','external_order_id','external_pack_id','resource_id'], true)) {
                $out[$keyString . '_hash'] = $this->hashString((string) $item);
                continue;
            }
            if (is_array($item)) {
                $out[$keyString] = $this->sanitizeArray($item);
                continue;
            }
            $out[$keyString] = is_scalar($item) || $item === null ? $item : '[complex]';
        }
        return $out;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function firstScalarFromNested(array $value, array $keys): mixed
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $keys, true) && (is_scalar($item) || $item === null) && $item !== null && (string) $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                $nested = $this->firstScalarFromNested($item, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }

    private function baseReceiptStatus(): array
    {
        $dir = AppPaths::storage(self::BASE_RECEIPT_DIR);
        $today = $dir . '/' . gmdate('Y-m-d') . '.jsonl';
        return [
            'dir_hash' => $this->hashString($dir),
            'today_exists' => is_file($today),
            'today_bytes' => is_file($today) ? (filesize($today) ?: 0) : 0,
            'latest_line_at' => is_file($today) ? gmdate('Y-m-d H:i:s', (int) filemtime($today)) : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function latestBundle(): ?array
    {
        $path = $this->latestBundlePath();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed> */
    private function debugConfig(): array
    {
        $path = $this->debugConfigPath();
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($config)) {
            return ['enabled' => false, 'minutes' => 0, 'mode' => 'off', 'expires_at' => null];
        }
        $expires = strtotime((string) ($config['expires_at'] ?? ''));
        if ($expires !== false && $expires < time()) {
            $config['enabled'] = false;
            $config['minutes'] = 0;
            $config['mode'] = 'off';
        }
        return $config;
    }

    private function createDownloadToken(string $zipPath): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::SIGNED_URL_TTL_SECONDS);
        $this->writeJsonFile($this->tokenDir() . '/' . hash('sha256', $token) . '.json', [
            'zip_path' => $zipPath,
            'created_at' => $this->now(),
            'expires_at' => $expiresAt,
            'zip_sha256' => hash_file('sha256', $zipPath),
        ]);
        return [
            'token' => $token,
            'url' => rtrim((string) Env::get('APP_URL', ''), '/') . '/settings/cron/queue-diagnostic/download?token=' . rawurlencode($token),
            'expires_at' => $expiresAt,
        ];
    }

    private function zipDirectory(string $dir, string $zipPath, array $files): void
    {
        $this->ensureDir(dirname($zipPath));
        $tmp = $zipPath . '.tmp';
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('diagnostic_zip_open_failed');
        }
        foreach ($files as $file) {
            $zip->addFile($dir . '/' . $file, $file);
        }
        $zip->close();
        rename($tmp, $zipPath);
    }

    /** @param array<string,mixed> $data */
    private function writeJsonFile(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
        rename($tmp, $path);
    }

    /** @param list<string> $lines */
    private function writeJsonlFile(string $path, array $lines): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, ($lines === [] ? '' : implode("\n", $lines) . "\n"), LOCK_EX);
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeCsvFile(string $path, array $rows): void
    {
        $this->ensureDir(dirname($path));
        $fp = fopen($path, 'wb');
        if (!$fp) {
            throw new \RuntimeException('csv_open_failed');
        }
        $columns = ['id_hash','company_id_hash','meli_account_id_hash','job_type','work_type','source_table','source_id_hash','state','available_at','attempt_count','last_error_class','reason','reason_code','reason_human','exit_condition_human','created_at','updated_at'];
        fputcsv($fp, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? '';
                $line[] = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
    }

    private function cleanup(): void
    {
        foreach ([[$this->zipDir(), 86400], [$this->bundleRoot(), 172800], [$this->tokenDir(), self::SIGNED_URL_TTL_SECONDS]] as [$dir, $ttl]) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                if ((int) @filemtime($path) >= time() - (int) $ttl) {
                    continue;
                }
                if (is_dir($path)) {
                    $this->removeDir($path);
                } else {
                    @unlink($path);
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function pdo(): PDO
    {
        Database::useProfile('diagnostic');
        return Database::connectionFresh();
    }

    private function hasTable(string $table): bool
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $stmt = $this->pdo()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $stmt->execute([$table]);
        $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        return $cache[$table];
    }

    /** @param list<string> $available @param list<string> $candidates */
    private function firstExistingColumn(array $available, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function qi(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function hashString(string $value): string
    {
        return hash('sha256', 'erp-meli-qv4-diagnostic-v1|' . $value);
    }

    private function bundleRoot(): string
    {
        return AppPaths::storage(self::ROOT . '/bundles');
    }

    private function bundleDir(string $stamp): string
    {
        return $this->bundleRoot() . '/' . $stamp;
    }

    private function zipDir(): string
    {
        return AppPaths::storage(self::ROOT . '/zips');
    }

    private function tokenDir(): string
    {
        return AppPaths::storage(self::ROOT . '/tokens');
    }

    private function debugConfigPath(): string
    {
        return AppPaths::storage(self::ROOT . '/debug-config.json');
    }

    private function latestBundlePath(): string
    {
        return AppPaths::storage(self::ROOT . '/latest-bundle.json');
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
