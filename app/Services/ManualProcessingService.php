<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ManualProcessingService
{
    /** @return array<string,list<string>> */
    public function scopes(): array
    {
        $retiredWithoutExactConsumer = [
            'notification_backfill',
            'recurring_sync',
            'questions',
            'sales_fiscal',
            'module_jobs',
            'claims_search_page',
        ];
        $all = array_values(array_diff(
            array_keys((new WorkQueueRegistry())->definitionsByKey()),
            array_merge(['notification_spool'], $retiredWithoutExactConsumer)
        ));
        return [
            // Selección inicial: trabajo comercial útil y reparaciones seguras.
            // Cargas masivas de productos, descripciones y módulos requieren
            // una elección explícita del administrador.
            'recommended' => [
                'notification_fallback',
                'orders_sync',
                'order_enrichment',
                'sale_pack_reconciliation',
                'financial_recalc',
                'sale_financial_reconciliation',
                'sales_repair',
                'order_date_repair',
            ],
            // Entrada webhook y ventas recién notificadas permanecen siempre
            // reservadas para el worker urgente, incluso en "todo".
            'all' => $all,
            'sales' => ['notification_fallback','orders_sync','order_enrichment','sale_pack_reconciliation'],
            'finance' => ['financial_recalc','sale_financial_reconciliation'],
            'audits' => ['sales_audit','sales_repair','order_date_repair'],
            'products' => ['items_sync'],
            'descriptions' => ['catalog_descriptions'],
            'modules' => [],
            // La entrada temporal de webhooks y la programación recurrente son
            // reservas del cron: no deben quedar congeladas por el navegador.
            'local' => ['operational_maintenance','order_date_repair','financial_recalc'],
        ];
    }

    /** @return array<string,mixed> */
    public function preview(string $scopeKey, ?int $accountId = null): array
    {
        $scopeKey = isset($this->scopes()[$scopeKey]) ? $scopeKey : 'all';
        $wanted = array_flip($this->scopes()[$scopeKey]);
        $maximum = max(100, min(10000, (new AppSettingsService())->int('manual_processing.preview_max_jobs', 5000)));
        $page = (new WorkQueueProjectionService())->all([
            'active_only' => 1,
            'account_id' => $accountId ?: null,
            'queue_keys' => array_keys($wanted),
        ], $maximum);
        $rows = array_values(array_filter(
            $page['rows'],
            static fn (array $row): bool => isset($wanted[(string) ($row['queue_key'] ?? '')])
                && !in_array((string) ($row['display_status'] ?? ''), ['completed','cancelled'], true)
        ));
        $calls = 0;
        $seconds = 0;
        $units = 0;
        $heavy = 0;
        $unknownSizeJobs = 0;
        $accountIds = [];
        $localJobs = 0;
        $remoteJobs = 0;
        $heaviestLabel = 'Ninguna';
        $heaviestRank = -1;
        $loadRanks = [
            'local' => 0,
            'light' => 1,
            'medium' => 2,
            'paginated' => 3,
            'heavy' => 4,
            'cursor' => 5,
            'very_heavy' => 6,
        ];
        foreach ($rows as &$row) {
            $operation = $this->operationForQueue((string) $row['queue_key']);
            $profile = (new MeliOperationProfileRegistry())->get($operation);
            $row['operation_key'] = $operation;
            $row['load_class'] = $profile['load_class'];
            $row['load_label'] = $this->loadLabel((string) $profile['load_class']);
            $calls += max(0, (int) ($row['estimated_api_calls'] ?? 0));
            $seconds += max(1, (int) ($row['estimated_seconds'] ?? 1));
            $units += max(0, (int) $profile['workload_units']);
            if (in_array($profile['load_class'], ['heavy','very_heavy','cursor'], true)) {
                $heavy++;
            }
            if ((int) ($row['item_count'] ?? 0) < 1) {
                $unknownSizeJobs++;
            }
            if (!empty($row['meli_account_id'])) {
                $accountIds[(int) $row['meli_account_id']] = true;
            }
            if (!empty($profile['uses_api'])) {
                $remoteJobs++;
            } else {
                $localJobs++;
            }
            $rank = $loadRanks[(string) $profile['load_class']] ?? 0;
            if ($rank > $heaviestRank) {
                $heaviestRank = $rank;
                $heaviestLabel = (string) $profile['label'];
            }
        }
        unset($row);
        $observation = (new MeliOperationTelemetryService())->observationStatus();
        $telemetry = new MeliOperationTelemetryService();
        $registry = new MeliOperationProfileRegistry();
        $profileReadiness = ['ready' => true, 'verified' => [], 'pending' => []];
        $checkedProfiles = [];
        foreach ($rows as $row) {
            $operation = (string) ($row['operation_key'] ?? 'local_maintenance');
            if ($operation === 'local_maintenance') {
                continue;
            }
            $rowAccountId = !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null;
            $checkKey = $operation . ':' . ($rowAccountId ?? '*');
            if (isset($checkedProfiles[$checkKey])) {
                continue;
            }
            $checkedProfiles[$checkKey] = true;
            $readiness = $telemetry->profileReadiness([$operation], $rowAccountId);
            $profile = $registry->get($operation);
            $accountLabel = trim((string) ($row['account_name'] ?? ''));
            $label = (string) $profile['label'] . ($accountLabel !== '' ? ' · ' . $accountLabel : '');
            if (!empty($readiness['ready'])) {
                $profileReadiness['verified'][] = $label;
            } else {
                $profileReadiness['ready'] = false;
                $profileReadiness['pending'][] = $label;
            }
        }
        $localOnly = $checkedProfiles === [];
        $safeModeRequired = !$localOnly && empty($profileReadiness['ready']);
        return [
            'scope_key' => $scopeKey,
            'rows' => $rows,
            'jobs' => count($rows),
            'items' => array_sum(array_map(static fn (array $r): int => max(0, (int) ($r['item_count'] ?? 0)), $rows)),
            'estimated_calls' => $calls,
            'estimated_seconds' => $seconds,
            'workload_units' => $units,
            'delicate_jobs' => $heavy,
            'unknown_size_jobs' => $unknownSizeJobs,
            'source_total' => (int) $page['total'],
            'truncated' => (bool) $page['truncated'],
            'can_start' => count($rows) > 0
                && !(bool) $page['truncated'],
            'safe_mode_required' => $safeModeRequired,
            'observation' => $observation,
            'profile_readiness' => $profileReadiness,
            'accounts' => count($accountIds),
            'local_jobs' => $localJobs,
            'remote_jobs' => $remoteJobs,
            'heaviest_operation' => $heaviestLabel,
            'estimated_time_label' => $this->timeEstimateLabel($seconds),
            'contains_descriptions' => count(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['queue_key'] ?? '') === 'catalog_descriptions'
            )) > 0,
        ];
    }

    /** @return array<string,mixed> */
    public function start(
        int $userId,
        string $scopeKey,
        string $mode,
        ?int $accountId = null,
        bool $confirmDelicate = false
    ): array
    {
        if (!(new ExecutionCoordinationService())->available()) {
            throw new RuntimeException('Complete primero la migración 098.');
        }
        $coordination = new ExecutionCoordinationService();
        $coordination->releaseAbandoned();
        $existing = $coordination->activeSession();
        if ($existing !== null) {
            if ((int) $existing['created_by_user_id'] === $userId) {
                return $this->find((int) $existing['id']) ?? $existing;
            }
            throw new RuntimeException('Ya existe una sesión activa. Finalícela o espere a que devuelva el trabajo al cron.');
        }
        $preview = $this->preview($scopeKey, $accountId);
        if (empty($preview['can_start'])) {
            throw new RuntimeException(!empty($preview['truncated'])
                ? 'La selección supera el máximo seguro. Filtre la cuenta o el tipo de trabajo.'
                : (empty($preview['rows'])
                ? 'No hay trabajos pendientes en esta selección.'
                : 'Faltan mediciones seguras para una o más operaciones seleccionadas.'));
        }
        if (!empty($preview['contains_descriptions']) && !$confirmDelicate) {
            throw new RuntimeException(
                'Confirme que desea incluir descripciones. Es la operación de mayor carga y se procesará una por ciclo.'
            );
        }
        $engine = (new ManualProcessingEngineService())->status();
        if (empty($engine['ready'])) {
            throw new RuntimeException(
                'El motor manual todavía no está listo. Configure la tarea CLI y confirme su primera señal antes de comenzar.'
            );
        }
        $mode = in_array($mode, ['conservative','balanced','automatic','advanced'], true) ? $mode : 'automatic';
        if (!empty($preview['safe_mode_required']) && $mode !== 'conservative') {
            throw new RuntimeException(
                'Estas operaciones aún no tienen evidencia suficiente. Inícielas con el ritmo conservador.'
            );
        }
        $pdo = Database::connectionFresh();
        $lock = $pdo->query("SELECT GET_LOCK('erp_manual_processing_start',3)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new RuntimeException('Otra solicitud está preparando el procesador manual. Espere unos segundos e intente nuevamente.');
        }
        $pdo->beginTransaction();
        try {
            // Segunda comprobación dentro de la transacción: el índice por
            // estado serializa dos clics concurrentes antes de insertar.
            $activeStmt = $pdo->query(
                'SELECT * FROM manual_processing_sessions
                 WHERE status IN ("active","finishing")
                    OR (status="paused" AND grace_until>UTC_TIMESTAMP())
                 ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            $active = $activeStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($active)) {
                $pdo->rollBack();
                if ((int) $active['created_by_user_id'] === $userId) {
                    return $this->find((int) $active['id']) ?? $active;
                }
                throw new RuntimeException('Otra sesión ya está usando el procesador manual.');
            }
            $token = bin2hex(random_bytes(20));
            $grace = max(1, min(60, (new AppSettingsService())->int('manual_processing.browser_grace_minutes', 15)));
            $stmt = $pdo->prepare(
                'INSERT INTO manual_processing_sessions
                 (session_token,created_by_user_id,mode,scope_key,status,heartbeat_at,grace_until,started_at,total_jobs)
                 VALUES (?,?,?,?,"active",UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $grace . ' MINUTE),UTC_TIMESTAMP(),?)'
            );
            $stmt->execute([$token, $userId, $mode, $preview['scope_key'], count($preview['rows'])]);
            $sessionId = (int) $pdo->lastInsertId();
            $scopeStmt = $pdo->prepare(
                'INSERT INTO manual_processing_scopes
                 (manual_processing_session_id,queue_key,account_scope_key,meli_account_id)
                 VALUES (?,?,?,?)'
            );
            foreach ($this->scopes()[$preview['scope_key']] as $queueKey) {
                $scopeStmt->execute([$sessionId, $queueKey, $accountId ? (string) $accountId : '*', $accountId ?: null]);
            }
            $itemStmt = $pdo->prepare(
                'INSERT IGNORE INTO manual_processing_items
                 (manual_processing_session_id,queue_key,source_id,meli_account_id,human_label,content_summary,
                  operation_key,load_class,progress_current,progress_total)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($preview['rows'] as $row) {
                $itemStmt->execute([
                    $sessionId, $row['queue_key'], (string) $row['source_id'],
                    !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null,
                    (string) $row['human_label'], mb_substr((string) $row['content_summary'], 0, 500),
                    $row['operation_key'], $row['load_class'], max(0, (int) $row['progress_current']),
                    max(0, (int) $row['progress_total']),
                ]);
            }
            $this->event($pdo, $sessionId, 'started', 'Procesamiento manual iniciado. Cron cedió únicamente las colas seleccionadas.');
            $pdo->commit();
            return $this->find($sessionId) ?? ['id' => $sessionId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK('erp_manual_processing_start')");
            } catch (Throwable) {
                // El cierre de la conexión también libera el lock nombrado.
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1 || !(new ExecutionCoordinationService())->available()) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare('SELECT * FROM manual_processing_sessions WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            return null;
        }
        unset($session['session_token']);
        $visibleLimit = max(25, min(
            500,
            (new AppSettingsService())->int('manual_processing.visible_item_limit', 250)
        ));
        $items = Database::connectionFresh()->prepare(
            'SELECT * FROM manual_processing_items
             WHERE manual_processing_session_id=? ORDER BY id ASC LIMIT ' . ($visibleLimit + 1)
        );
        $items->execute([$id]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        $session['items_truncated'] = count($rows) > $visibleLimit;
        $session['visible_item_limit'] = $visibleLimit;
        $rows = array_slice($rows, 0, $visibleLimit);
        foreach ($rows as &$row) {
            unset($row['lease_owner']);
        }
        unset($row);
        $session['items'] = $rows;
        $session['current_item'] = null;
        $session['next_item'] = null;
        $session['waiting_jobs'] = 0;
        $session['retry_jobs'] = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'running' && $session['current_item'] === null) {
                $session['current_item'] = $row;
            }
            if (in_array($status, ['pending', 'retry', 'waiting'], true)
                && $session['next_item'] === null) {
                $session['next_item'] = $row;
            }
            if ($status === 'waiting') {
                $session['waiting_jobs']++;
            }
            if ($status === 'retry') {
                $session['retry_jobs']++;
            }
        }
        return $session;
    }

    public function pause(int $id, int $userId): bool
    {
        return $this->change($id, $userId, 'paused', false, 'Procesamiento pausado. La reserva se conservará durante 15 minutos.');
    }

    public function resume(int $id, int $userId): bool
    {
        return $this->change($id, $userId, 'active', true, 'Procesamiento reanudado.');
    }

    public function finish(int $id, int $userId): bool
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $sessionStmt = $pdo->prepare(
                'SELECT id FROM manual_processing_sessions
                 WHERE id=? AND created_by_user_id=? AND status IN ("active","paused")
                 LIMIT 1 FOR UPDATE'
            );
            $sessionStmt->execute([$id, $userId]);
            if (!$sessionStmt->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare(
                'UPDATE manual_processing_sessions SET status="finishing",
                 safe_message="Terminando el micro-lote actual. Después, el trabajo pendiente volverá al cron.",
                 updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND created_by_user_id=?'
            );
            $stmt->execute([$id, $userId]);
            $pdo->prepare(
                'UPDATE manual_processing_items SET status="returned",lease_owner=NULL,lease_expires_at=NULL
                 WHERE manual_processing_session_id=? AND status IN ("pending","waiting","retry")'
            )->execute([$id]);
            $this->event($pdo, $id, 'finishing', 'Se solicitó terminar después del micro-lote actual.');
            $this->finalizeIfDone($pdo, $id);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function claimNext(int $sessionId, string $owner): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $session = $pdo->prepare(
                'SELECT status FROM manual_processing_sessions WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $session->execute([$sessionId]);
            if ((string) $session->fetchColumn() !== 'active') {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE manual_processing_items
                 SET status="retry",lease_owner=NULL,lease_expires_at=NULL,
                     result_summary="La reserva anterior venció; el trabajo puede continuar.",updated_at=UTC_TIMESTAMP()
                 WHERE manual_processing_session_id=? AND status="running"
                   AND lease_expires_at IS NOT NULL AND lease_expires_at<UTC_TIMESTAMP()'
            )->execute([$sessionId]);
            $stmt = $pdo->prepare(
                'SELECT * FROM manual_processing_items
                 WHERE manual_processing_session_id=? AND status IN ("pending","retry","waiting")
                   AND (next_eligible_at IS NULL OR next_eligible_at<=UTC_TIMESTAMP())
                 ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$sessionId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $pdo->commit();
                return null;
            }
            $generation = (int) $item['lease_generation'] + 1;
            $pdo->prepare(
                'UPDATE manual_processing_items
                 SET status="running",lease_owner=?,lease_generation=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),
                     started_at=COALESCE(started_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([$owner, $generation, $item['id']]);
            $pdo->commit();
            $item['lease_owner'] = $owner;
            $item['lease_generation'] = $generation;
            return $item;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $result */
    public function completeItem(array $item, array $result): bool
    {
        $errors = max(0, (int) ($result['errors'] ?? 0));
        $deferred = in_array((string) ($result['status'] ?? ''), ['deferred','waiting'], true);
        $targetTerminal = !empty($result['target_terminal']);
        $targetErrored = !empty($result['target_errored']);
        $status = $targetErrored
            ? 'failed'
            : ($targetTerminal ? ($errors > 0 ? 'failed' : 'completed') : 'retry');
        $delay = max(5, min(3600, (int) ($result['delay_seconds'] ?? ($deferred || $errors > 0 ? 60 : 5))));
        $message = (string) ($result['message'] ?? '');
        if (!$targetTerminal && !$deferred && $errors === 0) {
            $message = 'La cola avanzó. Este trabajo conserva su turno y continuará en el siguiente micro-lote.';
        }

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE manual_processing_items
                 SET status=?,result_summary=?,diagnostic_id=?,
                     completed_at=IF(? IN ("completed","failed"),UTC_TIMESTAMP(),NULL),
                     next_eligible_at=IF(?="retry",DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),NULL),
                     lease_owner=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND lease_owner=? AND lease_generation=?'
            );
            $stmt->execute([
                $status,
                mb_substr($message !== '' ? $message : ($status === 'completed' ? 'Trabajo completado.' : 'Trabajo aplazado.'), 0, 500),
                $result['diagnostic_id'] ?? null,
                $status, $status, $delay,
                $item['id'], $item['lease_owner'], $item['lease_generation'],
            ]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare(
                'UPDATE manual_processing_sessions SET
                 completed_jobs=completed_jobs+?,failed_jobs=failed_jobs+?,
                 status=IF(?=1 AND status="active","paused",status),
                 paused_at=IF(?=1,UTC_TIMESTAMP(),paused_at),
                 safe_message=IF(?=1,"El procesamiento se detuvo para revisar el último resultado.",safe_message),
                 updated_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([
                $status === 'completed' ? 1 : 0,
                $status === 'failed' ? 1 : 0,
                $errors > 0 || $targetErrored ? 1 : 0,
                $errors > 0 || $targetErrored ? 1 : 0,
                $errors > 0 || $targetErrored ? 1 : 0,
                $item['manual_processing_session_id'],
            ]);
            $this->finalizeIfDone($pdo, (int) $item['manual_processing_session_id']);
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function workerHeartbeat(int $sessionId, string $result, string $message): void
    {
        if ($sessionId < 1) {
            return;
        }
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE manual_processing_sessions
             SET worker_heartbeat_at=UTC_TIMESTAMP(),last_worker_result=?,last_worker_message=?,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND status IN ("active","paused")'
        );
        $stmt->execute([
            mb_substr($result, 0, 40),
            mb_substr(Logger::redactString($message), 0, 500),
            $sessionId,
        ]);
    }

    public function finalizeSessionIfDone(int $sessionId): void
    {
        if ($sessionId < 1) {
            return;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->finalizeIfDone($pdo, $sessionId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function operationForQueue(string $queueKey): string
    {
        return match ($queueKey) {
            'notification_fallback' => 'order_exact',
            'orders_sync' => 'orders_search',
            'sales_audit' => 'sales_audit',
            'order_enrichment' => 'shipment_exact',
            'questions' => 'questions_search',
            // Puede pasar de cálculo local a importación de billing dentro del
            // mismo trabajo; se clasifica por la fase remota más exigente.
            'financial_recalc' => 'billing_orders',
            'sales_repair' => 'order_exact',
            'catalog_descriptions' => 'item_description',
            // El mismo trabajo incluye descubrimiento y detalles; clasificarlo
            // por la fase más pesada evita subestimar su carga.
            'items_sync' => 'item_detail',
            'module_jobs' => 'insights',
            'order_date_repair' => 'local_maintenance',
            default => 'local_maintenance',
        };
    }

    private function change(int $id, int $userId, string $status, bool $heartbeat, string $message): bool
    {
        $grace = max(1, min(60, (new AppSettingsService())->int('manual_processing.browser_grace_minutes', 15)));
        $sql = 'UPDATE manual_processing_sessions SET status=?,paused_at=' . ($status === 'paused' ? 'UTC_TIMESTAMP()' : 'NULL') . ',
                heartbeat_at=' . ($heartbeat ? 'UTC_TIMESTAMP()' : 'heartbeat_at') . ',
                grace_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $grace . ' MINUTE),
                safe_message=?,updated_at=UTC_TIMESTAMP()
                WHERE id=? AND created_by_user_id=? AND status IN ("active","paused")';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute([$status, $message, $id, $userId]);
        return $stmt->rowCount() === 1;
    }

    private function event(PDO $pdo, int $sessionId, string $type, string $message): void
    {
        $pdo->prepare(
            'INSERT INTO manual_processing_events (manual_processing_session_id,event_type,safe_message) VALUES (?,?,?)'
        )->execute([$sessionId, $type, mb_substr($message, 0, 500)]);
    }

    private function finalizeIfDone(PDO $pdo, int $sessionId): void
    {
        $remaining = $pdo->prepare(
            'SELECT COUNT(*) FROM manual_processing_items
             WHERE manual_processing_session_id=? AND status IN ("pending","running","waiting","retry")'
        );
        $remaining->execute([$sessionId]);
        if ((int) $remaining->fetchColumn() > 0) {
            return;
        }
        $failed = $pdo->prepare(
            'SELECT COUNT(*) FROM manual_processing_items
             WHERE manual_processing_session_id=? AND status="failed"'
        );
        $failed->execute([$sessionId]);
        $hasIssues = (int) $failed->fetchColumn() > 0;
        $session = $pdo->prepare(
            'SELECT status FROM manual_processing_sessions WHERE id=? LIMIT 1 FOR UPDATE'
        );
        $session->execute([$sessionId]);
        $currentStatus = (string) $session->fetchColumn();
        if (!in_array($currentStatus, ['active', 'paused', 'finishing'], true)) {
            return;
        }
        $finishing = $currentStatus === 'finishing';
        $finalStatus = $finishing
            ? 'abandoned'
            : ($hasIssues ? 'completed_with_issues' : 'completed');
        $finalMessage = $finishing
            ? 'El micro-lote terminó y el trabajo pendiente volvió al cron.'
            : ($hasIssues
                ? 'El procesamiento terminó con asuntos que requieren revisión.'
                : 'El procesamiento terminó y el trabajo quedó verificado.');
        $pdo->prepare(
            'UPDATE manual_processing_sessions
             SET status=?,finished_at=UTC_TIMESTAMP(),
                 safe_message=?,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND status IN ("active","paused","finishing")'
        )->execute([
            $finalStatus,
            $finalMessage,
            $sessionId,
        ]);
        $pdo->prepare(
            'UPDATE manual_processing_scopes SET active=0,updated_at=UTC_TIMESTAMP()
             WHERE manual_processing_session_id=?'
        )->execute([$sessionId]);
        $this->event(
            $pdo,
            $sessionId,
            $finalStatus,
            $finalMessage
        );
    }

    private function loadLabel(string $load): string
    {
        return [
            'local' => 'Sin API', 'light' => 'Ligera', 'medium' => 'Media',
            'paginated' => 'Paginada', 'cursor' => 'Cursor continuo',
            'heavy' => 'Pesada', 'very_heavy' => 'Muy pesada',
        ][$load] ?? 'Sin clasificar';
    }

    private function timeEstimateLabel(int $seconds): string
    {
        if ($seconds < 1) {
            return 'Menos de un ciclo';
        }
        $minimum = max(1, (int) ceil($seconds / 60));
        $maximum = max($minimum, (int) ceil(($seconds * 1.6) / 60));
        if ($maximum < 60) {
            return $minimum === $maximum
                ? $minimum . ' min aprox.'
                : $minimum . '–' . $maximum . ' min aprox.';
        }
        $fromHours = max(1, (int) floor($minimum / 60));
        $toHours = max($fromHours, (int) ceil($maximum / 60));
        return $fromHours === $toHours
            ? $fromHours . ' h aprox.'
            : $fromHours . '–' . $toHours . ' h aprox.';
    }
}
