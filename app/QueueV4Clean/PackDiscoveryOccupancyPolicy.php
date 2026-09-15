<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

/**
 * R0/H3 local candidate: exact pack-discovery admission occupancy.
 *
 * This is not a call budget. It only bounds unresolved pack-discovery units
 * admitted into Queue V4 while preserving the three explicitly authorized
 * historical identities as a closed, non-renewable exception.
 */
final class PackDiscoveryOccupancyPolicy
{
    public const AUTHORITY_KEY = 'queue_v4.pack_discovery_h3_authority';
    public const OUTSTANDING_TARGET = 2;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lockAdmissionAuthority(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('r0_pack_discovery_transaction_required');
        }

        $this->lockControlRow();
    }

    /**
     * The caller owns the transaction and source lock.
     *
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $payload
     * @return array{allowed:bool,reason:string,outstanding:int}
     */
    public function inspectAdmission(
        int $companyId,
        int $accountId,
        int $sourceId,
        string $idempotencyKey,
        array $sourceRow,
        array $payload,
        ?string $storedIdempotencyKey = null,
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('r0_pack_discovery_transaction_required');
        }

        $this->lockControlRow();

        $authority = $this->loadAuthority(true);
        if (!$authority['valid']) {
            return $this->decision(false, (string) $authority['reason'], 0);
        }
        if (!$this->inScope($authority['scope'], $companyId, $accountId)) {
            return $this->decision(false, 'R0_SCOPE_DENIED', 0);
        }
        if (!$this->validCandidate($companyId, $accountId, $sourceId, $sourceRow, $payload)) {
            return $this->decision(false, 'R0_SOURCE_IDENTITY_INVALID', 0);
        }
        if ($this->sourceHasUnresolvedResult($sourceRow)) {
            return $this->decision(false, 'R0_SOURCE_UNCERTAIN_HELD', 0);
        }
        try {
            $historicalMatches = $this->historicalAuthorityStillMatches($authority['historical']);
        } catch (Throwable) {
            return $this->decision(false, 'R0_H3_AUTHORITY_UNAVAILABLE', 0);
        }
        if (!$historicalMatches) {
            return $this->decision(false, 'R0_H3_AUTHORITY_CHANGED', 0);
        }

        $alreadyAdmitted = $this->isAlreadyAdmitted($companyId, $accountId, $sourceId, $idempotencyKey, $storedIdempotencyKey);
        if (!$alreadyAdmitted && !$this->validNewAdmissionSource($sourceRow)) {
            return $this->decision(false, 'R0_SOURCE_NOT_ELIGIBLE', 0);
        }
        try {
            $outstanding = $this->outstanding($authority);
        } catch (Throwable) {
            return $this->decision(false, 'R0_OCCUPANCY_UNAVAILABLE', self::OUTSTANDING_TARGET);
        }
        if ($alreadyAdmitted) {
            return $this->decision(true, 'R0_ALREADY_ADMITTED', $outstanding);
        }
        if ($outstanding >= self::OUTSTANDING_TARGET) {
            return $this->decision(false, 'R0_OCCUPANCY_EXHAUSTED', $outstanding);
        }

        return $this->decision(true, 'R0_ADMISSION_ALLOWED', $outstanding);
    }

    /**
     * @param list<array{company_id:int,meli_account_id:int}> $accounts
     */
    public function outstandingForAccounts(array $accounts): int
    {
        if ($accounts === []) {
            return 0;
        }
        if (!$this->pdo->inTransaction()) {
            return self::OUTSTANDING_TARGET;
        }

        $this->lockControlRow();

        $authority = $this->loadAuthority(true);
        try {
            $historicalMatches = $authority['valid'] && $this->historicalAuthorityStillMatches($authority['historical']);
        } catch (Throwable) {
            return self::OUTSTANDING_TARGET;
        }
        if (!$authority['valid'] || !$historicalMatches) {
            return self::OUTSTANDING_TARGET;
        }

        try {
            return $this->outstanding($authority);
        } catch (Throwable) {
            return self::OUTSTANDING_TARGET;
        }
    }

    /** @return array{valid:bool,reason:string,scope:list<array{company_id:int,meli_account_id:int}>,historical:list<array<string,mixed>>} */
    private function loadAuthority(bool $forUpdate = false): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT setting_value FROM app_settings WHERE setting_key=? AND is_encrypted=0 LIMIT 1'
                . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $statement->execute([self::AUTHORITY_KEY]);
            $raw = $statement->fetchColumn();
        } catch (Throwable) {
            return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_UNAVAILABLE', 'scope' => [], 'historical' => []];
        }
        if (!is_string($raw) || trim($raw) === '') {
            return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_MISSING', 'scope' => [], 'historical' => []];
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_MALFORMED', 'scope' => [], 'historical' => []];
        }
        if (!is_array($decoded) || ($decoded['version'] ?? '') !== 'r0-h3-v1') {
            return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_VERSION_INVALID', 'scope' => [], 'historical' => []];
        }
        $scope = $this->normalizeScope($decoded['scope'] ?? null);
        $historical = $this->normalizeHistorical($decoded['historical'] ?? null);
        if ($scope === [] || count($historical) !== 3) {
            return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_INCOMPLETE', 'scope' => [], 'historical' => []];
        }
        foreach ($historical as $entry) {
            if (!$this->inScope($scope, (int) $entry['company_id'], (int) $entry['meli_account_id'])) {
                return ['valid' => false, 'reason' => 'R0_H3_AUTHORITY_SCOPE_MISMATCH', 'scope' => [], 'historical' => []];
            }
        }

        return ['valid' => true, 'reason' => 'OK', 'scope' => $scope, 'historical' => $historical];
    }

    /** @return list<array{company_id:int,meli_account_id:int}> */
    private function normalizeScope(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $scope = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return [];
            }
            $companyId = (int) ($row['company_id'] ?? 0);
            $accountId = (int) ($row['meli_account_id'] ?? 0);
            if ($companyId < 1 || $accountId < 1) {
                return [];
            }
            $key = $companyId . ':' . $accountId;
            $scope[$key] = ['company_id' => $companyId, 'meli_account_id' => $accountId];
        }

        return array_values($scope);
    }

    /** @return list<array<string,mixed>> */
    private function normalizeHistorical(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $historical = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return [];
            }
            $entry = [
                'company_id' => (int) ($row['company_id'] ?? 0),
                'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
                'source_id' => (int) ($row['source_id'] ?? 0),
                'queue_id' => (int) ($row['queue_id'] ?? 0),
                'source_sha256' => strtolower((string) ($row['source_sha256'] ?? '')),
                'queue_sha256' => strtolower((string) ($row['queue_sha256'] ?? '')),
                'attempts_sha256' => strtolower((string) ($row['attempts_sha256'] ?? '')),
                'transport_events_sha256' => strtolower((string) ($row['transport_events_sha256'] ?? '')),
            ];
            if ($entry['company_id'] < 1 || $entry['meli_account_id'] < 1 || $entry['source_id'] < 1 || $entry['queue_id'] < 1
                || preg_match('/^[a-f0-9]{64}$/', $entry['source_sha256']) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $entry['queue_sha256']) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $entry['attempts_sha256']) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $entry['transport_events_sha256']) !== 1) {
                return [];
            }
            $key = $entry['company_id'] . ':' . $entry['meli_account_id'] . ':' . $entry['source_id'] . ':' . $entry['queue_id'];
            if (isset($historical[$key])) {
                return [];
            }
            $historical[$key] = $entry;
        }

        return array_values($historical);
    }

    /** @param list<array{company_id:int,meli_account_id:int}> $scope */
    private function inScope(array $scope, int $companyId, int $accountId): bool
    {
        foreach ($scope as $row) {
            if ((int) $row['company_id'] === $companyId && (int) $row['meli_account_id'] === $accountId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $sourceRow @param array<string,mixed> $payload */
    private function validCandidate(int $companyId, int $accountId, int $sourceId, array $sourceRow, array $payload): bool
    {
        if ((int) ($sourceRow['id'] ?? 0) !== $sourceId
            || (int) ($sourceRow['company_id'] ?? 0) !== $companyId
            || (int) ($sourceRow['meli_account_id'] ?? 0) !== $accountId
            || (string) ($sourceRow['resource_type'] ?? '') !== 'pack') {
            return false;
        }
        $packId = trim((string) (($payload['pack_id'] ?? null) ?: ($payload['payload']['pack_id'] ?? '')));
        return $packId === '' || hash_equals((string) ($sourceRow['external_resource_id'] ?? ''), $packId);
    }

    /** @param array<string,mixed> $sourceRow */
    private function sourceHasUnresolvedResult(array $sourceRow): bool
    {
        $failure = (string) ($sourceRow['failure_class'] ?? '');
        return in_array($failure, ['remote_result_uncertain', 'remote_result_uncertain_safe_get'], true);
    }

    /** @param array<string,mixed> $sourceRow */
    private function validNewAdmissionSource(array $sourceRow): bool
    {
        if (!in_array(strtolower((string) ($sourceRow['status'] ?? '')), ['pending', 'retry'], true)) {
            return false;
        }
        if ($this->activeSourceLease($sourceRow)) {
            return false;
        }
        $nextRunAt = trim((string) ($sourceRow['next_run_at'] ?? ''));
        if ($nextRunAt === '') {
            return false;
        }
        $timestamp = strtotime($nextRunAt . ' UTC');

        return $timestamp !== false && $timestamp <= time();
    }

    private function lockControlRow(): void
    {
        $row = $this->pdo->query("SELECT control_key FROM queue_v4_clean_control WHERE control_key='primary' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('r0_pack_discovery_control_missing');
        }
    }

    /** @param list<array<string,mixed>> $historical */
    private function historicalAuthorityStillMatches(array $historical): bool
    {
        foreach ($historical as $entry) {
            if (!$this->historicalEntryMatches($entry)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $entry */
    private function historicalEntryMatches(array $entry): bool
    {
        $source = $this->fetchOne('SELECT * FROM order_resource_enrichment_jobs WHERE id=? AND meli_account_id=? AND resource_type="pack" FOR UPDATE', [
            (int) $entry['source_id'],
            (int) $entry['meli_account_id'],
        ]);
        if (!is_array($source) || !hash_equals((string) $entry['source_sha256'], $this->hashValue($source))) {
            return false;
        }
        if ($this->activeSourceLease($source) || $this->activeManualReservation((int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['source_id'])) {
            return false;
        }
        $queue = $this->fetchOne('SELECT * FROM queue_v4_clean_jobs WHERE id=? AND company_id=? AND meli_account_id=? FOR UPDATE', [
            (int) $entry['queue_id'],
            (int) $entry['company_id'],
            (int) $entry['meli_account_id'],
        ]);
        if (!is_array($queue) || !hash_equals((string) $entry['queue_sha256'], $this->hashValue($queue))) {
            return false;
        }
        if ($this->activeQueueLease($queue)) {
            return false;
        }
        if ($this->historicalSourceHasAdditionalPointers((int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['source_id'], (int) $entry['queue_id'])) {
            return false;
        }
        if (!hash_equals(
            (string) $entry['attempts_sha256'],
            $this->attemptsHash((int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id'])
        )) {
            return false;
        }

        return hash_equals(
            (string) $entry['transport_events_sha256'],
            $this->transportEventsHash((int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id'])
        );
    }

    /** @param list<array{company_id:int,meli_account_id:int}>|null $accounts */
    private function outstanding(array $authority, ?array $accounts = null): int
    {
        $scope = $accounts ?? $authority['scope'];
        $clauses = [];
        $params = [];
        foreach ($scope as $row) {
            if (!$this->inScope($authority['scope'], (int) $row['company_id'], (int) $row['meli_account_id'])) {
                continue;
            }
            $clauses[] = '(q.company_id=? AND q.meli_account_id=?)';
            $params[] = (int) $row['company_id'];
            $params[] = (int) $row['meli_account_id'];
        }
        if ($clauses === []) {
            return 0;
        }
        if ($this->malformedOccupantExists($authority['scope'])) {
            return self::OUTSTANDING_TARGET;
        }
        $historical = [];
        foreach ($authority['historical'] as $entry) {
            $historical[] = (int) $entry['company_id'] . ':' . (int) $entry['meli_account_id'] . ':' . (int) $entry['source_id'];
        }

        $statement = $this->pdo->prepare(
            "SELECT q.id queue_id,q.company_id,q.meli_account_id,q.resource_id,
                    CAST(q.resource_id AS UNSIGNED) source_id,
                    q.payload_json,q.idempotency_key,q.state,q.completed_at,
                    q.last_error_class,q.lease_owner,q.lease_expires_at,q.lease_generation,
                    j.status source_status,j.completed_at source_completed_at,j.failure_class,
                    j.resource_type,j.external_resource_id,
                    j.lock_token source_lock_token,j.locked_at source_locked_at,
                    a.company_id source_company_id
               FROM queue_v4_clean_jobs q
               JOIN order_resource_enrichment_jobs j
                 ON j.id=CAST(q.resource_id AS UNSIGNED)
                AND j.meli_account_id=q.meli_account_id
               JOIN meli_accounts a
                 ON a.id=j.meli_account_id AND a.company_id=q.company_id
               WHERE q.job_type='domain_exact'
                 AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
                 AND (" . implode(' OR ', $clauses) . ')
              FOR UPDATE'
        );
        $statement->execute($params);
        $seen = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->validOccupant($row)) {
                return self::OUTSTANDING_TARGET;
            }
            $key = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'] . ':' . (int) $row['source_id'];
            if (in_array($key, $historical, true) || isset($seen[$key])) {
                continue;
            }
            if ($this->isClosed($row)) {
                continue;
            }
            $seen[$key] = true;
        }

        return count($seen);
    }

    /** @param list<array{company_id:int,meli_account_id:int}> $scope */
    private function malformedOccupantExists(array $scope): bool
    {
        $queueClauses = [];
        $sourceClauses = [];
        $params = [];
        foreach ($scope as $row) {
            $queueClauses[] = '(q.company_id=? AND q.meli_account_id=?)';
            $params[] = (int) $row['company_id'];
            $params[] = (int) $row['meli_account_id'];
        }
        foreach ($scope as $row) {
            $sourceClauses[] = '(a.company_id=? AND j.meli_account_id=?)';
            $params[] = (int) $row['company_id'];
            $params[] = (int) $row['meli_account_id'];
        }
        $statement = $this->pdo->prepare(
            "SELECT 1
               FROM queue_v4_clean_jobs q
               LEFT JOIN order_resource_enrichment_jobs j
                 ON q.resource_id REGEXP '^[0-9]+$'
                AND j.id=CAST(q.resource_id AS UNSIGNED)
                AND j.meli_account_id=q.meli_account_id
               LEFT JOIN meli_accounts a
                 ON a.id=j.meli_account_id
              WHERE q.job_type='domain_exact'
                AND (
                    (
                        (" . implode(' OR ', $queueClauses) . ")
                        AND (
                            JSON_VALID(q.payload_json)=0
                            OR q.idempotency_key LIKE 'domain:order_enrichment_pack:%'
                            OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability')),'')='order_enrichment_pack'
                        )
                    )
                    OR (
                        (" . implode(' OR ', $sourceClauses) . ")
                        AND j.resource_type='pack'
                        AND (
                            q.idempotency_key LIKE 'domain:order_enrichment_pack:%'
                            OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability')),'')='order_enrichment_pack'
                        )
                    )
                )
                AND (
                    JSON_VALID(q.payload_json)=0
                    OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability')),'')<>'order_enrichment_pack'
                    OR q.resource_id NOT REGEXP '^[0-9]+$'
                    OR j.id IS NULL
                    OR a.id IS NULL
                    OR a.company_id<>q.company_id
                    OR j.resource_type<>'pack'
                    OR CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.source_id')) AS CHAR)<>q.resource_id
                    OR (
                        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.payload.pack_id')),'')<>''
                        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.payload.pack_id')),'')<>j.external_resource_id
                    )
                )
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function isClosed(array $row): bool
    {
        if ((string) ($row['state'] ?? '') !== 'completed'
            || (string) ($row['source_status'] ?? '') !== 'complete'
            || empty($row['completed_at'])
            || empty($row['source_completed_at'])
            || (string) ($row['failure_class'] ?? '') !== ''
            || (string) ($row['last_error_class'] ?? '') !== '') {
            return false;
        }

        return !$this->activeQueueLease($row)
            && !$this->activeSourceLease([
                'lock_token' => $row['source_lock_token'] ?? null,
                'locked_at' => $row['source_locked_at'] ?? null,
            ])
            && !$this->activeManualReservation((int) $row['company_id'], (int) $row['meli_account_id'], (int) $row['source_id'])
            && !$this->hasUnresolvedAttempt((int) $row['company_id'], (int) $row['meli_account_id'], (int) $row['queue_id'])
            && !$this->hasUnresolvedTransport((int) $row['company_id'], (int) $row['meli_account_id'], (int) $row['queue_id'])
            && $this->hasSufficientClosureAttempt($row);
    }

    /** @param array<string,mixed> $row */
    private function validOccupant(array $row): bool
    {
        $resourceId = (string) ($row['resource_id'] ?? '');
        if ($resourceId === '' || !ctype_digit($resourceId) || (string) ((int) $resourceId) !== $resourceId) {
            return false;
        }
        if ((int) ($row['source_id'] ?? 0) < 1
            || (int) ($row['source_company_id'] ?? 0) !== (int) ($row['company_id'] ?? 0)
            || (string) ($row['resource_type'] ?? '') !== 'pack') {
            return false;
        }
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($payload)
            || (string) ($payload['capability'] ?? '') !== 'order_enrichment_pack'
            || (int) ($payload['source_id'] ?? 0) !== (int) ($row['source_id'] ?? 0)) {
            return false;
        }
        $packId = trim((string) (($payload['payload']['pack_id'] ?? '') ?: ''));
        return $packId === '' || hash_equals((string) ($row['external_resource_id'] ?? ''), $packId);
    }

    private function isAlreadyAdmitted(int $companyId, int $accountId, int $sourceId, string $idempotencyKey, ?string $storedIdempotencyKey = null): bool
    {
        $storedKey = $storedIdempotencyKey !== null && trim($storedIdempotencyKey) !== ''
            ? trim($storedIdempotencyKey)
            : 'domain:order_enrichment_pack:' . trim($idempotencyKey);
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=? AND job_type='domain_exact'
               AND resource_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId, $storedKey]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function activeQueueLease(array $row): bool
    {
        if ((string) ($row['lease_owner'] ?? '') === '') {
            return false;
        }
        $expires = strtotime((string) ($row['lease_expires_at'] ?? '') . ' UTC');
        return $expires === false || $expires > time();
    }

    /** @param array<string,mixed> $row */
    private function activeSourceLease(array $row): bool
    {
        if ((string) ($row['lock_token'] ?? '') === '') {
            return false;
        }
        $lockedAt = strtotime((string) ($row['locked_at'] ?? '') . ' UTC');
        return $lockedAt === false || $lockedAt > time() - 600;
    }

    private function activeManualReservation(int $companyId, int $accountId, int $sourceId): bool
    {
        if (!$this->tableExists('manual_campaign_reservations') || !$this->tableExists('manual_campaigns')) {
            return false;
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM manual_campaign_reservations r
             JOIN manual_campaigns c ON c.id=r.manual_campaign_id
             WHERE r.queue_key='order_enrichment' AND r.company_id=? AND r.meli_account_id=?
               AND r.source_id=? AND r.status='active' AND r.expires_at>UTC_TIMESTAMP(3)
               AND c.status IN ('active','pausing','paused') LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId]);

        return $statement->fetchColumn() !== false;
    }

    private function historicalSourceHasAdditionalPointers(int $companyId, int $accountId, int $sourceId, int $queueId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=? AND job_type='domain_exact'
               AND resource_id=? AND id<>?
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack'
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId, $queueId]);

        return $statement->fetchColumn() !== false;
    }

    private function hasUnresolvedAttempt(int $companyId, int $accountId, int $queueId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM queue_v4_clean_attempts
             WHERE company_id=? AND meli_account_id=? AND job_id=?
               AND (
                    outcome='running'
                    OR dispatch_state='PHYSICAL_STARTED'
                    OR error_class IN ('remote_result_uncertain','remoteresultuncertainexception','remote_result_uncertain_safe_get')
               )
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $queueId]);

        return $statement->fetchColumn() !== false;
    }

    private function hasUnresolvedTransport(int $companyId, int $accountId, int $queueId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM queue_v4_clean_transport_events
             WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
               AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $queueId]);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function hasSufficientClosureAttempt(array $row): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT *
               FROM queue_v4_clean_attempts
              WHERE company_id=? AND meli_account_id=? AND job_id=?
                AND lease_generation=?
                AND outcome='completed'
                AND error_class IS NULL
                AND finished_at IS NOT NULL
                AND source_closed_at IS NOT NULL
              ORDER BY id DESC
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            (int) $row['company_id'],
            (int) $row['meli_account_id'],
            (int) $row['queue_id'],
            (int) ($row['lease_generation'] ?? 0),
        ]);
        $attempt = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($attempt)) {
            return false;
        }

        $dispatch = (string) ($attempt['dispatch_state'] ?? '');
        if ($dispatch === 'NOT_DISPATCHED') {
            return (int) ($attempt['physical_http_calls'] ?? -1) === 0
                && empty($attempt['physical_started_at'])
                && empty($attempt['http_status'])
                && empty($attempt['response_known_at'])
                && !$this->attemptHasTransportEvents($attempt);
        }

        if ($dispatch !== 'RESPONSE_KNOWN'
            || (int) ($attempt['physical_http_calls'] ?? 0) !== 1
            || (string) ($attempt['transport_method'] ?? '') === ''
            || (string) ($attempt['endpoint_key'] ?? '') === ''
            || empty($attempt['physical_started_at'])
            || empty($attempt['response_known_at'])
            || empty($attempt['http_status'])) {
            return false;
        }

        $event = $this->pdo->prepare(
            "SELECT method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status
               FROM queue_v4_clean_transport_events
              WHERE source_kind='queue' AND work_id=? AND attempt_id=?
                AND company_id=? AND meli_account_id=? AND lease_generation=?
              LIMIT 2 FOR UPDATE"
        );
        $event->execute([
            (int) $row['queue_id'],
            (int) $attempt['id'],
            (int) $row['company_id'],
            (int) $row['meli_account_id'],
            (int) $attempt['lease_generation'],
        ]);
        $events = $event->fetchAll(PDO::FETCH_ASSOC);
        if (count($events) !== 1) {
            return false;
        }
        $onlyEvent = $events[0];

        return (string) ($onlyEvent['method'] ?? '') === (string) $attempt['transport_method']
            && (string) ($onlyEvent['endpoint_key'] ?? '') === (string) $attempt['endpoint_key']
            && (string) ($onlyEvent['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
            && !empty($onlyEvent['physical_started_at'])
            && !empty($onlyEvent['response_known_at'])
            && (int) ($onlyEvent['http_status'] ?? 0) === (int) $attempt['http_status'];
    }

    /** @param array<string,mixed> $attempt */
    private function attemptHasTransportEvents(array $attempt): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM queue_v4_clean_transport_events
              WHERE source_kind='queue' AND work_id=? AND attempt_id=?
                AND company_id=? AND meli_account_id=? LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            (int) $attempt['job_id'],
            (int) $attempt['id'],
            (int) $attempt['company_id'],
            (int) $attempt['meli_account_id'],
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function transportEventsHash(int $companyId, int $accountId, int $queueId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM queue_v4_clean_transport_events
             WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
             ORDER BY id FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $queueId]);

        return $this->hashValue($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function attemptsHash(int $companyId, int $accountId, int $queueId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM queue_v4_clean_attempts
             WHERE company_id=? AND meli_account_id=? AND job_id=?
             ORDER BY id FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $queueId]);

        return $this->hashValue($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function hashValue(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{allowed:bool,reason:string,outstanding:int} */
    private function decision(bool $allowed, string $reason, int $outstanding): array
    {
        return ['allowed' => $allowed, 'reason' => $reason, 'outstanding' => $outstanding];
    }
}
