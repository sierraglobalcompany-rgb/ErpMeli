<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Convierte evidencia histórica incompleta en una decisión local auditable.
 *
 * Este servicio no cambia el trabajo fuente, no abre transporte y no ejecuta
 * colas. Únicamente fija la evidencia observada para que una acción posterior
 * pueda operar sobre un recurso exacto y cercado.
 */
final class HistoricalWorkReconciliationService
{
    /** @param array<string,mixed> $work @return array<string,mixed> */
    public function applyLatest(array $work, ?PDO $pdo = null): array
    {
        if (!(new SchemaInspectorService())->hasTable('system_work_historical_reconciliations')) {
            return $work;
        }
        $queueKey = trim((string) ($work['queue_key'] ?? ''));
        $sourceId = trim((string) ($work['source_id'] ?? $work['id'] ?? ''));
        $companyId = (int) ($work['company_id'] ?? 0);
        $accountId = (int) ($work['meli_account_id'] ?? 0);
        if ($queueKey === '' || $sourceId === '' || $companyId < 1 || $accountId < 1) {
            return $work;
        }
        $pdo ??= Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT observed_source_status,observed_generation,observed_source_updated_at,
                    normalized_error_code,failure_class,reached_remote,diagnostic_id,safe_message,
                    reconciliation_state,reconciled_at
             FROM system_work_historical_reconciliations
             WHERE company_id=? AND meli_account_id=? AND queue_key=? AND source_id=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $queueKey, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $work;
        }
        $status = (string) ($work['source_status'] ?? $work['status'] ?? '');
        if ($status !== '' && (string) $row['observed_source_status'] !== $status) {
            return $work;
        }
        $updated = trim((string) ($work['source_updated_at'] ?? $work['updated_at'] ?? ''));
        $observedUpdated = trim((string) ($row['observed_source_updated_at'] ?? ''));
        if ($updated !== '' && $observedUpdated !== '' && $updated !== $observedUpdated) {
            return $work;
        }

        foreach (['normalized_error_code', 'failure_class', 'diagnostic_id'] as $key) {
            if (trim((string) ($row[$key] ?? '')) !== '') {
                $work[$key] = $row[$key];
            }
        }
        if (trim((string) ($row['safe_message'] ?? '')) !== '') {
            $work['safe_error_message'] = (string) $row['safe_message'];
        }
        if ($row['reached_remote'] !== null) {
            $work['reached_remote'] = (int) $row['reached_remote'];
        }
        $work['historical_reconciliation'] = [
            'state' => (string) $row['reconciliation_state'],
            'reconciled_at' => $row['reconciled_at'],
        ];
        return $work;
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function reconcile(PDO $pdo, array $source, int $userId): array
    {
        if (!(new SchemaInspectorService())->hasTable('system_work_historical_reconciliations')) {
            throw new HttpException(409, 'Complete la actualización antes de diagnosticar registros históricos.');
        }
        $queueKey = trim((string) ($source['queue_key'] ?? ''));
        $sourceId = trim((string) ($source['source_id'] ?? $source['id'] ?? ''));
        $status = trim((string) ($source['source_status'] ?? $source['status'] ?? ''));
        $companyId = (int) ($source['company_id'] ?? 0);
        $accountId = (int) ($source['meli_account_id'] ?? 0);
        if ($queueKey === '' || $sourceId === '' || $status === '' || $companyId < 1 || $accountId < 1 || $userId < 1) {
            throw new HttpException(422, 'No se pudo identificar la evidencia histórica de este trabajo.');
        }

        $code = strtolower(trim((string) ($source['normalized_error_code'] ?? $source['last_error_code'] ?? '')));
        $failureClass = strtolower(trim((string) ($source['failure_class'] ?? '')));
        $message = mb_strtolower(trim((string) ($source['safe_error_message'] ?? $source['last_error_message'] ?? '')));
        $reachedRemote = array_key_exists('reached_remote', $source) && $source['reached_remote'] !== null
            ? (int) $source['reached_remote']
            : null;

        if ($reachedRemote === null && $this->provesLocalOnly($code, $failureClass, $message)) {
            $reachedRemote = 0;
            $code = $code !== '' ? $code : 'legacy_local_failure';
            $failureClass = $failureClass !== '' ? $failureClass : 'local';
        } elseif ($reachedRemote === null && ($code === 'http_404' || str_contains($message, 'http 404'))) {
            $reachedRemote = 1;
            $code = 'http_404';
            $failureClass = 'remote_absent';
        } elseif ($reachedRemote === null) {
            $code = 'remote_result_uncertain';
            $failureClass = 'remote_result_uncertain';
        }

        $state = $reachedRemote === 0
            ? 'ready_for_exact_retry'
            : ($code === 'http_404' ? 'expected_absence' : 'remote_result_uncertain');
        $diagnosticId = trim((string) ($source['diagnostic_id'] ?? $source['last_error_diagnostic_id'] ?? ''));
        if ($diagnosticId === '') {
            $diagnosticId = SafeErrorPresenter::reference();
        }
        $safeMessage = match ($state) {
            'ready_for_exact_retry' => 'La evidencia local confirma que Mercado Libre no fue consultado. Puede reintentarse únicamente este recurso.',
            'expected_absence' => 'La evidencia histórica confirma que Mercado Libre informó que el recurso ya no está disponible.',
            default => 'La evidencia histórica no permite confirmar el resultado remoto. El recurso permanece bloqueado para evitar un reintento ciego.',
        };
        $updatedAt = trim((string) ($source['source_updated_at'] ?? $source['updated_at'] ?? '')) ?: null;
        $generation = max(0, (int) ($source['source_generation'] ?? 0));
        $stmt = $pdo->prepare(
            'INSERT INTO system_work_historical_reconciliations
                (queue_key,source_id,company_id,meli_account_id,observed_source_status,
                 observed_generation,observed_source_updated_at,normalized_error_code,failure_class,
                 reached_remote,diagnostic_id,safe_message,reconciliation_state,reconciled_by,reconciled_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                company_id=VALUES(company_id),meli_account_id=VALUES(meli_account_id),
                observed_source_status=VALUES(observed_source_status),observed_generation=VALUES(observed_generation),
                observed_source_updated_at=VALUES(observed_source_updated_at),
                normalized_error_code=VALUES(normalized_error_code),failure_class=VALUES(failure_class),
                reached_remote=VALUES(reached_remote),diagnostic_id=VALUES(diagnostic_id),
                safe_message=VALUES(safe_message),reconciliation_state=VALUES(reconciliation_state),
                reconciled_by=VALUES(reconciled_by),reconciled_at=UTC_TIMESTAMP(3)'
        );
        $stmt->execute([
            $queueKey,
            $sourceId,
            $companyId,
            $accountId,
            $status,
            $generation,
            $updatedAt,
            $code,
            $failureClass,
            $reachedRemote,
            mb_substr($diagnosticId, 0, 80),
            $safeMessage,
            $state,
            $userId,
        ]);

        return $this->applyLatest($source, $pdo);
    }

    private function provesLocalOnly(string $code, string $failureClass, string $message): bool
    {
        foreach (['database', 'local', 'validation', 'claim', 'transaction', 'cron_deadline', 'deadline_reached'] as $term) {
            if (str_contains($code, $term) || str_contains($failureClass, $term)) {
                return true;
            }
        }
        foreach (['antes de iniciar', 'no fue consultado', 'transacción local', 'base de datos'] as $term) {
            if (str_contains($message, $term)) {
                return true;
            }
        }
        return false;
    }
}
