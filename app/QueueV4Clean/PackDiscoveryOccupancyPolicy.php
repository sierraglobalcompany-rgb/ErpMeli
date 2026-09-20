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
    public const UNIT02_DISPOSITION_KEY = 'queue_v4.pack_discovery_unit02_disposition';
    public const UNIT02_DISPOSITION_VERSION = 'k3-unit02-disposition-v1';
    public const UNIT02_DISPOSITION_STATUS = 'occupancy_withdrawn_historical_transport_unknown';
    public const OUTSTANDING_TARGET = 2;

    /** @var list<string> */
    private const UNIT02_HISTORICAL_ATTEMPT_ERROR_CLASSES = [
        'remote_result_uncertain',
        'remoteresultuncertainexception',
        'remote_result_uncertain_safe_get',
        'domain_source_waiting:order_enrichment_pack:remote_uncertain_safe_get',
    ];

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
            $outstanding = $this->occupancy($authority)['outstanding'];
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
        return $this->measureForAccounts($accounts)['outstanding'];
    }

    /**
     * @param list<array{company_id:int,meli_account_id:int}> $accounts
     * @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string}
     */
    public function measureForAccounts(array $accounts): array
    {
        if (!$this->pdo->inTransaction()) {
            return $this->unavailableOccupancyDecision(false);
        }

        $this->lockControlRow();

        $authority = $this->loadAuthority(true);
        try {
            $historicalMatches = $authority['valid'] && $this->historicalAuthorityStillMatches($authority['historical']);
        } catch (Throwable) {
            return $this->unavailableOccupancyDecision(false);
        }
        if (!$authority['valid'] || !$historicalMatches) {
            return $this->unavailableOccupancyDecision(false);
        }
        if ($accounts === []) {
            return $this->occupancyDecision(0, 0, 0);
        }

        try {
            return $this->occupancy($authority, $accounts);
        } catch (Throwable) {
            return $this->unavailableOccupancyDecision(true);
        }
    }

    /** @param array<string,mixed> $authorizedEvidence */
    public function assertUnit02ApplicationContext(array $authorizedEvidence): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('k3_unit02_transaction_required');
        }
        $this->lockControlRow();

        $evidence = $this->normalizeAuthorizedEvidence($authorizedEvidence);
        if ($evidence === null) {
            throw new RuntimeException('k3_unit02_evidence_invalid');
        }
        $authority = $this->loadAuthority(true);
        if (!$authority['valid']) {
            throw new RuntimeException($this->unit02H3FailureReason((string) $authority['reason']));
        }
        try {
            $historicalMatches = $this->historicalAuthorityStillMatches($authority['historical']);
        } catch (Throwable $error) {
            throw new RuntimeException('k3_unit02_h3_authority_unavailable', 0, $error);
        }
        if (!$historicalMatches) {
            throw new RuntimeException('k3_unit02_h3_authority_changed');
        }

        $identity = $evidence['identity'];
        if (!$this->inScope($authority['scope'], (int) $identity['company_id'], (int) $identity['meli_account_id'])) {
            throw new RuntimeException('k3_unit02_h3_scope_denied');
        }
        foreach ($authority['historical'] as $historical) {
            if ((int) $historical['company_id'] === (int) $identity['company_id']
                && (int) $historical['meli_account_id'] === (int) $identity['meli_account_id']
                && (int) $historical['source_id'] === (int) $identity['source_id']
                && (int) $historical['queue_id'] === (int) $identity['queue_id']) {
                throw new RuntimeException('k3_unit02_h3_identity_conflict');
            }
        }
    }

    /**
     * Builds the only document that may later be persisted by the private
     * application service. The caller owns a transaction and the global
     * admission lock. No business row is changed here.
     *
     * @param array<string,mixed> $authorizedEvidence
     * @return array<string,mixed>
     */
    public function prepareUnit02DispositionAuthority(
        array $authorizedEvidence,
        int $actorUserId,
        string $approvedAtUtc,
        string $reason,
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('k3_unit02_transaction_required');
        }
        $this->lockControlRow();
        if ($actorUserId < 1 || !$this->validUtcTimestamp($approvedAtUtc)) {
            throw new RuntimeException('k3_unit02_approval_invalid');
        }
        $reason = trim($reason);
        if (strlen($reason) < 20 || strlen($reason) > 1000) {
            throw new RuntimeException('k3_unit02_reason_invalid');
        }
        $evidence = $this->normalizeAuthorizedEvidence($authorizedEvidence);
        if ($evidence === null) {
            throw new RuntimeException('k3_unit02_evidence_invalid');
        }
        $row = $this->fetchDispositionOccupant($evidence['identity']);
        if (!is_array($row) || !$this->authorizedEvidenceMatches($evidence, $row)) {
            throw new RuntimeException('k3_unit02_evidence_mismatch');
        }
        $packIntegrityHash = $this->packIntegrityHash($evidence['identity']);
        if ($packIntegrityHash === null) {
            throw new RuntimeException('k3_unit02_pack_integrity_invalid');
        }

        return $this->canonicalize([
            'version' => self::UNIT02_DISPOSITION_VERSION,
            'status' => self::UNIT02_DISPOSITION_STATUS,
            'non_renewable' => true,
            'max_units' => 1,
            'approved_by_user_id' => $actorUserId,
            'approved_at_utc' => $approvedAtUtc,
            'reason' => $reason,
            'identity' => $evidence['identity'],
            'hashes' => $evidence['hashes'] + [
                'pack_integrity_sha256' => $packIntegrityHash,
                'policy_sha256' => hash_file('sha256', __FILE__),
            ],
        ]);
    }

    /** @param array<string,mixed> $document */
    public function canonicalDispositionJson(array $document): string
    {
        return json_encode($this->canonicalize($document), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed>|null */
    public function storedUnit02Disposition(): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('k3_unit02_transaction_required');
        }

        return $this->loadUnit02Disposition(true);
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $authorizedEvidence */
    public function dispositionRepresentsEvidence(array $document, array $authorizedEvidence, int $actorUserId, string $reason): bool
    {
        $evidence = $this->normalizeAuthorizedEvidence($authorizedEvidence);
        $normalized = $this->normalizeDispositionDocument($document);
        if ($evidence === null || $normalized === null) {
            return false;
        }

        return (int) $normalized['approved_by_user_id'] === $actorUserId
            && hash_equals((string) $normalized['reason'], trim($reason))
            && $normalized['identity'] === $evidence['identity']
            && $this->hashSubsetMatches($normalized['hashes'], $evidence['hashes'])
            && $this->dispositionMatchesCurrentState($normalized);
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

    /**
     * @param list<array{company_id:int,meli_account_id:int}>|null $accounts
     * @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string}
     */
    private function occupancy(array $authority, ?array $accounts = null): array
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
            return $this->occupancyDecision(0, 0, 0);
        }
        if ($this->malformedOccupantExists($authority['scope'])) {
            throw new RuntimeException('r0_pack_discovery_occupancy_malformed');
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
        $disposition = $this->loadUnit02Disposition(true);
        $units = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->validOccupant($row)) {
                throw new RuntimeException('r0_pack_discovery_occupant_invalid');
            }
            $key = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'] . ':' . (int) $row['source_id'];
            if (in_array($key, $historical, true)) {
                continue;
            }
            $units[$key][] = $row;
        }

        $seen = [];
        $disposed = [];
        foreach ($units as $key => $rows) {
            $allClosed = true;
            $matchesDisposition = false;
            foreach ($rows as $row) {
                if (!$this->isClosed($row)) {
                    $allClosed = false;
                }
                if (is_array($disposition) && $this->dispositionMatchesRow($disposition, $row)) {
                    $matchesDisposition = true;
                }
            }
            if ($allClosed) {
                continue;
            }
            if ($matchesDisposition) {
                $disposed[$key] = true;
                continue;
            }
            $seen[$key] = true;
        }

        return $this->occupancyDecision(
            count($seen),
            count($disposed),
            $this->physicalUnknownDispatches($units),
        );
    }

    /** @param array<string,list<array<string,mixed>>> $units */
    private function physicalUnknownDispatches(array $units): int
    {
        $queueScope = [];
        foreach ($units as $rows) {
            foreach ($rows as $row) {
                $queueId = (int) $row['queue_id'];
                $queueScope[$queueId] = [
                    'company_id' => (int) $row['company_id'],
                    'meli_account_id' => (int) $row['meli_account_id'],
                ];
            }
        }
        if ($queueScope === []) {
            return 0;
        }

        $ids = array_keys($queueScope);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $attemptStatement = $this->pdo->prepare(
            "SELECT id,job_id,company_id,meli_account_id,lease_generation,outcome,error_class,
                    dispatch_state,transport_method,endpoint_key,physical_http_calls,
                    physical_started_at,response_known_at,http_status
               FROM queue_v4_clean_attempts
              WHERE job_id IN ($placeholders)
              ORDER BY id FOR UPDATE"
        );
        $attemptStatement->execute($ids);
        $attempts = [];
        foreach ($attemptStatement->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $jobId = (int) $attempt['job_id'];
            $scope = $queueScope[$jobId] ?? null;
            if (!is_array($scope)
                || (int) $attempt['company_id'] !== $scope['company_id']
                || (int) $attempt['meli_account_id'] !== $scope['meli_account_id']) {
                throw new RuntimeException('r0_pack_discovery_attempt_scope_inconsistent');
            }
            $attempts[(int) $attempt['id']] = $attempt;
        }

        $eventStatement = $this->pdo->prepare(
            "SELECT id,work_id,attempt_id,company_id,meli_account_id,lease_generation,
                    method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status
               FROM queue_v4_clean_transport_events
              WHERE source_kind='queue' AND work_id IN ($placeholders)
              ORDER BY id FOR UPDATE"
        );
        $eventStatement->execute($ids);
        $eventsByAttempt = [];
        foreach ($eventStatement->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $workId = (int) $event['work_id'];
            $scope = $queueScope[$workId] ?? null;
            if (!is_array($scope)
                || (int) $event['company_id'] !== $scope['company_id']
                || (int) $event['meli_account_id'] !== $scope['meli_account_id']) {
                throw new RuntimeException('r0_pack_discovery_transport_scope_inconsistent');
            }
            $attemptId = (int) ($event['attempt_id'] ?? 0);
            if ($attemptId < 1) {
                if ((string) $event['dispatch_state'] === 'PHYSICAL_STARTED' && empty($event['response_known_at'])) {
                    throw new RuntimeException('r0_pack_discovery_transport_orphaned');
                }
                continue;
            }
            $eventsByAttempt[$attemptId][] = $event;
        }

        $unknown = 0;
        foreach ($attempts as $attemptId => $attempt) {
            $dispatch = (string) ($attempt['dispatch_state'] ?? '');
            $events = $eventsByAttempt[$attemptId] ?? [];
            if ($dispatch === 'NOT_DISPATCHED') {
                if ((int) ($attempt['physical_http_calls'] ?? -1) !== 0
                    || !empty($attempt['physical_started_at'])
                    || !empty($attempt['response_known_at'])
                    || !empty($attempt['http_status'])
                    || $events !== []) {
                    throw new RuntimeException('r0_pack_discovery_not_dispatched_inconsistent');
                }
                continue;
            }
            if ($dispatch === 'RESPONSE_KNOWN') {
                foreach ($events as $event) {
                    if ((string) $event['dispatch_state'] === 'PHYSICAL_STARTED' && empty($event['response_known_at'])) {
                        throw new RuntimeException('r0_pack_discovery_known_attempt_has_unknown_transport');
                    }
                }
                continue;
            }
            if ($dispatch !== 'PHYSICAL_STARTED'
                || (int) ($attempt['physical_http_calls'] ?? 0) !== 1
                || empty($attempt['physical_started_at'])
                || !empty($attempt['response_known_at'])
                || !empty($attempt['http_status'])
                || (string) ($attempt['transport_method'] ?? '') === ''
                || (string) ($attempt['endpoint_key'] ?? '') === ''
                || count($events) !== 1) {
                throw new RuntimeException('r0_pack_discovery_unknown_attempt_inconsistent');
            }
            $event = $events[0];
            if ((int) $event['work_id'] !== (int) $attempt['job_id']
                || (int) $event['company_id'] !== (int) $attempt['company_id']
                || (int) $event['meli_account_id'] !== (int) $attempt['meli_account_id']
                || (int) $event['lease_generation'] !== (int) $attempt['lease_generation']
                || (string) $event['method'] !== (string) $attempt['transport_method']
                || (string) $event['endpoint_key'] !== (string) $attempt['endpoint_key']
                || (string) $event['dispatch_state'] !== 'PHYSICAL_STARTED'
                || empty($event['physical_started_at'])
                || !empty($event['response_known_at'])
                || !empty($event['http_status'])) {
                throw new RuntimeException('r0_pack_discovery_unknown_transport_mismatch');
            }
            $unknown++;
            unset($eventsByAttempt[$attemptId]);
        }
        foreach ($eventsByAttempt as $events) {
            foreach ($events as $event) {
                if ((string) $event['dispatch_state'] === 'PHYSICAL_STARTED' && empty($event['response_known_at'])) {
                    throw new RuntimeException('r0_pack_discovery_transport_attempt_missing');
                }
            }
        }

        return $unknown;
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
    private function loadUnit02Disposition(bool $forUpdate): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value,is_encrypted FROM app_settings WHERE setting_key=? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([self::UNIT02_DISPOSITION_KEY]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((int) ($row['is_encrypted'] ?? 1) !== 0) {
            return null;
        }
        $raw = (string) ($row['setting_value'] ?? '');
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $normalized = $this->normalizeDispositionDocument($decoded);
        if ($normalized === null || !hash_equals($this->canonicalDispositionJson($normalized), $raw)) {
            return null;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $document */
    private function dispositionMatchesRow(array $document, array $row): bool
    {
        $identity = $document['identity'] ?? null;
        if (!is_array($identity)
            || (int) ($row['company_id'] ?? 0) !== (int) ($identity['company_id'] ?? 0)
            || (int) ($row['meli_account_id'] ?? 0) !== (int) ($identity['meli_account_id'] ?? 0)
            || (int) ($row['source_id'] ?? 0) !== (int) ($identity['source_id'] ?? 0)
            || (int) ($row['queue_id'] ?? 0) !== (int) ($identity['queue_id'] ?? 0)) {
            return false;
        }

        return $this->dispositionMatchesCurrentState($document, $row);
    }

    /** @param array<string,mixed> $document @param array<string,mixed>|null $knownRow */
    private function dispositionMatchesCurrentState(array $document, ?array $knownRow = null): bool
    {
        $normalized = $this->normalizeDispositionDocument($document);
        if ($normalized === null
            || !hash_equals((string) $normalized['hashes']['policy_sha256'], (string) hash_file('sha256', __FILE__))) {
            return false;
        }
        $row = $knownRow ?? $this->fetchDispositionOccupant($normalized['identity']);
        if (!is_array($row)) {
            return false;
        }
        $evidence = [
            'identity' => $normalized['identity'],
            'hashes' => [
                'source_sha256' => $normalized['hashes']['source_sha256'],
                'queue_sha256' => $normalized['hashes']['queue_sha256'],
                'attempts_sha256' => $normalized['hashes']['attempts_sha256'],
                'transport_events_sha256' => $normalized['hashes']['transport_events_sha256'],
                'evidence_zip_sha256' => $normalized['hashes']['evidence_zip_sha256'],
            ],
        ];
        if (!$this->authorizedEvidenceMatches($evidence, $row)) {
            return false;
        }
        $packHash = $this->packIntegrityHash($normalized['identity']);

        return is_string($packHash)
            && hash_equals((string) $normalized['hashes']['pack_integrity_sha256'], $packHash);
    }

    /** @param array<string,mixed> $identity @return array<string,mixed>|null */
    private function fetchDispositionOccupant(array $identity): ?array
    {
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
              WHERE q.id=? AND q.company_id=? AND q.meli_account_id=?
                AND q.job_type='domain_exact' AND q.resource_id=?
                AND j.id=? AND j.resource_type='pack'
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            (int) ($identity['queue_id'] ?? 0),
            (int) ($identity['company_id'] ?? 0),
            (int) ($identity['meli_account_id'] ?? 0),
            (string) ((int) ($identity['source_id'] ?? 0)),
            (int) ($identity['source_id'] ?? 0),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $evidence @param array<string,mixed> $row */
    private function authorizedEvidenceMatches(array $evidence, array $row): bool
    {
        $identity = $evidence['identity'];
        $hashes = $evidence['hashes'];
        if ((int) $row['company_id'] !== (int) $identity['company_id']
            || (int) $row['meli_account_id'] !== (int) $identity['meli_account_id']
            || (int) $row['source_id'] !== (int) $identity['source_id']
            || (int) $row['queue_id'] !== (int) $identity['queue_id']
            || !hash_equals((string) $row['external_resource_id'], (string) $identity['external_pack_id'])
            || !$this->validOccupant($row)
            || (string) $row['state'] !== 'completed'
            || (string) $row['source_status'] !== 'complete'
            || empty($row['completed_at']) || empty($row['source_completed_at'])
            || (string) ($row['failure_class'] ?? '') !== ''
            || (string) ($row['last_error_class'] ?? '') !== ''
            || $this->activeQueueLease($row)
            || $this->activeSourceLease(['lock_token' => $row['source_lock_token'] ?? null, 'locked_at' => $row['source_locked_at'] ?? null])
            || $this->activeManualReservation((int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['source_id'])
            || $this->historicalSourceHasAdditionalPointers((int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['source_id'], (int) $identity['queue_id'])) {
            return false;
        }
        $source = $this->fetchOne(
            'SELECT * FROM order_resource_enrichment_jobs WHERE id=? AND meli_account_id=? AND resource_type="pack" FOR UPDATE',
            [(int) $identity['source_id'], (int) $identity['meli_account_id']]
        );
        $queue = $this->fetchOne(
            'SELECT * FROM queue_v4_clean_jobs WHERE id=? AND company_id=? AND meli_account_id=? FOR UPDATE',
            [(int) $identity['queue_id'], (int) $identity['company_id'], (int) $identity['meli_account_id']]
        );
        if (!is_array($source) || !is_array($queue)
            || !hash_equals((string) $hashes['source_sha256'], $this->hashValue($source))
            || !hash_equals((string) $hashes['queue_sha256'], $this->hashValue($queue))
            || !hash_equals((string) $hashes['attempts_sha256'], $this->attemptsHash((int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['queue_id']))
            || !hash_equals((string) $hashes['transport_events_sha256'], $this->transportEventsHash((int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['queue_id']))) {
            return false;
        }

        return $this->exactHistoricalUnknownMatches($identity)
            && $this->exactClosureAttemptMatches($identity)
            && $this->onlyAuthorizedHistoricalUncertainty($identity);
    }

    /** @param array<string,mixed> $identity */
    private function exactHistoricalUnknownMatches(array $identity): bool
    {
        $attempt = $this->fetchOne(
            'SELECT * FROM queue_v4_clean_attempts WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=? AND lease_generation=? FOR UPDATE',
            [(int) $identity['historical_attempt_id'], (int) $identity['queue_id'], (int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['historical_generation']]
        );
        if (!is_array($attempt)) {
            return false;
        }
        $event = $this->fetchOne(
            'SELECT * FROM queue_v4_clean_transport_events WHERE id=? AND source_kind="queue" AND work_id=? AND attempt_id=? AND company_id=? AND meli_account_id=? AND lease_generation=? FOR UPDATE',
            [(int) $identity['historical_transport_event_id'], (int) $identity['queue_id'], (int) $identity['historical_attempt_id'], (int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['historical_generation']]
        );

        return is_array($event) && $this->historicalUnknownRowsMatch($identity, $attempt, $event);
    }

    /**
     * Pure row predicate shared with the private evidence verifier.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $event
     */
    private function historicalUnknownRowsMatch(array $identity, array $attempt, array $event): bool
    {
        return (int) ($attempt['id'] ?? 0) === (int) ($identity['historical_attempt_id'] ?? 0)
            && (int) ($attempt['job_id'] ?? 0) === (int) ($identity['queue_id'] ?? 0)
            && (int) ($attempt['company_id'] ?? 0) === (int) ($identity['company_id'] ?? 0)
            && (int) ($attempt['meli_account_id'] ?? 0) === (int) ($identity['meli_account_id'] ?? 0)
            && (int) ($attempt['lease_generation'] ?? 0) === (int) ($identity['historical_generation'] ?? 0)
            && (string) ($attempt['dispatch_state'] ?? '') === 'PHYSICAL_STARTED'
            && (int) ($attempt['physical_http_calls'] ?? 0) === 1
            && in_array((string) ($attempt['error_class'] ?? ''), self::UNIT02_HISTORICAL_ATTEMPT_ERROR_CLASSES, true)
            && empty($attempt['response_known_at'])
            && (int) ($event['id'] ?? 0) === (int) ($identity['historical_transport_event_id'] ?? 0)
            && (string) ($event['source_kind'] ?? '') === 'queue'
            && (int) ($event['work_id'] ?? 0) === (int) ($identity['queue_id'] ?? 0)
            && (int) ($event['attempt_id'] ?? 0) === (int) ($identity['historical_attempt_id'] ?? 0)
            && (int) ($event['company_id'] ?? 0) === (int) ($identity['company_id'] ?? 0)
            && (int) ($event['meli_account_id'] ?? 0) === (int) ($identity['meli_account_id'] ?? 0)
            && (int) ($event['lease_generation'] ?? 0) === (int) ($identity['historical_generation'] ?? 0)
            && (string) ($event['dispatch_state'] ?? '') === 'PHYSICAL_STARTED'
            && !empty($event['physical_started_at'])
            && empty($event['response_known_at'])
            && empty($event['http_status'])
            && (string) ($event['method'] ?? '') === (string) ($attempt['transport_method'] ?? '')
            && (string) ($event['endpoint_key'] ?? '') === (string) ($attempt['endpoint_key'] ?? '');
    }

    /** @param array<string,mixed> $identity */
    private function exactClosureAttemptMatches(array $identity): bool
    {
        $attempt = $this->fetchOne(
            'SELECT * FROM queue_v4_clean_attempts WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=? AND lease_generation=? FOR UPDATE',
            [(int) $identity['closure_attempt_id'], (int) $identity['queue_id'], (int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['closure_generation']]
        );
        if (!is_array($attempt)
            || (string) ($attempt['outcome'] ?? '') !== 'completed'
            || !empty($attempt['error_class'])
            || (string) ($attempt['dispatch_state'] ?? '') !== 'RESPONSE_KNOWN'
            || (int) ($attempt['physical_http_calls'] ?? 0) !== 1
            || empty($attempt['finished_at']) || empty($attempt['source_closed_at'])
            || empty($attempt['physical_started_at']) || empty($attempt['response_known_at'])
            || (int) ($attempt['http_status'] ?? 0) < 200 || (int) ($attempt['http_status'] ?? 0) >= 300) {
            return false;
        }
        $event = $this->fetchOne(
            'SELECT * FROM queue_v4_clean_transport_events WHERE id=? AND source_kind="queue" AND work_id=? AND attempt_id=? AND company_id=? AND meli_account_id=? AND lease_generation=? FOR UPDATE',
            [(int) $identity['closure_transport_event_id'], (int) $identity['queue_id'], (int) $identity['closure_attempt_id'], (int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['closure_generation']]
        );

        return is_array($event)
            && (string) ($event['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
            && !empty($event['physical_started_at']) && !empty($event['response_known_at'])
            && (int) ($event['http_status'] ?? 0) === (int) $attempt['http_status']
            && (string) ($event['method'] ?? '') === (string) ($attempt['transport_method'] ?? '')
            && (string) ($event['endpoint_key'] ?? '') === (string) ($attempt['endpoint_key'] ?? '');
    }

    /** @param array<string,mixed> $identity */
    private function onlyAuthorizedHistoricalUncertainty(array $identity): bool
    {
        $attempts = $this->pdo->prepare(
            "SELECT id FROM queue_v4_clean_attempts
             WHERE company_id=? AND meli_account_id=? AND job_id=?
               AND (outcome='running' OR dispatch_state='PHYSICAL_STARTED'
                    OR error_class IN ('remote_result_uncertain','remoteresultuncertainexception','remote_result_uncertain_safe_get'))
             ORDER BY id FOR UPDATE"
        );
        $attempts->execute([(int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['queue_id']]);
        $attemptIds = array_map('intval', $attempts->fetchAll(PDO::FETCH_COLUMN));
        $events = $this->pdo->prepare(
            "SELECT id FROM queue_v4_clean_transport_events
             WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
               AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL
             ORDER BY id FOR UPDATE"
        );
        $events->execute([(int) $identity['company_id'], (int) $identity['meli_account_id'], (int) $identity['queue_id']]);
        $eventIds = array_map('intval', $events->fetchAll(PDO::FETCH_COLUMN));

        return $attemptIds === [(int) $identity['historical_attempt_id']]
            && $eventIds === [(int) $identity['historical_transport_event_id']];
    }

    /** @param array<string,mixed> $identity */
    private function packIntegrityHash(array $identity): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id,p.meli_account_id,p.external_pack_id,p.status,p.integrity_status,
                    p.expected_orders_count,p.linked_orders_count,p.expected_orders_json,p.orders_fingerprint
               FROM meli_packs p
               JOIN order_resource_enrichment_jobs j
                 ON j.meli_account_id=p.meli_account_id AND j.external_resource_id=p.external_pack_id
               JOIN meli_accounts a ON a.id=p.meli_account_id
              WHERE p.id=? AND p.meli_account_id=? AND p.external_pack_id=?
                AND j.id=? AND j.resource_type='pack' AND a.company_id=?
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            (int) $identity['pack_row_id'], (int) $identity['meli_account_id'], (string) $identity['external_pack_id'],
            (int) $identity['source_id'], (int) $identity['company_id'],
        ]);
        $pack = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($pack) || (string) ($pack['integrity_status'] ?? '') !== 'complete') {
            return null;
        }
        $expected = $this->strictOrderIds((string) ($pack['expected_orders_json'] ?? ''));
        if ($expected === null || $expected === []) {
            return null;
        }
        $linkedStatement = $this->pdo->prepare(
            'SELECT DISTINCT o.external_order_id
               FROM meli_pack_orders po
               JOIN meli_orders o
                 ON o.id=po.meli_order_id AND o.meli_account_id=? AND o.external_pack_id=?
              WHERE po.meli_pack_id=? ORDER BY o.external_order_id FOR UPDATE'
        );
        $linkedStatement->execute([(int) $identity['meli_account_id'], (string) $identity['external_pack_id'], (int) $identity['pack_row_id']]);
        $linked = [];
        foreach ($linkedStatement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $id = trim((string) $value);
            if ($id === '' || !ctype_digit($id) || isset($linked[$id])) {
                return null;
            }
            $linked[$id] = $id;
        }
        $linked = array_values($linked);
        sort($linked, SORT_STRING);
        if ($expected !== $linked
            || (int) ($pack['expected_orders_count'] ?? -1) !== count($expected)
            || (int) ($pack['linked_orders_count'] ?? -1) !== count($linked)
            || !hash_equals(hash('sha256', implode('|', $expected)), (string) ($pack['orders_fingerprint'] ?? ''))) {
            return null;
        }

        return $this->hashValue($this->canonicalize([
            'pack_row_id' => (int) $pack['id'],
            'meli_account_id' => (int) $pack['meli_account_id'],
            'external_pack_id' => (string) $pack['external_pack_id'],
            'status' => (string) $pack['status'],
            'integrity_status' => (string) $pack['integrity_status'],
            'expected_order_ids' => $expected,
            'linked_order_ids' => $linked,
            'orders_fingerprint' => (string) $pack['orders_fingerprint'],
        ]));
    }

    /** @return list<string>|null */
    private function strictOrderIds(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }
        $ids = [];
        foreach ($decoded as $value) {
            if (!is_string($value) && !is_int($value)) {
                return null;
            }
            $id = trim((string) $value);
            if ($id === '' || !ctype_digit($id) || isset($ids[$id])) {
                return null;
            }
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed>|null */
    private function normalizeAuthorizedEvidence(array $evidence): ?array
    {
        if (!$this->exactKeys($evidence, ['hashes', 'identity'])
            || !is_array($evidence['identity']) || !is_array($evidence['hashes'])) {
            return null;
        }
        $identityKeys = [
            'closure_attempt_id', 'closure_generation', 'closure_transport_event_id', 'company_id',
            'external_pack_id', 'historical_attempt_id', 'historical_generation', 'historical_transport_event_id',
            'meli_account_id', 'pack_row_id', 'queue_id', 'source_id',
        ];
        $hashKeys = ['attempts_sha256', 'evidence_zip_sha256', 'queue_sha256', 'source_sha256', 'transport_events_sha256'];
        if (!$this->exactKeys($evidence['identity'], $identityKeys) || !$this->exactKeys($evidence['hashes'], $hashKeys)) {
            return null;
        }
        foreach ($identityKeys as $key) {
            if ($key === 'external_pack_id') {
                if (!is_string($evidence['identity'][$key]) || trim($evidence['identity'][$key]) === '') {
                    return null;
                }
                continue;
            }
            if (!is_int($evidence['identity'][$key]) || $evidence['identity'][$key] < 1) {
                return null;
            }
        }
        foreach ($hashKeys as $key) {
            if (!is_string($evidence['hashes'][$key]) || preg_match('/^[a-f0-9]{64}$/D', $evidence['hashes'][$key]) !== 1) {
                return null;
            }
        }

        return $this->canonicalize($evidence);
    }

    /** @param array<string,mixed> $document @return array<string,mixed>|null */
    private function normalizeDispositionDocument(array $document): ?array
    {
        $top = ['approved_at_utc', 'approved_by_user_id', 'hashes', 'identity', 'max_units', 'non_renewable', 'reason', 'status', 'version'];
        if (!$this->exactKeys($document, $top)
            || $document['version'] !== self::UNIT02_DISPOSITION_VERSION
            || $document['status'] !== self::UNIT02_DISPOSITION_STATUS
            || $document['non_renewable'] !== true
            || $document['max_units'] !== 1
            || !is_int($document['approved_by_user_id']) || $document['approved_by_user_id'] < 1
            || !is_string($document['approved_at_utc']) || !$this->validUtcTimestamp($document['approved_at_utc'])
            || !is_string($document['reason']) || strlen(trim($document['reason'])) < 20 || strlen(trim($document['reason'])) > 1000
            || !is_array($document['identity']) || !is_array($document['hashes'])) {
            return null;
        }
        $evidenceHashes = $document['hashes'];
        $packHash = $evidenceHashes['pack_integrity_sha256'] ?? null;
        $policyHash = $evidenceHashes['policy_sha256'] ?? null;
        unset($evidenceHashes['pack_integrity_sha256'], $evidenceHashes['policy_sha256']);
        $evidence = $this->normalizeAuthorizedEvidence(['identity' => $document['identity'], 'hashes' => $evidenceHashes]);
        if ($evidence === null
            || !is_string($packHash) || preg_match('/^[a-f0-9]{64}$/D', $packHash) !== 1
            || !is_string($policyHash) || preg_match('/^[a-f0-9]{64}$/D', $policyHash) !== 1
            || !$this->exactKeys($document['hashes'], [
                'attempts_sha256', 'evidence_zip_sha256', 'pack_integrity_sha256', 'policy_sha256',
                'queue_sha256', 'source_sha256', 'transport_events_sha256',
            ])) {
            return null;
        }
        $document['identity'] = $evidence['identity'];
        $document['hashes'] = $this->canonicalize($document['hashes']);
        $document['reason'] = trim($document['reason']);

        return $this->canonicalize($document);
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function hashSubsetMatches(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!isset($actual[$key]) || !is_string($actual[$key]) || !hash_equals((string) $value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }

    private function validUtcTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $date->format('Y-m-d\\TH:i:s\\Z') === $value;
    }

    /** @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    /** @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string} */
    private function occupancyDecision(int $outstanding, int $dispositions, int $unknown): array
    {
        return [
            'outstanding' => $outstanding,
            'administrative_dispositions' => $dispositions,
            'physical_unknown_dispatches' => $unknown,
            'measurement_status' => 'CERTIFIED',
            'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
        ];
    }

    /** @return array{outstanding:int,administrative_dispositions:null,physical_unknown_dispatches:null,measurement_status:string,measurement_scope:string} */
    private function unavailableOccupancyDecision(bool $h3ScopeVerified): array
    {
        return [
            'outstanding' => self::OUTSTANDING_TARGET,
            'administrative_dispositions' => null,
            'physical_unknown_dispatches' => null,
            'measurement_status' => 'UNAVAILABLE',
            'measurement_scope' => $h3ScopeVerified ? 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY' : 'UNVERIFIED',
        ];
    }

    private function unit02H3FailureReason(string $reason): string
    {
        return match ($reason) {
            'R0_H3_AUTHORITY_MISSING' => 'k3_unit02_h3_authority_missing',
            'R0_H3_AUTHORITY_MALFORMED' => 'k3_unit02_h3_authority_malformed',
            'R0_H3_AUTHORITY_VERSION_INVALID' => 'k3_unit02_h3_authority_version_invalid',
            'R0_H3_AUTHORITY_INCOMPLETE' => 'k3_unit02_h3_authority_incomplete',
            'R0_H3_AUTHORITY_SCOPE_MISMATCH' => 'k3_unit02_h3_authority_scope_mismatch',
            default => 'k3_unit02_h3_authority_unavailable',
        };
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
