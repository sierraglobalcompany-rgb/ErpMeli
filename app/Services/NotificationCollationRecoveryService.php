<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Recupera únicamente recursos cuyo diagnóstico confirma MySQL 1267 por mezcla
 * utf8mb4_general_ci/utf8mb4_unicode_ci. La lista se congela antes de reactivar
 * trabajos para no convertir una recuperación en un "reintentar todo".
 */
final class NotificationCollationRecoveryService
{
    private const SIGNATURE = 'mysql_1267_general_unicode';

    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('meli_notification_recovery_runs')
            && $schema->hasTable('meli_notification_recovery_run_items');
    }

    /** @return array<string,mixed> */
    /** @param list<int>|null $accountIds */
    public function createCanary(int $userId, ?array $accountIds = null): array
    {
        $this->assertEnabled();
        if ($accountIds !== null && $accountIds === []) {
            throw new \RuntimeException('No hay cuentas autorizadas para ejecutar la recuperación.');
        }
        $active = $this->activeRun($accountIds);
        if ($active !== null) {
            return $this->status((int) $active['id']);
        }

        $candidates = $this->confirmedCandidates($accountIds);
        if ($candidates === []) {
            return [
                'available' => true,
                'status' => 'no_candidates',
                'message' => 'No hay errores de collation 1267 confirmados para recuperar.',
                'candidate_count' => 0,
            ];
        }

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $accounts = [];
            foreach ($candidates as $candidate) {
                $accounts[(int) ($candidate['meli_account_id'] ?? 0)] = true;
            }
            $pdo->prepare(
                'INSERT INTO meli_notification_recovery_runs
                 (signature,mode,status,candidate_count,account_count,created_by,started_at,created_at,updated_at)
                 VALUES (?,"canary","canary_running",?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([self::SIGNATURE, count($candidates), count($accounts), $userId > 0 ? $userId : null]);
            $runId = (int) $pdo->lastInsertId();

            $selectedAccounts = [];
            $canaryIds = [];
            $insert = $pdo->prepare(
                'INSERT INTO meli_notification_recovery_run_items
                 (recovery_run_id,work_item_id,meli_account_id,original_diagnostic_id,is_canary,status,created_at,updated_at)
                 VALUES (?,?,?,?,?,? ,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            foreach ($candidates as $candidate) {
                $accountId = (int) ($candidate['meli_account_id'] ?? 0);
                $isCanary = !isset($selectedAccounts[$accountId]);
                if ($isCanary) {
                    $selectedAccounts[$accountId] = true;
                    $canaryIds[] = (int) $candidate['id'];
                }
                $insert->execute([
                    $runId,
                    (int) $candidate['id'],
                    $accountId > 0 ? $accountId : null,
                    (string) $candidate['last_error_diagnostic_id'],
                    $isCanary ? 1 : 0,
                    $isCanary ? 'queued' : 'frozen',
                ]);
            }
            $this->reactivate($pdo, $canaryIds);
            $pdo->prepare(
                'UPDATE meli_notification_recovery_runs
                 SET queued_count=?,safe_message=?,updated_at=UTC_TIMESTAMP() WHERE id=?'
            )->execute([
                count($canaryIds),
                'Canario iniciado: un recurso por cuenta afectada.',
                $runId,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->status($runId);
    }

    /** @return array<string,mixed> */
    /** @param list<int>|null $accountIds */
    public function startFull(?int $runId = null, ?array $accountIds = null): array
    {
        $this->assertEnabled();
        $run = $runId !== null ? $this->run($runId) : $this->latestRun($accountIds);
        if ($run === null) {
            throw new \RuntimeException('No existe una recuperación canaria para continuar.');
        }
        $this->assertRunAuthorized((int) $run['id'], $accountIds);
        $status = $this->refresh((int) $run['id']);
        if ((string) ($status['status'] ?? '') !== 'canary_passed' && (string) ($status['status'] ?? '') !== 'paused') {
            throw new \RuntimeException('El canario debe finalizar correctamente antes de recuperar el backlog.');
        }
        Database::connectionFresh()->prepare(
            'UPDATE meli_notification_recovery_runs
             SET mode="full",status="recovering",paused_at=NULL,safe_message="Recuperación gradual habilitada.",
                 updated_at=UTC_TIMESTAMP()
             WHERE id=?'
        )->execute([(int) $run['id']]);
        return $this->advance((int) $run['id']);
    }

    /** @return array<string,mixed> */
    /** @param list<int>|null $accountIds */
    public function pause(?int $runId = null, ?array $accountIds = null): array
    {
        $run = $runId !== null ? $this->run($runId) : $this->activeRun($accountIds);
        if ($run === null) {
            throw new \RuntimeException('No hay una recuperación activa para pausar.');
        }
        $this->assertRunAuthorized((int) $run['id'], $accountIds);
        Database::connectionFresh()->prepare(
            'UPDATE meli_notification_recovery_runs
             SET status="paused",paused_at=UTC_TIMESTAMP(),
                 safe_message="Pausada; el lote ya entregado puede terminar.",updated_at=UTC_TIMESTAMP()
             WHERE id=? AND status IN ("canary_running","recovering")'
        )->execute([(int) $run['id']]);
        return $this->status((int) $run['id']);
    }

    /**
     * Se invoca al inicio del worker. Solo libera un nuevo lote cuando el
     * anterior ya terminó y la corrida completa fue autorizada por un admin.
     *
     * @return array<string,mixed>
     */
    public function advanceActive(): array
    {
        if (!$this->available() || !$this->settings->bool('notifications.collation_recovery_enabled', true)) {
            return ['available' => false, 'status' => 'disabled'];
        }
        $run = $this->activeRun();
        if ($run === null) {
            return ['available' => true, 'status' => 'idle'];
        }
        return $this->advance((int) $run['id']);
    }

    /** @return array<string,mixed> */
    /** @param list<int>|null $accountIds */
    public function status(?int $runId = null, ?array $accountIds = null): array
    {
        if (!$this->available()) {
            return ['available' => false, 'status' => 'migration_pending'];
        }
        $run = $runId !== null ? $this->run($runId) : $this->latestRun($accountIds);
        if ($run === null) {
            return ['available' => true, 'status' => 'idle', 'candidate_count' => 0];
        }
        $this->assertRunAuthorized((int) $run['id'], $accountIds);
        return $this->refresh((int) $run['id']);
    }

    /** @return array<string,mixed> */
    private function advance(int $runId): array
    {
        $status = $this->refresh($runId);
        if ((string) ($status['status'] ?? '') !== 'recovering') {
            return $status;
        }
        if ((int) ($status['active_count'] ?? 0) > 0) {
            return $status;
        }

        $limit = max(1, min(100, $this->settings->int('notifications.collation_recovery_batch', 25)));
        $stmt = Database::connectionFresh()->prepare(
            'SELECT i.id,i.work_item_id,w.resource_type,w.meli_account_id,w.remote_resource_id,w.latest_event_sent_at
             FROM meli_notification_recovery_run_items i
             JOIN meli_notification_work_items w ON w.id=i.work_item_id
             WHERE i.recovery_run_id=? AND i.status="frozen"
             ORDER BY COALESCE(w.meli_account_id,0),w.first_received_at,w.id
             LIMIT ' . $limit
        );
        $stmt->execute([$runId]);
        $next = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($next === []) {
            Database::connectionFresh()->prepare(
                'UPDATE meli_notification_recovery_runs
                 SET status="complete",completed_at=UTC_TIMESTAMP(),
                     safe_message="Recuperación terminada.",updated_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([$runId]);
            return $this->refresh($runId);
        }

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $workIds = [];
            foreach ($next as $item) {
                if ($this->isSatisfiedLocally($pdo, $item)) {
                    $pdo->prepare(
                        'UPDATE meli_notification_recovery_run_items
                         SET status="satisfied_local",processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                         WHERE id=?'
                    )->execute([(int) $item['id']]);
                    $pdo->prepare(
                        'UPDATE meli_notification_work_items
                         SET status="complete",last_result="satisfied_local",last_success_at=UTC_TIMESTAMP(),
                             last_processed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),
                             consecutive_failures=0,last_error_code=NULL,last_error_message=NULL,
                             last_error_diagnostic_id=NULL,last_error_stage=NULL
                         WHERE id=?'
                    )->execute([(int) $item['work_item_id']]);
                    continue;
                }
                $workIds[] = (int) $item['work_item_id'];
                $pdo->prepare(
                    'UPDATE meli_notification_recovery_run_items
                     SET status="queued",updated_at=UTC_TIMESTAMP() WHERE id=?'
                )->execute([(int) $item['id']]);
            }
            $this->reactivate($pdo, $workIds);
            $pdo->prepare(
                'UPDATE meli_notification_recovery_runs
                 SET queued_count=queued_count+?,safe_message=?,updated_at=UTC_TIMESTAMP() WHERE id=?'
            )->execute([
                count($workIds),
                count($workIds) > 0
                    ? 'Nuevo lote liberado para el worker.'
                    : 'El lote ya estaba actualizado localmente.',
                $runId,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return $this->refresh($runId);
    }

    /** @return array<string,mixed> */
    private function refresh(int $runId): array
    {
        $run = $this->run($runId);
        if ($run === null) {
            return ['available' => true, 'status' => 'missing'];
        }
        $items = $this->items($runId);
        $pdo = Database::connectionFresh();
        $collationRepeated = false;
        foreach ($items as $item) {
            if (!in_array((string) $item['recovery_status'], ['queued', 'processing'], true)) {
                continue;
            }
            $workStatus = (string) ($item['work_status'] ?? '');
            if ($workStatus === 'complete' || !empty($item['last_success_at'])) {
                $pdo->prepare(
                    'UPDATE meli_notification_recovery_run_items
                     SET status="complete",processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?'
                )->execute([(int) $item['id']]);
                continue;
            }
            if (in_array($workStatus, ['pending', 'retry', 'running'], true)) {
                $pdo->prepare(
                    'UPDATE meli_notification_recovery_run_items SET status="processing",updated_at=UTC_TIMESTAMP() WHERE id=?'
                )->execute([(int) $item['id']]);
                continue;
            }
            if ($workStatus === 'error') {
                $repeated = $this->diagnosticMatches((string) ($item['last_error_diagnostic_id'] ?? ''));
                $collationRepeated = $collationRepeated || $repeated;
                $pdo->prepare(
                    'UPDATE meli_notification_recovery_run_items
                     SET status=?,safe_message=?,processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?'
                )->execute([
                    $repeated ? 'collation_error' : 'other_error',
                    $repeated
                        ? 'El error de collation 1267 reapareció.'
                        : 'El recurso terminó con una causa diferente; requiere revisión.',
                    (int) $item['id'],
                ]);
            }
        }

        $counts = $this->counts($runId);
        $newStatus = (string) $run['status'];
        $message = (string) ($run['safe_message'] ?? '');
        if ($collationRepeated) {
            $newStatus = 'blocked';
            $message = 'Recuperación detenida: reapareció MySQL 1267.';
        } elseif ($newStatus === 'canary_running' && $counts['active'] === 0) {
            $newStatus = $counts['failed'] > 0 ? 'canary_failed' : 'canary_passed';
            $message = $newStatus === 'canary_passed'
                ? (
                    $counts['frozen'] > 0
                        ? 'Prueba canaria aprobada. Quedan ' . $counts['frozen'] . ' recursos preparados para recuperar de forma gradual.'
                        : 'Prueba canaria aprobada. No quedan recursos congelados por recuperar.'
                )
                : 'La prueba canaria terminó con errores y no se liberó el backlog.';
        } elseif ($newStatus === 'recovering' && $counts['active'] === 0 && $counts['frozen'] === 0) {
            $newStatus = 'complete';
            $message = 'Recuperación terminada.';
        }
        $pdo->prepare(
            'UPDATE meli_notification_recovery_runs
             SET status=?,completed_count=?,failed_count=?,safe_message=?,
                 completed_at=IF(?="complete",UTC_TIMESTAMP(),completed_at),updated_at=UTC_TIMESTAMP()
             WHERE id=?'
        )->execute([
            $newStatus,
            $counts['complete'],
            $counts['failed'],
            $message,
            $newStatus,
            $runId,
        ]);

        return array_merge($this->run($runId) ?? [], [
            'available' => true,
            'active_count' => $counts['active'],
            'frozen_count' => $counts['frozen'],
            'pending_recovery_count' => $counts['frozen'],
            'canary_count' => $counts['canary'],
            'canary_complete_count' => $counts['canary_complete'],
            'canary_failed_count' => $counts['canary_failed'],
            'needs_full_recovery' => $newStatus === 'canary_passed' && $counts['frozen'] > 0,
            'complete_count' => $counts['complete'],
            'failed_count' => $counts['failed'],
            'progress_percent' => (int) ($run['candidate_count'] ?? 0) > 0
                ? (int) round((($counts['complete'] + $counts['failed']) / (int) $run['candidate_count']) * 100)
                : 0,
        ]);
    }

    /** @return list<array<string,mixed>> */
    /** @param list<int>|null $accountIds */
    private function confirmedCandidates(?array $accountIds = null): array
    {
        $scope = '';
        $params = [];
        if ($accountIds !== null) {
            $scope = ' AND w.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $params = $accountIds;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT w.id,w.meli_account_id,w.last_error_diagnostic_id,l.context_json
             FROM meli_notification_work_items w
             JOIN system_logs l ON l.context_json LIKE CONCAT("%",w.last_error_diagnostic_id,"%")
             WHERE w.status IN ("error","retry")
               AND w.last_error_diagnostic_id IS NOT NULL
               AND w.last_error_stage="processing"
               ' . $scope . '
             ORDER BY COALESCE(w.meli_account_id,0),w.first_received_at,w.id'
        );
        $stmt->execute($params);
        $matched = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (!isset($matched[$id]) && $this->contextMatches((string) $row['context_json'])) {
                $matched[$id] = $row;
            }
        }
        return array_values($matched);
    }

    private function diagnosticMatches(string $diagnosticId): bool
    {
        if ($diagnosticId === '') {
            return false;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT context_json FROM system_logs
             WHERE context_json LIKE CONCAT("%",?,"%") ORDER BY id DESC LIMIT 5'
        );
        $stmt->execute([$diagnosticId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $context) {
            if ($this->contextMatches((string) $context)) {
                return true;
            }
        }
        return false;
    }

    private function contextMatches(string $json): bool
    {
        $context = json_decode($json, true);
        if (!is_array($context) || (string) ($context['module'] ?? '') !== 'notifications') {
            return false;
        }
        $error = strtolower((string) ($context['error'] ?? ''));
        $driver = (string) ($context['driver_code'] ?? $context['mysql_code'] ?? '');
        return ($driver === '1267' || str_contains($error, '1267'))
            && str_contains($error, 'illegal mix of collations')
            && str_contains($error, 'utf8mb4_general_ci')
            && str_contains($error, 'utf8mb4_unicode_ci');
    }

    /** @param list<int> $ids */
    private function reactivate(PDO $pdo, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="pending",next_run_at=UTC_TIMESTAMP(),locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL,
                 consecutive_failures=0,processing_event_id=NULL,completed_at=NULL
             WHERE id IN (' . $placeholders . ') AND status IN ("error","retry","complete")'
        )->execute($ids);
        $sources = $pdo->prepare(
            'SELECT id,meli_account_id FROM meli_notification_work_items
             WHERE id IN (' . $placeholders . ') AND status="pending" ORDER BY id'
        );
        $sources->execute($ids);
        $work = new NotificationWorkItemService();
        foreach ($sources->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $receipt = $work->admitCanonicalWork(
                (int) $source['id'],
                (int) ($source['meli_account_id'] ?? 0),
                $pdo,
                'collation_recovery',
            );
            if (!$receipt['accepted']) {
                throw new \RuntimeException('notification_collation_recovery_admission_denied:' . $receipt['reason']);
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function isSatisfiedLocally(PDO $pdo, array $item): bool
    {
        if ((string) ($item['resource_type'] ?? '') !== 'order' || empty($item['latest_event_sent_at'])) {
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM meli_orders
             WHERE meli_account_id=? AND external_order_id=?
               AND last_updated_utc IS NOT NULL AND last_updated_utc>=?
             LIMIT 1'
        );
        $stmt->execute([
            (int) $item['meli_account_id'],
            (string) $item['remote_resource_id'],
            (string) $item['latest_event_sent_at'],
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    private function items(int $runId): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT i.*,i.status recovery_status,w.status work_status,w.last_success_at,
                    w.last_error_diagnostic_id,w.last_error_code
             FROM meli_notification_recovery_run_items i
             JOIN meli_notification_work_items w ON w.id=i.work_item_id
             WHERE i.recovery_run_id=? ORDER BY i.id'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{active:int,frozen:int,complete:int,failed:int,canary:int,canary_complete:int,canary_failed:int} */
    private function counts(int $runId): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT
               SUM(status IN ("queued","processing")) active_count,
               SUM(status="frozen") frozen_count,
               SUM(status IN ("complete","satisfied_local")) complete_count,
               SUM(status IN ("collation_error","other_error")) failed_count,
               SUM(is_canary=1) canary_count,
               SUM(is_canary=1 AND status IN ("complete","satisfied_local")) canary_complete_count,
               SUM(is_canary=1 AND status IN ("collation_error","other_error")) canary_failed_count
             FROM meli_notification_recovery_run_items WHERE recovery_run_id=?'
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'active' => (int) ($row['active_count'] ?? 0),
            'frozen' => (int) ($row['frozen_count'] ?? 0),
            'complete' => (int) ($row['complete_count'] ?? 0),
            'failed' => (int) ($row['failed_count'] ?? 0),
            'canary' => (int) ($row['canary_count'] ?? 0),
            'canary_complete' => (int) ($row['canary_complete_count'] ?? 0),
            'canary_failed' => (int) ($row['canary_failed_count'] ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    /** @param list<int>|null $accountIds */
    private function activeRun(?array $accountIds = null): ?array
    {
        if ($accountIds !== null) {
            return $this->findAuthorizedRun($accountIds, ['canary_running', 'recovering', 'paused']);
        }
        $row = Database::connectionFresh()->query(
            'SELECT * FROM meli_notification_recovery_runs
             WHERE status IN ("canary_running","recovering","paused")
             ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    /** @param list<int>|null $accountIds */
    private function latestRun(?array $accountIds = null): ?array
    {
        if ($accountIds !== null) {
            return $this->findAuthorizedRun($accountIds, null);
        }
        $row = Database::connectionFresh()->query(
            'SELECT * FROM meli_notification_recovery_runs ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function run(int $id): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM meli_notification_recovery_runs WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param list<int> $accountIds @param list<string>|null $statuses @return array<string,mixed>|null */
    private function findAuthorizedRun(array $accountIds, ?array $statuses): ?array
    {
        if ($accountIds === []) {
            return null;
        }
        $sql = 'SELECT * FROM meli_notification_recovery_runs';
        $params = [];
        if ($statuses !== null) {
            $sql .= ' WHERE status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $params = $statuses;
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $run) {
            if ($this->runIsAuthorized((int) $run['id'], $accountIds)) {
                return $run;
            }
        }
        return null;
    }

    /** @param list<int>|null $accountIds */
    private function assertRunAuthorized(int $runId, ?array $accountIds): void
    {
        if ($accountIds !== null && !$this->runIsAuthorized($runId, $accountIds)) {
            throw new \RuntimeException('No se encontró una recuperación dentro del alcance autorizado.');
        }
    }

    /** @param list<int> $accountIds */
    private function runIsAuthorized(int $runId, array $accountIds): bool
    {
        if ($accountIds === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) total_count,
                    SUM(meli_account_id IN (' . $placeholders . ')) authorized_count
             FROM meli_notification_recovery_run_items WHERE recovery_run_id=?'
        );
        $stmt->execute(array_merge($accountIds, [$runId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total_count'] ?? 0);
        return $total > 0 && (int) ($row['authorized_count'] ?? 0) === $total;
    }

    private function assertEnabled(): void
    {
        if (!$this->available()) {
            throw new \RuntimeException('La migración 076 de recuperación todavía no está aplicada.');
        }
        if (!$this->settings->bool('notifications.collation_recovery_enabled', true)) {
            throw new \RuntimeException('La recuperación de collation está desactivada.');
        }
    }
}
