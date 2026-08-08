<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class CronV3Enqueuer
{
    private ?bool $hasArrivalSeq = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CronV3WorkTypeRegistry $types,
    ) {
    }

    /** @return array{id:int,created:bool,status:string} */
    public function enqueue(WorkEnvelope $work, ?string $availableAt = null): array
    {
        return $this->enqueueWithStatus($work, 'ready', null, $availableAt);
    }

    /** @return array{id:int,created:bool,status:string} */
    public function enqueueWithStatus(
        WorkEnvelope $work,
        string $status,
        ?string $reasonCode = null,
        ?string $availableAt = null
    ): array
    {
        if (!$this->types->admits($work->workType, $work->lane)) {
            throw new RuntimeException('Cron V3 work_type/lane is not registered.');
        }
        if (!in_array($status, [
            'ready', 'waiting_capability', 'waiting_identity', 'deferred',
            'waiting_rate', 'waiting_budget', 'waiting_api', 'review', 'dead',
        ], true)) {
            throw new RuntimeException('Invalid Cron V3 enqueue status.');
        }
        $account = $this->pdo->prepare(
            'SELECT id FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
        );
        $account->execute([$work->meliAccountId, $work->companyId]);
        if ($account->fetchColumn() === false) {
            throw new RuntimeException('Cron V3 account does not belong to the requested company.');
        }
        $availableAt = $availableAt ?? gmdate('Y-m-d H:i:s');
        if (DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $availableAt) === false) {
            throw new RuntimeException('Invalid Cron V3 available_at.');
        }

        try {
            $payload = json_encode($work->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('Cron V3 payload is not valid JSON.', 0, $error);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO cron_v3_work
             (company_id,meli_account_id,work_type,work_family,lane,dedupe_key,input_version,source_ref,
              payload_json,status,priority,available_at,last_error_code,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $statement->execute([
            $work->companyId,
            $work->meliAccountId,
            $work->workType,
            $this->types->familyFor($work->workType),
            $work->lane,
            $work->dedupeKey,
            $work->inputVersion,
            $work->sourceRef,
            $payload,
            $status,
            $work->priority,
            $availableAt,
            $reasonCode,
        ]);

        $created = $statement->rowCount() === 1;
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            $lookup = $this->pdo->prepare(
                'SELECT id FROM cron_v3_work
                 WHERE company_id=? AND meli_account_id=? AND work_type=?
                   AND dedupe_key=? AND input_version=? LIMIT 1'
            );
            $lookup->execute([
                $work->companyId,
                $work->meliAccountId,
                $work->workType,
                $work->dedupeKey,
                $work->inputVersion,
            ]);
            $id = (int) $lookup->fetchColumn();
        }
        if ($id < 1) {
            throw new RuntimeException('Cron V3 enqueue did not produce a work id.');
        }
        $this->ensureArrivalSeq($id);

        if ($created && in_array($status, ['waiting_capability', 'waiting_identity', 'review'], true)) {
            $this->recordParkingEvent($work, $id, $status, $reasonCode ?? $status);
        }

        return ['id' => $id, 'created' => $created, 'status' => $status];
    }

    private function ensureArrivalSeq(int $id): void
    {
        if (!$this->hasArrivalSeqColumn()) {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE cron_v3_work
             SET arrival_seq=COALESCE(arrival_seq,id)
             WHERE id=? AND arrival_seq IS NULL'
        );
        $statement->execute([$id]);
    }

    private function hasArrivalSeqColumn(): bool
    {
        if ($this->hasArrivalSeq !== null) {
            return $this->hasArrivalSeq;
        }
        try {
            $statement = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name='cron_v3_work'
                   AND column_name='arrival_seq'"
            );
            $this->hasArrivalSeq = (int) $statement->fetchColumn() === 1;
        } catch (\Throwable) {
            $this->hasArrivalSeq = false;
        }

        return $this->hasArrivalSeq;
    }

    private function recordParkingEvent(WorkEnvelope $work, int $workId, string $status, string $reasonCode): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO cron_v3_parked_work_events
                 (work_id,company_id,meli_account_id,work_type,source_ref,parking_state,reason_code,safe_message)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                $workId,
                $work->companyId,
                $work->meliAccountId,
                $work->workType,
                $work->sourceRef,
                $status,
                substr(preg_replace('/[^a-z0-9_.-]/i', '_', $reasonCode) ?: $status, 0, 100),
                match ($status) {
                    'waiting_identity' => 'El recurso quedó parqueado porque falta identidad segura para ejecutar el trabajo exacto.',
                    'waiting_capability' => 'El recurso quedó parqueado porque falta capacidad V3 confirmada para su tipo exacto.',
                    default => 'El recurso quedó parqueado para revisión segura.',
                },
            ]);
        } catch (\Throwable) {
            // La cola principal ya conserva el estado parqueado; el evento es
            // diagnóstico auxiliar y no debe romper un enqueue idempotente.
        }
    }
}
