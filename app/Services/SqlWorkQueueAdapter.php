<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Adapta una cola existente a un contrato de lectura común.
 * Todas las expresiones SQL proceden de definiciones internas; nunca de filtros web.
 */
final class SqlWorkQueueAdapter implements WorkQueueAdapter
{
    private bool $projectSucceeded = false;

    /** @param array<string,mixed> $definition */
    public function __construct(private readonly array $definition)
    {
    }

    public function key(): string
    {
        return (string) $this->definition['key'];
    }

    public function project(): array
    {
        $this->projectSucceeded = false;
        $table = (string) $this->definition['table'];
        if (!(new SchemaInspectorService())->hasTable($table)) {
            (new WorkQueueAdapterHealthService())->unavailable(
                $this->key(),
                'La tabla necesaria todavía no está instalada. Complete las migraciones pendientes.'
            );
            return [];
        }
        try {
            $rows = Database::connectionFresh()->query((string) $this->definition['sql'])->fetchAll(PDO::FETCH_ASSOC);
            (new WorkQueueAdapterHealthService())->success($this->key());
            $this->projectSucceeded = true;
            $companies = $this->companyMap($rows);
            return array_map(fn (array $row): array => $this->normalize($row, $companies), $rows);
        } catch (Throwable $error) {
            $diagnosticId = SafeErrorPresenter::reference();
            (new WorkQueueAdapterHealthService())->failure(
                $this->key(),
                'No se pudo comprobar esta cola. Revise su diagnóstico antes de asumir que está vacía.',
                $diagnosticId
            );
            Logger::write('warning', 'No fue posible comprobar una cola de automatización.', [
                'module' => 'automation',
                'queue_key' => $this->key(),
                'error_class' => $error::class,
                'diagnostic_id' => $diagnosticId,
            ]);
            return [];
        }
    }

    public function projectSucceeded(): bool
    {
        return $this->projectSucceeded;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row, array $companies = []): array
    {
        $status = strtolower((string) ($row['source_status'] ?? 'pending'));
        $display = match ($status) {
            'running', 'analyzing', 'discovering', 'details' => 'running',
            'complete', 'completed', 'success', 'empty', 'ignored' => 'completed',
            'error', 'failed', 'quarantined' => 'error',
            'paused', 'cancelled', 'canceled' => 'paused',
            'waiting_budget', 'paused_api' => 'waiting_budget',
            'retry', 'partial', 'awaiting_remote' => 'retry',
            default => 'pending',
        };
        $clock = new SystemDatabaseUtcClock();
        $next = $row['next_eligible_at'] ?? null;
        if ($display === 'pending' && $next && !$clock->isDue((string) $next)) {
            $display = 'scheduled';
        }
        $activityAt = $row['source_updated_at'] ?? $row['started_at_source'] ?? null;
        $activityTimestamp = $clock->timestamp(is_string($activityAt) ? $activityAt : null);
        if ($display === 'running' && ($activityTimestamp === null || $activityTimestamp < time() - 300)) {
            $display = 'retry';
            $row['wait_reason'] = 'La reserva anterior dejó de enviar señales. El trabajo puede recuperarse de forma segura.';
        }
        $total = max(0, (int) ($row['progress_total'] ?? $row['item_count'] ?? 0));
        $current = max(0, min($total > 0 ? $total : PHP_INT_MAX, (int) ($row['progress_current'] ?? 0)));

        return [
            'queue_key' => $this->key(),
            'source_table' => (string) $this->definition['table'],
            'source_id' => (string) ($row['source_id'] ?? '0'),
            'company_id' => !empty($row['company_id'])
                ? (int) $row['company_id']
                : (!empty($row['meli_account_id']) ? ($companies[(int) $row['meli_account_id']] ?? null) : null),
            'meli_account_id' => !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null,
            'account_name' => $row['account_name'] ?? null,
            'category' => (string) $this->definition['category'],
            'human_label' => (string) $this->definition['label'],
            'content_summary' => mb_substr((string) ($row['content_summary'] ?? $this->definition['label']), 0, 500),
            'source_status' => $status,
            'display_status' => $display,
            'priority_tier' => (int) $this->definition['tier'],
            'is_api_task' => !empty($this->definition['api']) ? 1 : 0,
            'item_count' => max(0, (int) ($row['item_count'] ?? $total)),
            'progress_current' => $current,
            'progress_total' => $total,
            'estimated_api_calls' => isset($row['estimated_api_calls']) ? max(0, (int) $row['estimated_api_calls']) : null,
            'estimated_seconds' => isset($row['estimated_seconds']) ? max(0, (int) $row['estimated_seconds']) : null,
            'created_at_source' => $row['created_at_source'] ?? null,
            'next_eligible_at' => $next,
            'started_at_source' => $row['started_at_source'] ?? null,
            'heartbeat_at' => $row['heartbeat_at'] ?? $row['source_updated_at'] ?? null,
            'lease_expires_at' => $row['lease_expires_at'] ?? $row['lock_expires_at'] ?? null,
            'finished_at_source' => $row['finished_at_source'] ?? null,
            'last_result' => $row['last_result'] ?? null,
            'wait_reason' => $display === 'waiting_budget' ? 'Esperando presupuesto de consultas' : ($row['wait_reason'] ?? null),
            'safe_error_message' => !empty($row['safe_error_message'])
                ? mb_substr(Logger::redactString((string) $row['safe_error_message']), 0, 500)
                : null,
            'diagnostic_id' => $row['diagnostic_id'] ?? null,
            'normalized_error_code' => !empty($row['normalized_error_code'])
                ? mb_substr((string) $row['normalized_error_code'], 0, 80)
                : null,
            'retry_policy' => $this->retryPolicy($display, $row),
            'remediation_key' => !empty($row['remediation_key'])
                ? mb_substr((string) $row['remediation_key'], 0, 80)
                : null,
            'reached_remote' => array_key_exists('reached_remote', $row) && $row['reached_remote'] !== null
                ? ((int) $row['reached_remote'] === 1 ? 1 : 0)
                : null,
            'next_retry_at' => $row['next_retry_at'] ?? null,
            'source_updated_at' => $row['source_updated_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function retryPolicy(string $display, array $row): string
    {
        $provided = (string) ($row['retry_policy'] ?? '');
        if (in_array($provided, ['automatic', 'scheduled', 'manual', 'terminal', 'unknown'], true)) {
            return $provided;
        }
        return match ($display) {
            'retry', 'waiting_budget' => 'automatic',
            'scheduled' => 'scheduled',
            'error', 'paused' => 'manual',
            'completed' => 'terminal',
            default => 'unknown',
        };
    }

    /** @param list<array<string,mixed>> $rows @return array<int,int> */
    private function companyMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['meli_account_id']) && empty($row['company_id'])) {
                $ids[(int) $row['meli_account_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connectionFresh()->prepare(
            'SELECT id,company_id FROM meli_accounts WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_keys($ids));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
            $result[(int) $account['id']] = (int) $account['company_id'];
        }
        return $result;
    }
}
