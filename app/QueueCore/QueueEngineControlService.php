<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

/** Autoridad fail-closed para que V3 y V4 nunca consuman trabajo a la vez. */
final class QueueEngineControlService
{
    private const CONTROL_KEY = 'primary';
    private const ENGINES = ['disabled', 'v3', 'v4'];
    private const RUNTIME_LOCKS = [
        'v3:local',
        'v3:remote',
        'v4:operational',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{active_engine:string,generation:int,changed_at:string,changed_by:string} */
    public function snapshot(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT active_engine,generation,changed_at,changed_by
             FROM queue_engine_control WHERE control_key=? LIMIT 1'
        );
        $statement->execute([self::CONTROL_KEY]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !in_array((string) ($row['active_engine'] ?? ''), self::ENGINES, true)) {
            throw new RuntimeException('Queue Engine control authority is unavailable.');
        }
        return [
            'active_engine' => (string) $row['active_engine'],
            'generation' => max(0, (int) $row['generation']),
            'changed_at' => (string) ($row['changed_at'] ?? ''),
            'changed_by' => (string) ($row['changed_by'] ?? ''),
        ];
    }

    /**
     * @return array{ok:bool,reason:string,permit:?QueueEngineRuntimePermit,active_engine:string,generation:int}
     */
    public function acquireRuntime(string $engine, string $lane): array
    {
        $this->assertEngineAndLane($engine, $lane);
        $lockName = $this->runtimeLockName($engine, $lane);
        if ($this->lockIsHeld($lockName)) {
            $snapshot = $this->snapshot();
            return ['ok' => false, 'reason' => 'engine_runtime_busy', 'permit' => null] + $snapshot;
        }
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            $snapshot = $this->snapshot();
            return ['ok' => false, 'reason' => 'engine_runtime_busy', 'permit' => null] + $snapshot;
        }
        try {
            $snapshot = $this->snapshot();
            if ($snapshot['active_engine'] !== $engine) {
                $this->releaseLock($lockName);
                return ['ok' => false, 'reason' => 'engine_not_active', 'permit' => null] + $snapshot;
            }
            return [
                'ok' => true,
                'reason' => 'active',
                'permit' => new QueueEngineRuntimePermit($engine, $lane, $snapshot['generation'], $lockName),
            ] + $snapshot;
        } catch (Throwable $error) {
            $this->releaseLock($lockName);
            throw $error;
        }
    }

    public function stillCurrent(QueueEngineRuntimePermit $permit): bool
    {
        $snapshot = $this->snapshot();
        return $snapshot['active_engine'] === $permit->engine
            && $snapshot['generation'] === $permit->generation;
    }

    public function releaseRuntime(?QueueEngineRuntimePermit $permit): void
    {
        if ($permit !== null) {
            $this->releaseLock($permit->lockName);
        }
    }

    /**
     * @return array{ok:bool,reason:string,active_engine:string,generation:int}
     */
    public function compareAndSwap(string $desiredEngine, int $expectedGeneration, string $actor): array
    {
        if (!in_array($desiredEngine, self::ENGINES, true) || $expectedGeneration < 0) {
            throw new RuntimeException('Queue Engine cutover request is invalid.');
        }
        if ($desiredEngine === 'v4') {
            $readiness = (new QueueCoreReadinessReceiptService($this->pdo))->canActivateV4($expectedGeneration);
            if (!$readiness['ok']) {
                return ['ok' => false, 'reason' => $readiness['reason']] + $this->snapshot();
            }
        }
        $locks = [];
        try {
            foreach (self::RUNTIME_LOCKS as $runtime) {
                [$engine, $lane] = explode(':', $runtime, 2);
                $name = $this->runtimeLockName($engine, $lane);
                // GET_LOCK is re-entrant for the owning connection on MySQL
                // and MariaDB. IS_USED_LOCK therefore must fence even our own
                // connection before a cutover can advance the generation.
                if ($this->lockIsHeld($name)) {
                    $snapshot = $this->snapshot();
                    return ['ok' => false, 'reason' => 'engine_runtime_busy'] + $snapshot;
                }
                $statement = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
                $statement->execute([$name]);
                if ((int) $statement->fetchColumn() !== 1) {
                    $snapshot = $this->snapshot();
                    return ['ok' => false, 'reason' => 'engine_runtime_busy'] + $snapshot;
                }
                $locks[] = $name;
            }

            $this->pdo->beginTransaction();
            $select = $this->pdo->prepare(
                'SELECT active_engine,generation FROM queue_engine_control
                 WHERE control_key=? FOR UPDATE'
            );
            $select->execute([self::CONTROL_KEY]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || (int) $current['generation'] !== $expectedGeneration) {
                $this->pdo->rollBack();
                $snapshot = $this->snapshot();
                return ['ok' => false, 'reason' => 'stale_generation'] + $snapshot;
            }
            $update = $this->pdo->prepare(
                'UPDATE queue_engine_control
                 SET active_engine=?,generation=generation+1,changed_by=?,changed_at=UTC_TIMESTAMP(3)
                 WHERE control_key=? AND generation=?'
            );
            $update->execute([
                $desiredEngine,
                mb_substr(trim($actor) !== '' ? trim($actor) : 'cli', 0, 96),
                self::CONTROL_KEY,
                $expectedGeneration,
            ]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                $snapshot = $this->snapshot();
                return ['ok' => false, 'reason' => 'stale_generation'] + $snapshot;
            }
            $this->pdo->commit();
            return ['ok' => true, 'reason' => 'changed'] + $this->snapshot();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            foreach (array_reverse($locks) as $name) {
                $this->releaseLock($name);
            }
        }
    }

    private function assertEngineAndLane(string $engine, string $lane): void
    {
        $valid = ($engine === 'v3' && in_array($lane, ['local', 'remote'], true))
            || ($engine === 'v4' && $lane === 'operational');
        if (!$valid) {
            throw new RuntimeException('Queue Engine runtime identity is invalid.');
        }
    }

    private function runtimeLockName(string $engine, string $lane): string
    {
        return 'erp_meli_queue_engine_' . $engine . '_' . $lane;
    }

    private function releaseLock(string $lockName): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (Throwable) {
        }
    }

    private function lockIsHeld(string $lockName): bool
    {
        $statement = $this->pdo->prepare('SELECT IS_USED_LOCK(?)');
        $statement->execute([$lockName]);
        return $statement->fetchColumn() !== null;
    }
}
