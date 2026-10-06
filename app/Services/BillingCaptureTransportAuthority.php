<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Durable, Billing-specific authority at the physical HTTP boundary.
 * Queue journals remain independent telemetry and never authorize Billing.
 */
final class BillingCaptureTransportAuthority
{
    private const V2_CLAIM_MARKER = 'billing_v2_claimed';

    /** @param array<string,mixed> $job @return array<string,scalar|null> */
    public function reserve(array $job, string $externalOrderId): array
    {
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $jobId = (int) ($job['id'] ?? 0);
        $generation = (int) ($job['lease_generation'] ?? 0);
        $owner = trim((string) ($job['lock_owner'] ?? ''));
        $saleKey = (string) ($job['sale_key'] ?? '');
        $inputVersion = strtolower((string) ($job['input_version'] ?? ''));
        $externalOrderId = trim($externalOrderId);
        if ($companyId < 1 || $accountId < 1 || $jobId < 1 || $generation < 1
            || $owner === '' || preg_match('/^[PO]:[0-9]+$/', $saleKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $inputVersion) !== 1
            || preg_match('/^[0-9]{1,255}$/', $externalOrderId) !== 1) {
            throw new RuntimeException('billing_v2_reservation_identity_invalid');
        }

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $source = $this->lockFinancialJob($pdo, $jobId, $companyId, $accountId);
            $this->assertLiveFinancialLease($source, $owner, $generation, $inputVersion);
            $this->assertOrderInSale($pdo, $companyId, $accountId, $saleKey, $externalOrderId);
            if (!hash_equals(
                $inputVersion,
                (new SaleFinancialStateService())->currentInputVersionForSale($companyId, $accountId, $saleKey, true)
            )) {
                throw new RuntimeException('billing_v2_reservation_input_version_stale');
            }

            $requestId = bin2hex(random_bytes(20));
            $resourceKey = hash('sha256', 'billing_order:v1:' . $externalOrderId);
            $ordersJson = json_encode([$externalOrderId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $insert = $pdo->prepare(
                'INSERT INTO meli_billing_capture_runs
                    (company_id,meli_account_id,sale_key,external_sale_id,input_version,source_mode,
                     requested_order_ids_json,financial_job_id,financial_claim_generation,
                     external_order_id,billing_resource_key,request_id,dispatch_state)
                 VALUES(?,?,?,?,?,"exact_repair",?,?,?,?,?,?,"reserved")'
            );
            try {
                $insert->execute([
                    $companyId,
                    $accountId,
                    $saleKey,
                    (string) ($job['external_sale_id'] ?? substr($saleKey, 2)),
                    $inputVersion,
                    $ordersJson,
                    $jobId,
                    $generation,
                    $externalOrderId,
                    $resourceKey,
                    $requestId,
                ]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    throw new RuntimeException('billing_v2_unresolved_capture_conflict', 0, $error);
                }
                throw $error;
            }
            $captureId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return [
                'billing_v2' => true,
                'capture_run_id' => $captureId,
                'company_id' => $companyId,
                'account_id' => $accountId,
                'meli_account_id' => $accountId,
                'sale_key' => $saleKey,
                'input_version' => $inputVersion,
                'financial_job_id' => $jobId,
                'financial_claim_generation' => $generation,
                'financial_lock_owner' => $owner,
                'external_order_id' => $externalOrderId,
                'billing_resource_key' => $resourceKey,
                'request_id' => $requestId,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function abortReserved(array $metadata): bool
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $capture = $this->lockCaptureForMetadata($pdo, $metadata);
            if ((string) ($capture['dispatch_state'] ?? '') !== 'reserved') {
                $pdo->commit();
                return false;
            }
            $update = $pdo->prepare(
                'UPDATE meli_billing_capture_runs
                 SET dispatch_state="aborted_pretransport",aborted_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND company_id=? AND meli_account_id=? AND request_id=?
                   AND dispatch_state="reserved"'
            );
            $update->execute([
                (int) $metadata['capture_run_id'],
                (int) $metadata['company_id'],
                (int) $metadata['meli_account_id'],
                (string) $metadata['request_id'],
            ]);
            $changed = $update->rowCount() === 1;
            $pdo->commit();
            return $changed;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** The final Financial CAS immediately before the physical cURL call. @param array<string,mixed> $metadata */
    public function commitDispatch(array $metadata): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $capture = $this->lockCaptureForMetadata($pdo, $metadata);
            if ((string) ($capture['dispatch_state'] ?? '') !== 'reserved') {
                throw new RuntimeException('billing_v2_capture_not_reserved');
            }
            $source = $this->lockFinancialJob(
                $pdo,
                (int) $metadata['financial_job_id'],
                (int) $metadata['company_id'],
                (int) $metadata['meli_account_id']
            );
            $this->assertLiveFinancialLease(
                $source,
                (string) $metadata['financial_lock_owner'],
                (int) $metadata['financial_claim_generation'],
                (string) $metadata['input_version']
            );
            if ((string) ($source['sale_key'] ?? '') !== (string) $metadata['sale_key']) {
                throw new RuntimeException('billing_v2_source_sale_identity_mismatch');
            }
            if (!hash_equals(
                (string) $metadata['input_version'],
                (new SaleFinancialStateService())->currentInputVersionForSale(
                    (int) $metadata['company_id'],
                    (int) $metadata['meli_account_id'],
                    (string) $metadata['sale_key'],
                    true
                )
            )) {
                throw new RuntimeException('billing_v2_dispatch_input_version_stale');
            }

            $unresolved = $pdo->prepare(
                'SELECT id FROM meli_billing_capture_runs
                 WHERE company_id=? AND meli_account_id=? AND billing_resource_key=?
                   AND unresolved_guard=1 FOR UPDATE'
            );
            $unresolved->execute([
                (int) $metadata['company_id'],
                (int) $metadata['meli_account_id'],
                (string) $metadata['billing_resource_key'],
            ]);
            $ids = array_map('intval', $unresolved->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== [(int) $metadata['capture_run_id']]) {
                throw new RuntimeException('billing_v2_unresolved_guard_conflict');
            }

            $update = $pdo->prepare(
                'UPDATE meli_billing_capture_runs
                 SET dispatch_state="dispatch_committed",dispatch_committed_at=UTC_TIMESTAMP(6)
                 WHERE id=? AND company_id=? AND meli_account_id=? AND request_id=?
                   AND dispatch_state="reserved"'
            );
            $update->execute([
                (int) $metadata['capture_run_id'],
                (int) $metadata['company_id'],
                (int) $metadata['meli_account_id'],
                (string) $metadata['request_id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('billing_v2_dispatch_compare_and_swap_lost');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Physical-result permission is independent of the current Financial lease. @param array<string,mixed> $metadata */
    public function persistKnownResponse(array $metadata, int $httpStatus, string $rawBody, array $responseHeaders = []): void
    {
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw new RuntimeException('billing_v2_known_response_status_invalid');
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $capture = $this->lockCaptureForMetadata($pdo, $metadata);
            $state = (string) ($capture['dispatch_state'] ?? '');
            $bodyHash = hash('sha256', $rawBody);
            $missingFields = $this->missingFieldsFromHeaders($responseHeaders);
            $missingFieldsJson = json_encode($missingFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($state === 'result_durable') {
                if ((int) ($capture['http_status'] ?? 0) !== $httpStatus
                    || !hash_equals((string) ($capture['response_hash'] ?? ''), $bodyHash)
                    || !hash_equals((string) ($capture['response_body_raw'] ?? ''), $rawBody)
                    || !hash_equals((string) ($capture['missing_fields_json'] ?? '[]'), $missingFieldsJson)) {
                    throw new RuntimeException('billing_v2_durable_response_conflict');
                }
                $pdo->commit();
                return;
            }
            if ($state !== 'dispatch_committed') {
                throw new RuntimeException('billing_v2_response_without_committed_dispatch');
            }
            $update = $pdo->prepare(
                'UPDATE meli_billing_capture_runs
                 SET dispatch_state="result_durable",http_status=?,response_body_raw=?,response_hash=?,missing_fields_json=?,
                     result_durable_at=UTC_TIMESTAMP(6),captured_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=? AND request_id=?
                   AND financial_job_id=? AND financial_claim_generation=? AND input_version=?
                   AND dispatch_state="dispatch_committed"'
            );
            $update->execute([
                $httpStatus,
                $rawBody,
                $bodyHash,
                $missingFieldsJson,
                (int) $metadata['capture_run_id'],
                (int) $metadata['company_id'],
                (int) $metadata['meli_account_id'],
                (string) $metadata['request_id'],
                (int) $metadata['financial_job_id'],
                (int) $metadata['financial_claim_generation'],
                (string) $metadata['input_version'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('billing_v2_result_compare_and_swap_lost');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function loadCapture(int $captureId, int $companyId, int $accountId): ?array
    {
        if ($captureId < 1 || $companyId < 1 || $accountId < 1) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM meli_billing_capture_runs
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$captureId, $companyId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $capture */
    public function classifyCapture(?array $capture): string
    {
        if ($capture === null) {
            return 'none';
        }
        if (($capture['financial_job_id'] ?? null) === null || ($capture['dispatch_state'] ?? null) === null) {
            return 'legacy_ambiguous';
        }
        return match ((string) $capture['dispatch_state']) {
            'reserved' => 'not_dispatched_reserved',
            'aborted_pretransport' => 'not_dispatched_aborted',
            'dispatch_committed' => 'physical_result_unknown',
            'result_durable' => 'durable_result',
            default => 'conflict',
        };
    }

    /** Exact recovery entry; legacy/no-marker running rows are never mutated. */
    public function recoverExpiredRunning(int $jobId, int $companyId, int $accountId): string
    {
        if ($jobId < 1 || $companyId < 1 || $accountId < 1) {
            return 'not_found';
        }
        $pdo = Database::connectionFresh();
        // RC lets the no-capture branch detect a reservation that committed
        // while it waited for the Financial row, then retry in lock order.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $hint = $this->readFinancialJob($pdo, $jobId, $companyId, $accountId, false);
            if ($hint === null) {
                $pdo->commit();
                return 'not_found';
            }
            $generation = (int) $hint['lease_generation'];
            $captureStmt = $pdo->prepare(
                'SELECT * FROM meli_billing_capture_runs
                 WHERE company_id=? AND meli_account_id=? AND financial_job_id=?
                   AND financial_claim_generation=?
                 ORDER BY id FOR UPDATE'
            );
            $captureStmt->execute([$companyId, $accountId, $jobId, $generation]);
            $captures = $captureStmt->fetchAll(PDO::FETCH_ASSOC);
            $job = $this->readFinancialJob($pdo, $jobId, $companyId, $accountId, true);
            if ($job === null
                || (string) $job['status'] !== 'running'
                || $job['lease_expires_at'] === null
                || strtotime((string) $job['lease_expires_at'] . ' UTC') >= time()) {
                $pdo->commit();
                return 'not_expired_or_not_v2';
            }
            if ((string) ($job['last_remote_state'] ?? '') !== self::V2_CLAIM_MARKER) {
                $pdo->commit();
                return 'ambiguous_no_action';
            }
            if ((int) $job['lease_generation'] !== $generation) {
                $pdo->commit();
                return 'generation_changed';
            }
            if ($captures === []) {
                $latestCapture = $pdo->prepare(
                    'SELECT id FROM meli_billing_capture_runs
                     WHERE company_id=? AND meli_account_id=? AND financial_job_id=?
                       AND financial_claim_generation=? LIMIT 1'
                );
                $latestCapture->execute([$companyId, $accountId, $jobId, $generation]);
                if ($latestCapture->fetchColumn() !== false) {
                    $pdo->rollBack();
                    return $this->recoverExpiredRunning($jobId, $companyId, $accountId);
                }
                return $this->recoverSourceTo($pdo, $job, 'retry', true, 'billing_v2_not_dispatched_recovered');
            }
            if (count($captures) !== 1) {
                return $this->recoverSourceTo($pdo, $job, 'review', false, 'billing_v2_capture_generation_conflict');
            }

            $capture = $captures[0];
            $state = (string) ($capture['dispatch_state'] ?? '');
            if ($state !== 'result_durable' && $this->queueKnowsResponse($pdo, $capture)) {
                return $this->recoverSourceTo($pdo, $job, 'review', false, 'billing_v2_queue_known_billing_not_durable');
            }
            if ($state === 'reserved' || $state === 'aborted_pretransport') {
                if ($state === 'reserved') {
                    $abort = $pdo->prepare(
                        'UPDATE meli_billing_capture_runs
                         SET dispatch_state="aborted_pretransport",aborted_at=UTC_TIMESTAMP(6)
                         WHERE id=? AND company_id=? AND meli_account_id=? AND request_id=?
                           AND dispatch_state="reserved"'
                    );
                    $abort->execute([(int) $capture['id'], $companyId, $accountId, (string) $capture['request_id']]);
                    if ($abort->rowCount() !== 1) {
                        throw new RuntimeException('billing_v2_recovery_reserved_abort_cas_lost');
                    }
                }
                return $this->recoverSourceTo($pdo, $job, 'retry', true, 'billing_v2_not_dispatched_recovered');
            }
            if ($state === 'dispatch_committed') {
                return $this->recoverSourceTo($pdo, $job, 'review', false, 'remote_result_uncertain');
            }
            if ($state === 'result_durable'
                && $capture['http_status'] !== null
                && $capture['response_body_raw'] !== null
                && is_string($capture['response_hash'])
                && strlen((string) $capture['response_hash']) === 64
                && $capture['result_durable_at'] !== null
                && hash_equals((string) $capture['response_hash'], hash('sha256', (string) $capture['response_body_raw']))) {
                return $this->recoverSourceTo($pdo, $job, 'retry', false, 'billing_v2_offline_reconcile');
            }
            return $this->recoverSourceTo($pdo, $job, 'review', false, 'billing_v2_capture_evidence_conflict');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $authorizedAccountIds @return array<string,int> */
    public function recoverExpiredRunningForScope(array $authorizedAccountIds, ?int $accountId = null, int $limit = 10): array
    {
        $accounts = array_values(array_unique(array_filter(array_map('intval', $authorizedAccountIds), static fn (int $id): bool => $id > 0)));
        if ($accountId !== null) {
            $accounts = array_values(array_filter($accounts, static fn (int $id): bool => $id === $accountId));
        }
        if ($accounts === []) {
            return ['retry_not_dispatched' => 0, 'review' => 0, 'offline_reconcile' => 0, 'untouched' => 0];
        }
        $sql = 'SELECT j.id,j.company_id,j.meli_account_id
                FROM sale_financial_reconciliation_jobs j
                JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                WHERE j.meli_account_id IN (' . implode(',', array_fill(0, count($accounts), '?')) . ')
                  AND j.status="running" AND j.last_remote_state=?
                  AND j.lease_expires_at IS NOT NULL AND j.lease_expires_at<UTC_TIMESTAMP()
                ORDER BY j.lease_expires_at,j.id LIMIT ' . max(1, min(50, $limit));
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute(array_merge($accounts, [self::V2_CLAIM_MARKER]));
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = ['retry_not_dispatched' => 0, 'review' => 0, 'offline_reconcile' => 0, 'untouched' => 0];
        foreach ($candidates as $candidate) {
            $action = $this->recoverExpiredRunning(
                (int) $candidate['id'],
                (int) $candidate['company_id'],
                (int) $candidate['meli_account_id']
            );
            if (isset($result[$action])) {
                $result[$action]++;
            } else {
                $result['untouched']++;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function findUnconsumedDurableResult(array $metadata): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT c.* FROM meli_billing_capture_runs c
             WHERE c.company_id=? AND c.meli_account_id=? AND c.sale_key=? AND c.input_version=?
               AND c.external_order_id=? AND c.billing_resource_key=? AND c.dispatch_state="result_durable"
               AND c.http_status BETWEEN 200 AND 299 AND c.response_body_raw IS NOT NULL
               AND c.response_hash IS NOT NULL AND c.result_durable_at IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM sale_financial_evidence e
                   WHERE e.company_id=c.company_id AND e.meli_account_id=c.meli_account_id
                     AND e.sale_key=c.sale_key AND e.input_version=c.input_version
                     AND e.evidence_type="billing_capture" AND e.source_id=c.id
                     AND JSON_UNQUOTE(JSON_EXTRACT(e.evidence_json,"$.format"))="billing_order_v2"
               )
             ORDER BY c.id DESC LIMIT 1'
        );
        $stmt->execute([
            (int) $metadata['company_id'],
            (int) $metadata['meli_account_id'],
            (string) $metadata['sale_key'],
            (string) $metadata['input_version'],
            (string) $metadata['external_order_id'],
            (string) $metadata['billing_resource_key'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !hash_equals((string) $row['response_hash'], hash('sha256', (string) $row['response_body_raw']))) {
            return null;
        }
        return $row;
    }

    /** @param PDO $pdo @return array<string,mixed> */
    private function lockFinancialJob(PDO $pdo, int $jobId, int $companyId, int $accountId): array
    {
        $job = $this->readFinancialJob($pdo, $jobId, $companyId, $accountId, true);
        if ($job === null) {
            throw new RuntimeException('billing_v2_financial_job_missing');
        }
        return $job;
    }

    /** @return array<string,mixed>|null */
    private function readFinancialJob(PDO $pdo, int $jobId, int $companyId, int $accountId, bool $lock): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id,company_id,meli_account_id,sale_key,external_sale_id,input_version,status,
                    attempts,lock_owner,lease_generation,lease_expires_at,last_remote_state
             FROM sale_financial_reconciliation_jobs
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$jobId, $companyId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $source */
    private function assertLiveFinancialLease(array $source, string $owner, int $generation, string $inputVersion): void
    {
        if ((string) ($source['status'] ?? '') !== 'running'
            || !hash_equals((string) ($source['lock_owner'] ?? ''), $owner)
            || (int) ($source['lease_generation'] ?? 0) !== $generation
            || (string) ($source['input_version'] ?? '') !== $inputVersion
            || $source['lease_expires_at'] === null
            || strtotime((string) $source['lease_expires_at'] . ' UTC') < time()) {
            throw new RuntimeException('billing_v2_financial_lease_stale');
        }
    }

    private function assertOrderInSale(PDO $pdo, int $companyId, int $accountId, string $saleKey, string $externalOrderId): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.external_order_id=?
               AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=?
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$companyId, $accountId, $externalOrderId, $saleKey]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('billing_v2_order_outside_financial_sale');
        }
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function lockCaptureForMetadata(PDO $pdo, array $metadata): array
    {
        $required = [
            'billing_v2', 'capture_run_id', 'company_id', 'meli_account_id', 'sale_key', 'input_version',
            'financial_job_id', 'financial_claim_generation', 'financial_lock_owner', 'external_order_id',
            'billing_resource_key', 'request_id',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $metadata)) {
                throw new RuntimeException('billing_v2_transport_metadata_incomplete');
            }
        }
        if ($metadata['billing_v2'] !== true || (int) $metadata['capture_run_id'] < 1) {
            throw new RuntimeException('billing_v2_transport_metadata_invalid');
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM meli_billing_capture_runs
             WHERE id=? AND company_id=? AND meli_account_id=? AND request_id=? FOR UPDATE'
        );
        $stmt->execute([
            (int) $metadata['capture_run_id'],
            (int) $metadata['company_id'],
            (int) $metadata['meli_account_id'],
            (string) $metadata['request_id'],
        ]);
        $capture = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($capture)) {
            throw new RuntimeException('billing_v2_capture_identity_missing');
        }
        $matches = (int) $capture['financial_job_id'] === (int) $metadata['financial_job_id']
            && (int) $capture['financial_claim_generation'] === (int) $metadata['financial_claim_generation']
            && (string) $capture['sale_key'] === (string) $metadata['sale_key']
            && (string) $capture['input_version'] === (string) $metadata['input_version']
            && (string) $capture['external_order_id'] === (string) $metadata['external_order_id']
            && hash_equals((string) $capture['billing_resource_key'], (string) $metadata['billing_resource_key']);
        if (!$matches || preg_match('/^[a-f0-9]{40}$/', (string) $metadata['request_id']) !== 1) {
            throw new RuntimeException('billing_v2_capture_provenance_mismatch');
        }
        return $capture;
    }

    private function queueKnowsResponse(PDO $pdo, array $capture): bool
    {
        if ((string) ($capture['request_id'] ?? '') === '') {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM queue_v4_clean_transport_events
             WHERE company_id=? AND meli_account_id=? AND request_id=?
               AND dispatch_state="RESPONSE_KNOWN" LIMIT 1'
        );
        $stmt->execute([(int) $capture['company_id'], (int) $capture['meli_account_id'], (string) $capture['request_id']]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $headers @return list<string> */
    private function missingFieldsFromHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'x-content-missing') {
                continue;
            }
            $raw = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
            return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn (string $field): bool => $field !== '')));
        }
        return [];
    }

    private function recoverSourceTo(PDO $pdo, array $job, string $status, bool $refundAttempt, string $lastRemoteState): string
    {
        $retry = $status === 'retry';
        $update = $pdo->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET status=?,next_run_at=IF(?=1,UTC_TIMESTAMP(),next_run_at),
                 safe_message=?,last_remote_state=?,completed_at=IF(?=1,UTC_TIMESTAMP(),NULL),
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,
                 attempts=IF(?=1,GREATEST(attempts-1,0),attempts)
             WHERE id=? AND company_id=? AND meli_account_id=? AND status="running"
               AND lock_owner=? AND lease_generation=? AND last_remote_state=?
               AND lease_expires_at<UTC_TIMESTAMP()'
        );
        $message = match ($lastRemoteState) {
            'billing_v2_not_dispatched_recovered' => 'Billing V2 confirmó que la generación vencida no cruzó la frontera física; continuará sin penalidad.',
            'billing_v2_offline_reconcile' => 'Billing V2 conserva un resultado durable para conciliación local sin repetir HTTP.',
            'remote_result_uncertain' => 'El dispatch Billing quedó comprometido sin resultado durable; requiere revisión y no se repetirá HTTP.',
            default => 'La evidencia de captura Billing V2 no concuerda; se requiere revisión manual.',
        };
        $update->execute([
            $status,
            $retry ? 1 : 0,
            $message,
            $lastRemoteState,
            $status === 'review' ? 1 : 0,
            $refundAttempt ? 1 : 0,
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['lock_owner'],
            (int) $job['lease_generation'],
            self::V2_CLAIM_MARKER,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('billing_v2_expired_source_recovery_cas_lost');
        }
        $pdo->commit();
        return $status === 'review' ? 'review' : ($lastRemoteState === 'billing_v2_offline_reconcile' ? 'offline_reconcile' : 'retry_not_dispatched');
    }
}
