<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

final class HistoricalBacklogImporter
{
    public const MAX_SCANNED_PER_RUN = 200;
    private const ADMISSION_LOCK = 'erp_meli_queue_core_historical_admission';
    private const CERTIFIED_CAPABILITIES = ['order_exact'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueCoreRepository $repository,
        private readonly HistoricalBacklogSourceRegistry $registry,
        private readonly HistoricalAdmissionPolicy $admission = new HistoricalAdmissionPolicy(),
    ) {
    }

    /** @return array{source_key:string,company_id:int,meli_account_id:int,enabled:bool,high_water_id:int,cursor_id:int,generation:int,state:string} */
    public function enable(string $sourceKey, int $companyId, int $accountId, string $actor): array
    {
        $source = $this->registry->get($sourceKey);
        $this->assertCertified($source);
        $this->assertScope($companyId, $accountId);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                "INSERT IGNORE INTO queue_core_historical_checkpoints
                 (source_key,company_id,meli_account_id,enabled,state)
                 VALUES (?,?,?,0,'disabled')"
            );
            $insert->execute([$sourceKey, $companyId, $accountId]);
            $select = $this->pdo->prepare(
                'SELECT * FROM queue_core_historical_checkpoints
                 WHERE source_key=? AND company_id=? AND meli_account_id=? FOR UPDATE'
            );
            $select->execute([$sourceKey, $companyId, $accountId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new RuntimeException('Historical checkpoint could not be created.');
            }
            $highWater = max((int) $current['high_water_id'], $source->highWater($this->pdo, $companyId, $accountId));
            $cursor = min((int) $current['cursor_id'], $highWater);
            $state = $cursor >= $highWater ? 'complete' : 'ready';
            $update = $this->pdo->prepare(
                'UPDATE queue_core_historical_checkpoints
                 SET enabled=1,state=?,high_water_id=?,cursor_id=?,generation=generation+1,
                     enabled_by=?,enabled_at=COALESCE(enabled_at,UTC_TIMESTAMP(3)),
                     completed_at=IF(?="complete",UTC_TIMESTAMP(3),NULL),last_error_class=NULL
                 WHERE source_key=? AND company_id=? AND meli_account_id=? AND generation=?'
            );
            $update->execute([
                $state,
                $highWater,
                $cursor,
                mb_substr(trim($actor) !== '' ? trim($actor) : 'cli', 0, 96),
                $state,
                $sourceKey,
                $companyId,
                $accountId,
                (int) $current['generation'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Historical checkpoint generation changed.');
            }
            $this->pdo->commit();
            return $this->checkpoint($sourceKey, $companyId, $accountId);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{status:string,source_key:string,company_id:int,meli_account_id:int,scanned:int,created:int,duplicates:int,reviewed:int,cursor_id:int,high_water_id:int,capacity:int,outstanding:int} */
    public function run(string $sourceKey, int $companyId, int $accountId, int $createLimit = 50): array
    {
        $source = $this->registry->get($sourceKey);
        $this->assertCertified($source);
        $this->assertScope($companyId, $accountId);
        if (!$this->featureEnabled()) {
            return $this->result('feature_disabled', $sourceKey, $companyId, $accountId, 0, 0, 0, 0, 0, 0);
        }
        if (!$this->acquireAdmissionLock()) {
            return $this->result('busy', $sourceKey, $companyId, $accountId, 0, 0, 0, 0, 0, 0);
        }
        try {
            $this->pdo->beginTransaction();
            $checkpoint = $this->checkpointForUpdate($sourceKey, $companyId, $accountId);
            if ($checkpoint === null || (int) $checkpoint['enabled'] !== 1) {
                $this->pdo->rollBack();
                return $this->result('disabled', $sourceKey, $companyId, $accountId, 0, 0, 0, 0, 0, 0);
            }
            $available = $this->admission->available($this->pdo, $createLimit);
            $outstanding = $this->admission->outstanding($this->pdo);
            if ($available < 1) {
                $this->updateCheckpoint($checkpoint, 'ready', 0, 0, 0, 0, (int) $checkpoint['cursor_id']);
                $this->pdo->commit();
                $reason = $this->admission->blockReason($this->pdo);
                return $this->result($reason === null ? 'capacity_wait' : $reason, $sourceKey, $companyId, $accountId, 0, 0, 0, 0, (int) $checkpoint['cursor_id'], (int) $checkpoint['high_water_id'], $outstanding);
            }

            $rows = $source->scan(
                $this->pdo,
                $companyId,
                $accountId,
                (int) $checkpoint['cursor_id'],
                (int) $checkpoint['high_water_id'],
                self::MAX_SCANNED_PER_RUN,
            );
            $classifier = new LegacyWorkClassifier($companyId);
            $scanned = $created = $duplicates = $reviewed = 0;
            $cursor = (int) $checkpoint['cursor_id'];
            foreach ($rows as $row) {
                $classification = $source->classify($row, $classifier);
                $sourceId = (int) $classification['source_id'];
                if ($sourceId <= $cursor) {
                    throw new RuntimeException('Historical source violated ascending scan order.');
                }
                $job = $classification['job'];
                if ($job instanceof QueueJob && $created >= $available) {
                    break;
                }
                $scanned++;
                $cursor = $sourceId;
                if (!$job instanceof QueueJob) {
                    $this->review($sourceKey, $companyId, $accountId, $classification);
                    $reviewed++;
                    continue;
                }
                $jobId = $this->repository->enqueue($job);
                $wasCreated = $this->repository->lastEnqueueCreated();
                $this->receipt($sourceKey, $companyId, $accountId, $classification, $jobId, $wasCreated);
                if ($wasCreated) {
                    $created++;
                } else {
                    $duplicates++;
                }
            }
            if ($rows === []) {
                $cursor = (int) $checkpoint['high_water_id'];
            }
            $state = $cursor >= (int) $checkpoint['high_water_id'] ? 'complete' : 'ready';
            $this->updateCheckpoint($checkpoint, $state, $scanned, $created, $duplicates, $reviewed, $cursor);
            $this->pdo->commit();
            return $this->result($state, $sourceKey, $companyId, $accountId, $scanned, $created, $duplicates, $reviewed, $cursor, (int) $checkpoint['high_water_id'], $outstanding + $created);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $this->releaseAdmissionLock();
        }
    }

    private function featureEnabled(): bool
    {
        try {
            return (new QueueCoreFeatureFlagService($this->pdo))->enabled('historical_importer');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function checkpointForUpdate(string $sourceKey, int $companyId, int $accountId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM queue_core_historical_checkpoints
             WHERE source_key=? AND company_id=? AND meli_account_id=? FOR UPDATE'
        );
        $statement->execute([$sourceKey, $companyId, $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{source_key:string,company_id:int,meli_account_id:int,enabled:bool,high_water_id:int,cursor_id:int,generation:int,state:string} */
    private function checkpoint(string $sourceKey, int $companyId, int $accountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM queue_core_historical_checkpoints
             WHERE source_key=? AND company_id=? AND meli_account_id=?'
        );
        $statement->execute([$sourceKey, $companyId, $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Historical checkpoint is unavailable.');
        }
        return [
            'source_key' => (string) $row['source_key'],
            'company_id' => (int) $row['company_id'],
            'meli_account_id' => (int) $row['meli_account_id'],
            'enabled' => (int) $row['enabled'] === 1,
            'high_water_id' => (int) $row['high_water_id'],
            'cursor_id' => (int) $row['cursor_id'],
            'generation' => (int) $row['generation'],
            'state' => (string) $row['state'],
        ];
    }

    /** @param array<string,mixed> $checkpoint */
    private function updateCheckpoint(array $checkpoint, string $state, int $scanned, int $created, int $duplicates, int $reviewed, int $cursor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_historical_checkpoints
             SET state=?,cursor_id=?,generation=generation+1,last_scanned=?,last_created=?,
                 last_duplicates=?,last_reviewed=?,last_error_class=NULL,
                 completed_at=IF(?="complete",UTC_TIMESTAMP(3),NULL)
             WHERE source_key=? AND company_id=? AND meli_account_id=? AND generation=?'
        );
        $statement->execute([
            $state,
            $cursor,
            $scanned,
            $created,
            $duplicates,
            $reviewed,
            $state,
            (string) $checkpoint['source_key'],
            (int) $checkpoint['company_id'],
            (int) $checkpoint['meli_account_id'],
            (int) $checkpoint['generation'],
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Historical checkpoint fencing was lost.');
        }
    }

    /** @param array<string,mixed> $classification */
    private function review(string $sourceKey, int $companyId, int $accountId, array $classification): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_historical_reviews
             (source_key,company_id,meli_account_id,source_id,source_version,reason_code,source_state,evidence_sha256)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE evidence_sha256=VALUES(evidence_sha256),source_state=VALUES(source_state)'
        );
        $statement->execute([
            $sourceKey,
            $companyId,
            $accountId,
            (int) $classification['source_id'],
            (string) $classification['source_version'],
            (string) ($classification['reason'] ?? 'classification_failed'),
            (string) $classification['source_state'],
            (string) $classification['evidence_sha256'],
        ]);
    }

    /** @param array<string,mixed> $classification */
    private function receipt(string $sourceKey, int $companyId, int $accountId, array $classification, int $jobId, bool $created): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_historical_receipts
             (source_key,company_id,meli_account_id,source_id,source_generation,source_state,
              source_version,input_version,queue_job_id,admission_result,source_snapshot_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE queue_job_id=VALUES(queue_job_id)'
        );
        $statement->execute([
            $sourceKey,
            $companyId,
            $accountId,
            (int) $classification['source_id'],
            (int) $classification['source_generation'],
            (string) $classification['source_state'],
            (string) $classification['source_version'],
            (string) $classification['input_version'],
            $jobId,
            $created ? 'created' : 'duplicate',
            self::json((array) $classification['snapshot']),
        ]);
    }

    private function assertScope(int $companyId, int $accountId): void
    {
        if ($companyId < 1 || $accountId < 1) {
            throw new RuntimeException('Historical import scope is invalid.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1');
        $statement->execute([$accountId, $companyId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Historical import scope was not authorized.');
        }
    }

    private function assertCertified(HistoricalBacklogSource $source): void
    {
        if (!in_array($source->requiredCapability(), self::CERTIFIED_CAPABILITIES, true)) {
            throw new RuntimeException('Historical source capability is not certified.');
        }
    }

    private function acquireAdmissionLock(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([self::ADMISSION_LOCK]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseAdmissionLock(): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([self::ADMISSION_LOCK]);
        } catch (Throwable) {
        }
    }

    /** @return array{status:string,source_key:string,company_id:int,meli_account_id:int,scanned:int,created:int,duplicates:int,reviewed:int,cursor_id:int,high_water_id:int,capacity:int,outstanding:int} */
    private function result(string $status, string $sourceKey, int $companyId, int $accountId, int $scanned, int $created, int $duplicates, int $reviewed, int $cursor, int $highWater, ?int $outstanding = null): array
    {
        return [
            'status' => $status,
            'source_key' => $sourceKey,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'scanned' => $scanned,
            'created' => $created,
            'duplicates' => $duplicates,
            'reviewed' => $reviewed,
            'cursor_id' => $cursor,
            'high_water_id' => $highWater,
            'capacity' => $this->admission->effectiveCap(),
            'outstanding' => $outstanding ?? $this->admission->outstanding($this->pdo),
        ];
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
