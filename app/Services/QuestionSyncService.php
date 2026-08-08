<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class QuestionSyncService
{
    public function enabled(): bool
    {
        return (new AppSettingsService())->bool('questions.sync_enabled', false);
    }

    public function syncAllActive(): array
    {
        if (!$this->enabled()) {
            return ['accounts' => 0, 'questions' => 0, 'skipped' => true];
        }
        $settings = new AppSettingsService();
        $frequency = max(5, min(240, $settings->int('questions.frequency_minutes', 30)));
        $stmt = Database::connection()->prepare(
            'SELECT a.id
             FROM meli_accounts a
             LEFT JOIN question_sync_account_state s ON s.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected")
               AND (s.next_sync_at IS NULL OR s.next_sync_at<=UTC_TIMESTAMP())
             ORDER BY COALESCE(s.next_sync_at,"1970-01-01 00:00:00"),a.id'
        );
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $summary = ['accounts' => 0, 'questions' => 0, 'skipped' => false];
        foreach ($accounts as $accountId) {
            try {
                $summary['questions'] += $this->syncAccount((int) $accountId);
                $summary['accounts']++;
                $this->recordAccountAttempt((int) $accountId, $frequency, null);
            } catch (Throwable $e) {
                $this->recordAccountAttempt((int) $accountId, $frequency, $e);
                Logger::write('warning', 'No se pudieron sincronizar preguntas.', ['account_id' => (int) $accountId, 'error' => $e->getMessage()]);
            }
        }
        return $summary;
    }

    /** @return array{accounts:int,questions:int,skipped:bool,account_id?:int} */
    public function syncNextActive(): array
    {
        if (!$this->enabled()) {
            return ['accounts' => 0, 'questions' => 0, 'skipped' => true];
        }
        $settings = new AppSettingsService();
        $frequency = max(5, min(240, $settings->int('questions.frequency_minutes', 30)));
        $cursor = max(0, $settings->int('questions.cron_account_cursor', 0));
        $stmt = Database::connection()->prepare(
            'SELECT a.id FROM meli_accounts a
             LEFT JOIN question_sync_account_state s ON s.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected") AND a.id>:cursor
               AND (s.next_sync_at IS NULL OR s.next_sync_at<=UTC_TIMESTAMP())
             ORDER BY a.id LIMIT 1'
        );
        $stmt->execute(['cursor' => $cursor]);
        $accountId = (int) ($stmt->fetchColumn() ?: 0);
        if ($accountId <= 0) {
            $accountId = (int) (Database::connection()->query(
                'SELECT a.id FROM meli_accounts a
                 LEFT JOIN question_sync_account_state s ON s.meli_account_id=a.id
                 WHERE a.status IN ("conectado","connected")
                   AND (s.next_sync_at IS NULL OR s.next_sync_at<=UTC_TIMESTAMP())
                 ORDER BY a.id LIMIT 1'
            )->fetchColumn() ?: 0);
        }
        if ($accountId <= 0) {
            return ['accounts' => 0, 'questions' => 0, 'skipped' => true];
        }
        try {
            $count = $this->syncAccount($accountId);
            $this->recordAccountAttempt($accountId, $frequency, null);
        } catch (Throwable $error) {
            $this->recordAccountAttempt($accountId, $frequency, $error);
            throw $error;
        }
        $settings->set('questions.cron_account_cursor', (string) $accountId, 'questions');
        return ['accounts' => 1, 'questions' => $count, 'skipped' => false, 'account_id' => $accountId];
    }

    public function syncAccount(int $accountId): int
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('questions.endpoint_confirmed', false)) {
            Logger::write('info', 'Sincronizacion de preguntas omitida: endpoint pendiente de confirmar.', ['account_id' => $accountId]);
            return 0;
        }
        $limit = max(1, min(100, $settings->int('questions.page_limit', 50)));
        $sellerId = $this->sellerId($accountId);
        $params = [
            'seller_id' => $sellerId,
            'status' => 'UNANSWERED',
            'limit' => $limit,
            'api_version' => max(1, $settings->int('questions.api_version', 4)),
        ];
        try {
            $response = (new MeliApiClient($accountId))->get('/questions/search', $params, ['job_type' => 'questions', 'bulk' => true]);
        } catch (MeliApiException $e) {
            if (in_array($e->httpStatus, [400, 403], true)) {
                Logger::write('warning', 'La cuenta no pudo consultar preguntas; el endpoint documentado permanece habilitado para las demás cuentas.', [
                    'account_id' => $accountId,
                    'http_status' => $e->httpStatus,
                    'params' => Logger::redact($params),
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
        $rows = is_array($response['questions'] ?? null) ? $response['questions'] : (is_array($response['results'] ?? null) ? $response['results'] : []);
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->persist($accountId, $row);
                $count++;
            }
        }
        return $count;
    }

    public function syncQuestionById(int $accountId, int|string $externalQuestionId): int
    {
        $id = trim((string) $externalQuestionId);
        if ($accountId <= 0 || $id === '' || preg_match('/^[0-9]+$/', $id) !== 1) {
            throw new \InvalidArgumentException('Pregunta o cuenta inválida.');
        }
        $row = (new MeliApiClient($accountId))->get(
            '/questions/' . rawurlencode($id),
            [],
            ['job_type' => 'questions', 'source' => 'webhook_worker', 'bulk' => false]
        );
        $this->persist($accountId, $row);
        $stmt = Database::connection()->prepare(
            'SELECT id FROM meli_questions WHERE meli_account_id=? AND external_question_id=? LIMIT 1'
        );
        $stmt->execute([$accountId, $id]);
        return (int) $stmt->fetchColumn();
    }

    public function pending(array $filters = []): array
    {
        $where = ['q.status NOT IN ("ANSWERED","answered","closed")'];
        $params = [];
        $companyId = max(0, (int) ($filters['company_id'] ?? 0));
        $scope = new BusinessScopeContext();
        if (!empty($filters['account_id'])) {
            $account = $scope->account((int) $filters['account_id'], $companyId);
            $where[] = 'q.meli_account_id=?';
            $params[] = (int) $account['id'];
        } else {
            $authorized = $scope->accountPredicate('q.meli_account_id', null, $companyId);
            $where[] = $authorized['sql'];
            $params = array_merge($params, $authorized['params']);
        }
        $stmt = Database::connection()->prepare(
            'SELECT q.*,a.account_name,a.company_id
             FROM meli_questions q
             JOIN meli_accounts a ON a.id=q.meli_account_id
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY q.asked_at DESC, q.created_at DESC LIMIT 300'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingCount(int $accountId = 0, int $companyId = 0): int
    {
        try {
            $where = ['q.status NOT IN ("ANSWERED","answered","closed")'];
            $params = [];
            if ($accountId > 0) {
                $account = (new BusinessScopeContext())->account($accountId, $companyId);
                $where[] = 'q.meli_account_id=?';
                $params[] = (int) $account['id'];
            } else {
                $scope = (new BusinessScopeContext())->accountPredicate('q.meli_account_id', null, $companyId);
                $where[] = $scope['sql'];
                $params = array_merge($params, $scope['params']);
            }
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM meli_questions q WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function persist(int $accountId, array $row): void
    {
        $externalId = (int) ($row['id'] ?? $row['question_id'] ?? 0);
        if ($externalId <= 0) {
            return;
        }
        $askedAt = $this->dateValue($row['date_created'] ?? $row['asked_at'] ?? null);
        $answeredAt = $this->dateValue($row['answer']['date_created'] ?? $row['answered_at'] ?? null);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO meli_questions
             (meli_account_id,external_question_id,external_item_id,seller_id,buyer_id,status,text,answer_text,asked_at,answered_at,raw_json,synced_at)
             VALUES (:account,:external,:item,:seller,:buyer,:status,:text,:answer,:asked,:answered,:raw,NOW())
             ON DUPLICATE KEY UPDATE external_item_id=VALUES(external_item_id),seller_id=VALUES(seller_id),buyer_id=VALUES(buyer_id),status=VALUES(status),text=VALUES(text),answer_text=VALUES(answer_text),asked_at=VALUES(asked_at),answered_at=VALUES(answered_at),raw_json=VALUES(raw_json),synced_at=NOW(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $accountId,
            'external' => $externalId,
            'item' => $row['item_id'] ?? null,
            'seller' => $row['seller_id'] ?? null,
            'buyer' => $row['from']['id'] ?? $row['buyer_id'] ?? null,
            'status' => $row['status'] ?? null,
            'text' => $row['text'] ?? null,
            'answer' => $row['answer']['text'] ?? null,
            'asked' => $askedAt,
            'answered' => $answeredAt,
            'raw' => json_encode(Logger::redact($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $questionId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO question_notifications (meli_question_id,channel,status) VALUES (:id,"internal","sent")')->execute(['id' => $questionId]);
        $this->notifyByEmail($questionId, $accountId);
    }

    private function notifyByEmail(int $questionId, int $accountId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT q.*,a.account_name FROM meli_questions q JOIN meli_accounts a ON a.id=q.meli_account_id WHERE q.id=:id');
        $stmt->execute(['id' => $questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            return;
        }
        $email = new EmailNotificationService();
        if (!$email->enabled()) {
            $pdo->prepare('INSERT IGNORE INTO question_notifications (meli_question_id,channel,status) VALUES (:id,"email","skipped")')->execute(['id' => $questionId]);
            return;
        }
        $sent = $email->sendQuestionAlert($question);
        $pdo->prepare(
            'INSERT INTO question_notifications (meli_question_id,channel,status,sent_at,error_message)
             VALUES (:id,"email",:status,IF(:sent=1,NOW(),NULL),:error)
             ON DUPLICATE KEY UPDATE status=VALUES(status),sent_at=VALUES(sent_at),error_message=VALUES(error_message)'
        )->execute(['id' => $questionId, 'status' => $sent ? 'sent' : 'error', 'sent' => $sent ? 1 : 0, 'error' => $sent ? null : 'mail() no pudo enviar el mensaje.']);
    }

    private function sellerId(int $accountId): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id');
        $stmt->execute(['id' => $accountId]);
        return (int) $stmt->fetchColumn();
    }

    private function recordAccountAttempt(int $accountId, int $frequencyMinutes, ?Throwable $error): void
    {
        $safeError = $error === null
            ? null
            : mb_substr(Logger::redactString($error->getMessage()), 0, 500);
        Database::connection()->prepare(
            'INSERT INTO question_sync_account_state
             (meli_account_id,last_attempt_at,last_success_at,next_sync_at,last_error_message,consecutive_failures,updated_at)
             VALUES (:account,UTC_TIMESTAMP(),IF(:ok=1,UTC_TIMESTAMP(),NULL),
                     DATE_ADD(UTC_TIMESTAMP(),INTERVAL :frequency MINUTE),:error,IF(:ok=1,0,1),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               last_attempt_at=UTC_TIMESTAMP(),
               last_success_at=IF(:ok_update=1,UTC_TIMESTAMP(),last_success_at),
               next_sync_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL :frequency_update MINUTE),
               last_error_message=:error_update,
               consecutive_failures=IF(:ok_update2=1,0,consecutive_failures+1),
               updated_at=UTC_TIMESTAMP()'
        )->execute([
            'account' => $accountId,
            'ok' => $error === null ? 1 : 0,
            'frequency' => $frequencyMinutes,
            'error' => $safeError,
            'ok_update' => $error === null ? 1 : 0,
            'frequency_update' => $frequencyMinutes,
            'error_update' => $safeError,
            'ok_update2' => $error === null ? 1 : 0,
        ]);
    }

    private function dateValue(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
