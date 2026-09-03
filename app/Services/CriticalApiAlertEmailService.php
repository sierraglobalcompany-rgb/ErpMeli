<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CriticalApiAlertEmailService
{
    /** @var null|callable(string,string,string,string):bool */
    private $mailTransport;

    /** @param null|callable(string,string,string,string):bool $mailTransport */
    public function __construct(?callable $mailTransport = null)
    {
        $this->mailTransport = $mailTransport;
    }

    /** @param array<string,mixed> $context @return array{attempted:bool,sent:bool,status:string,fingerprint:string} */
    public function notifyApiIncident(array $context): array
    {
        $status = (int) ($context['http_status'] ?? 0);
        if (!$this->shouldNotifyStatus($status)) {
            return $this->result(false, false, 'not_configured_for_status', $this->fingerprint($context));
        }

        $settings = new AppSettingsService();
        $to = trim((string) $settings->get('alerts.email.to', ''));
        if (!$settings->bool('alerts.email.enabled', false) || $to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result(false, false, 'disabled', $this->fingerprint($context));
        }

        $fingerprint = $this->fingerprint($context);
        $scopeKey = $this->scopeKey($context);
        $incidentKey = $this->incidentKey($context);
        $cooldownMinutes = max(5, min(1440, $settings->int('alerts.email.cooldown_minutes', 60)));
        $now = gmdate('Y-m-d H:i:s');

        try {
            $pdo = Database::connectionFresh();
            $this->upsertLedger($pdo, $fingerprint, $incidentKey, $scopeKey, $now);
            $leaseUntil = gmdate('Y-m-d H:i:s', time() + 300);
            if (!$this->claimSendLease($pdo, $fingerprint, $now, $leaseUntil)) {
                return $this->result(false, false, 'claimed_or_cooldown', $fingerprint);
            }

            [$subject, $body] = $this->message($context);
            $headers = 'From: ' . (Env::get('MAIL_FROM', 'no-reply@localhost') ?: 'no-reply@localhost');
            $sent = $this->send($to, $subject, $body, $headers);
            $nextAttemptAt = gmdate('Y-m-d H:i:s', time() + ($cooldownMinutes * 60));
            $stmt = $pdo->prepare(
                'UPDATE api_critical_email_notifications
                 SET status=:status,last_attempt_at=:attempt,last_sent_at=:sent_at,next_attempt_at=:next_attempt,
                     attempts=attempts+1,last_error_code=:error_code
                 WHERE fingerprint=:fingerprint'
            );
            $stmt->execute([
                'status' => $sent ? 'sent' : 'failed',
                'attempt' => $now,
                'sent_at' => $sent ? $now : null,
                'next_attempt' => $nextAttemptAt,
                'error_code' => $sent ? null : 'mail_failed',
                'fingerprint' => $fingerprint,
            ]);

            return $this->result(true, $sent, $sent ? 'sent' : 'failed', $fingerprint);
        } catch (Throwable $error) {
            Logger::write('warning', 'No se pudo registrar alerta crítica por email.', [
                'fingerprint' => $fingerprint,
                'error' => get_class($error),
            ]);
            return $this->result(false, false, 'ledger_unavailable', $fingerprint);
        }
    }

    /** @return array{attempted:bool,sent:bool,status:string,fingerprint:string} */
    public function sendTest(): array
    {
        $settings = new AppSettingsService();
        $to = trim((string) $settings->get('alerts.email.to', ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result(false, false, 'invalid_recipient', 'test');
        }

        [$subject, $body] = $this->message([
            'meli_account_id' => 0,
            'method' => 'GET',
            'endpoint_path' => '/settings/api-health',
            'endpoint_key' => 'critical_email_test',
            'http_status' => 429,
            'retry_after' => null,
            'request_id' => 'email-test-' . gmdate('YmdHis'),
            'execution_source' => 'admin_test',
            'job_type' => 'email_test',
            'source_work_id' => '',
            'next_safe_at' => gmdate('Y-m-d H:i:s', time() + 1800),
            'safe_message' => 'Prueba administrativa de alerta crítica ERP Meli.',
        ]);
        $headers = 'From: ' . (Env::get('MAIL_FROM', 'no-reply@localhost') ?: 'no-reply@localhost');
        $sent = $this->send($to, $subject, $body, $headers);

        return $this->result(true, $sent, $sent ? 'sent' : 'failed', 'test');
    }

    private function shouldNotifyStatus(int $status): bool
    {
        $settings = new AppSettingsService();
        if ($status === 429) {
            return $settings->bool('alerts.email.notify_429', true);
        }
        if (in_array($status, [401, 403], true)) {
            return $settings->bool('alerts.email.notify_auth', true);
        }
        return $status >= 500;
    }

    private function upsertLedger(PDO $pdo, string $fingerprint, string $incidentKey, string $scopeKey, string $now): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO api_critical_email_notifications
                 (fingerprint,incident_key,scope_key,first_seen_at,last_seen_at,repetition_count,status,next_attempt_at)
             VALUES
                 (:fingerprint,:incident_key,:scope_key,:now,:now,1,"pending",NULL)
             ON DUPLICATE KEY UPDATE
                 incident_key=VALUES(incident_key),
                 scope_key=VALUES(scope_key),
                 last_seen_at=VALUES(last_seen_at),
                 repetition_count=repetition_count+1'
        );
        $stmt->execute([
            'fingerprint' => $fingerprint,
            'incident_key' => $incidentKey,
            'scope_key' => $scopeKey,
            'now' => $now,
        ]);
    }

    private function claimSendLease(PDO $pdo, string $fingerprint, string $now, string $leaseUntil): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE api_critical_email_notifications
             SET status="sending",last_attempt_at=:now,next_attempt_at=:lease_until,updated_at=UTC_TIMESTAMP(3)
             WHERE fingerprint=:fingerprint
               AND (next_attempt_at IS NULL OR next_attempt_at<=:now_due)
               AND (status<>"sending" OR next_attempt_at<=:now_sending_due)'
        );
        $stmt->execute([
            'now' => $now,
            'lease_until' => $leaseUntil,
            'fingerprint' => $fingerprint,
            'now_due' => $now,
            'now_sending_due' => $now,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $context @return array{0:string,1:string} */
    private function message(array $context): array
    {
        $status = (int) ($context['http_status'] ?? 0);
        $severity = in_array($status, [401, 403, 429], true) || $status >= 500 ? 'critical' : 'warning';
        $endpoint = $this->safe((string) ($context['endpoint_path'] ?? $context['path'] ?? ''));
        $method = $this->safe((string) ($context['method'] ?? 'GET'));
        $source = $this->safe((string) ($context['execution_source'] ?? $context['source'] ?? 'unknown'));
        $companyId = (int) ($context['company_id'] ?? 0);
        $requestId = $this->safe((string) ($context['request_id'] ?? ''));
        $nextSafeAt = $this->safe((string) ($context['next_safe_at'] ?? ''));
        $retryAfter = $context['retry_after'] ?? null;
        $accountId = (int) ($context['meli_account_id'] ?? $context['account_id'] ?? 0);
        $jobType = $this->safe((string) ($context['job_type'] ?? ''));
        $sourceWorkId = $this->safe((string) ($context['source_work_id'] ?? ''));
        $operationKey = $this->safe((string) ($context['operation_key'] ?? $context['endpoint_key'] ?? ''));
        $message = $this->safe((string) ($context['safe_message'] ?? 'Mercado Libre devolvió una respuesta crítica.'));
        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/settings/api-health/incidents';

        $body = implode("\n", array_filter([
            'ERP Meli - alerta API crítica',
            'Fecha UTC: ' . gmdate('Y-m-d H:i:s'),
            'Severidad: ' . $severity,
            $companyId > 0 ? 'Empresa ID: ' . $companyId : '',
            'Cuenta ID: ' . ($accountId > 0 ? (string) $accountId : 'aplicacion'),
            'HTTP: ' . $status,
            'Método/endpoint: ' . trim($method . ' ' . $endpoint),
            'Fuente: ' . $source,
            $operationKey !== '' ? 'Operación: ' . $operationKey : '',
            $jobType !== '' ? 'Trabajo: ' . $jobType : '',
            $sourceWorkId !== '' ? 'Work ID: ' . $sourceWorkId : '',
            $requestId !== '' ? 'Request ID: ' . $requestId : '',
            is_numeric($retryAfter) ? 'Retry-After: ' . (int) $retryAfter . ' segundos' : '',
            $nextSafeAt !== '' ? 'Next safe at UTC: ' . $nextSafeAt : '',
            'Mensaje seguro: ' . $message,
            $link !== '/settings/api-health/incidents' ? 'Enlace interno: ' . $link : '',
        ], static fn(string $line): bool => $line !== ''));

        return ['ERP Meli: alerta API HTTP ' . $status, $body];
    }

    /** @param array<string,mixed> $context */
    private function fingerprint(array $context): string
    {
        return hash('sha256', implode('|', [
            (int) ($context['meli_account_id'] ?? $context['account_id'] ?? 0),
            strtoupper((string) ($context['method'] ?? 'GET')),
            $this->scopeKey($context),
            (int) ($context['http_status'] ?? 0),
        ]));
    }

    /** @param array<string,mixed> $context */
    private function incidentKey(array $context): string
    {
        $key = trim((string) ($context['incident_key'] ?? ''));
        return $key !== '' ? mb_substr($this->safe($key), 0, 190) : mb_substr($this->fingerprint($context), 0, 64);
    }

    /** @param array<string,mixed> $context */
    private function scopeKey(array $context): string
    {
        $endpoint = strtolower(trim((string) ($context['endpoint_key'] ?? $context['endpoint_path'] ?? $context['path'] ?? 'unknown')));
        $source = strtolower(trim((string) ($context['execution_source'] ?? $context['source'] ?? 'unknown')));
        return mb_substr($this->safe($source . ':' . $endpoint), 0, 190);
    }

    private function safe(string $value): string
    {
        return mb_substr(Logger::redactString($value), 0, 500);
    }

    private function send(string $to, string $subject, string $body, string $headers): bool
    {
        if ($this->mailTransport !== null) {
            return (bool) ($this->mailTransport)($to, $subject, $body, $headers);
        }
        return @mail($to, $subject, $body, $headers);
    }

    /** @return array{attempted:bool,sent:bool,status:string,fingerprint:string} */
    private function result(bool $attempted, bool $sent, string $status, string $fingerprint): array
    {
        return compact('attempted', 'sent', 'status', 'fingerprint');
    }
}
