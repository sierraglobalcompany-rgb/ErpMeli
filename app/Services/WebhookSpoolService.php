<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class WebhookSpoolService
{
    /**
     * Validación pura: no abre PDO ni toca la cola viva.
     *
     * @return array<string,mixed>
     */
    public function validateIngress(string $raw): array
    {
        if (strlen($raw) > WebhookService::maxPayloadBytes()) {
            return $this->invalid(413, 'payload_too_large', 'Payload demasiado grande.');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->invalid(400, 'invalid_json', 'JSON inválido.');
        }
        $topicValue = $payload['topic'] ?? null;
        $resourceValue = $payload['resource'] ?? null;
        $applicationValue = $payload['application_id'] ?? null;
        $topic = is_scalar($topicValue) ? strtolower(trim((string) $topicValue)) : '';
        $resource = $resourceValue === null || is_scalar($resourceValue)
            ? ($resourceValue === null ? null : trim((string) $resourceValue))
            : null;
        $applicationId = $applicationValue === null || is_scalar($applicationValue)
            ? ($applicationValue === null ? null : trim((string) $applicationValue))
            : null;
        $userId = isset($payload['user_id']) && is_numeric($payload['user_id'])
            ? (int) $payload['user_id']
            : null;
        if (
            $topic === ''
            || mb_strlen($topic) > 120
            || $userId === null
            || $userId <= 0
            || ($resourceValue !== null && !is_scalar($resourceValue))
            || ($applicationValue !== null && !is_scalar($applicationValue))
        ) {
            return $this->invalid(400, 'invalid_required_fields', 'Faltan campos obligatorios o su formato es inválido.');
        }
        if ($resource !== null && mb_strlen($resource) > 500) {
            return $this->invalid(400, 'invalid_resource', 'Formato de recurso inválido.');
        }
        $expectedApp = trim((string) Env::get('MELI_CLIENT_ID', ''));
        if ($expectedApp !== '' && ($applicationId === null || !hash_equals($expectedApp, $applicationId))) {
            Logger::writeLocal('warning', 'Webhook rechazado por application_id ajeno.');
            return $this->invalid(403, 'foreign_application', 'Aplicación no autorizada.');
        }
        $classification = (new MeliNotificationTopicRegistry())->classify(
            $topic,
            $resource,
            $payload['actions'] ?? null
        );
        if (empty($classification['valid'])) {
            return $this->invalid(
                422,
                (string) $classification['reason'],
                'Tópico o recurso no reconocido.'
            );
        }
        return [
            'valid' => true,
            'http_status' => 200,
            'reason' => 'accepted',
            'message' => 'Notificación válida.',
            'payload' => $payload,
            'topic' => $topic,
            'resource' => $resource,
            'user_id' => $userId,
            'application_id' => $applicationId,
            'classification' => $classification,
        ];
    }

    /**
     * @return array{valid:bool,terminal:bool,http_status:int,reason:string,message:string,account_id:?int,company_id:?int}
     */
    public function validateLinkedAccount(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'valid' => false,
                'terminal' => true,
                'http_status' => 403,
                'reason' => 'unlinked_account',
                'message' => 'La cuenta de la notificación no está vinculada.',
                'account_id' => null,
                'company_id' => null,
            ];
        }
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT a.id,a.company_id
                 FROM meli_accounts a
                 INNER JOIN companies c ON c.id=a.company_id
                 WHERE a.meli_user_id=:user AND c.status=1
                 ORDER BY a.id LIMIT 2'
            );
            $stmt->execute(['user' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return [
                    'valid' => false,
                    'terminal' => true,
                    'http_status' => 403,
                    'reason' => 'unlinked_account',
                    'message' => 'La cuenta de la notificación no está vinculada.',
                    'account_id' => null,
                    'company_id' => null,
                ];
            }
            $accountId = (int) ($rows[0]['id'] ?? 0);
            $companyId = (int) ($rows[0]['company_id'] ?? 0);
            if ($accountId <= 0 || $companyId <= 0) {
                return [
                    'valid' => false,
                    'terminal' => true,
                    'http_status' => 403,
                    'reason' => 'unlinked_account',
                    'message' => 'La cuenta de la notificación no está vinculada.',
                    'account_id' => null,
                    'company_id' => null,
                ];
            }
            return [
                'valid' => true,
                'terminal' => false,
                'http_status' => 200,
                'reason' => 'linked_account',
                'message' => 'Cuenta vinculada.',
                'account_id' => $accountId,
                'company_id' => $companyId,
            ];
        } catch (Throwable $error) {
            Logger::writeLocal('warning', 'No fue posible validar la cuenta del webhook antes del spool.');
            return [
                'valid' => false,
                'terminal' => false,
                'http_status' => 503,
                'reason' => 'account_validation_unavailable',
                'message' => 'No fue posible validar la cuenta de la notificación.',
                'account_id' => null,
                'company_id' => null,
            ];
        }
    }

    public function append(string $raw): bool
    {
        if (strlen($raw) > WebhookService::maxPayloadBytes()) {
            return false;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        $directory = AppPaths::storage('spool/mercadolibre-webhooks');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }
        $record = [
            'spooled_at' => gmdate(DATE_ATOM),
            'payload' => Logger::redact($payload),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            return false;
        }
        $line .= PHP_EOL;
        $maxBytes = max(1048576, min(1073741824, (int) Env::get('WEBHOOK_SPOOL_MAX_BYTES', '134217728')));
        $fileMaxBytes = max(1048576, min(67108864, (int) Env::get('WEBHOOK_SPOOL_FILE_MAX_BYTES', '16777216')));
        $lock = @fopen($directory . '/.spool.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return false;
        }
        try {
            $usage = 0;
            $usageFiles = array_values(array_unique(array_merge(
                glob($directory . '/webhooks-*.jsonl') ?: [],
                glob($directory . '/webhooks-*.jsonl.processing-*') ?: []
            )));
            foreach ($usageFiles as $candidate) {
                $usage += max(0, (int) @filesize($candidate));
                if ($usage + strlen($line) > $maxBytes) {
                    return false;
                }
            }
            $prefix = $directory . '/webhooks-' . gmdate('Y-m-d-H');
            for ($shard = 0; $shard < 100; $shard++) {
                $file = $prefix . ($shard === 0 ? '' : '-' . str_pad((string) $shard, 2, '0', STR_PAD_LEFT)) . '.jsonl';
                if (!is_file($file) || (int) @filesize($file) + strlen($line) <= $fileMaxBytes) {
                    $written = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
                    if ($written) {
                        $current = $this->readCounter($directory);
                        $this->writeCounter(
                            $directory,
                            $current !== null ? (int) $current['count'] + 1 : $this->countFiles($directory)
                        );
                    }
                    return $written;
                }
            }
            return false;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function quarantine(string $raw, string $reason): bool
    {
        $directory = AppPaths::storage('spool/mercadolibre-webhooks-quarantine');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }
        $payload = json_decode($raw, true);
        $canonical = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $hash = hash('sha256', is_string($canonical) ? $canonical : $raw);
        $target = $directory . '/quarantine-' . $hash . '.json';
        if (is_file($target)) {
            return true;
        }
        $lock = @fopen($directory . '/.quarantine.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return false;
        }
        try {
            if (is_file($target)) {
                return true;
            }
            $safeReason = preg_replace('/[^a-z0-9_.-]/i', '_', $reason) ?: 'invalid_payload';
            $record = [
                'quarantined_at' => gmdate(DATE_ATOM),
                'reason' => mb_substr($safeReason, 0, 80),
                'payload_sha256' => $hash,
                'payload_bytes' => strlen($raw),
                'payload' => is_array($payload) ? Logger::redact($payload) : null,
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return false;
            }
            $maxBytes = max(1048576, min(134217728, (int) Env::get('WEBHOOK_QUARANTINE_MAX_BYTES', '16777216')));
            $usage = 0;
            foreach (glob($directory . '/quarantine-*.json') ?: [] as $file) {
                $usage += max(0, (int) @filesize($file));
            }
            if ($usage + strlen($encoded) > $maxBytes) {
                return false;
            }
            $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
            if (@file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                return false;
            }
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                return is_file($target);
            }
            @chmod($target, 0640);
            return true;
        } catch (Throwable) {
            return false;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{processed:int,completed:int,quarantined:int,errors:int,files:int} */
    public function replay(int $limit = 100, ?float $deadline = null): array
    {
        $directory = AppPaths::storage('spool/mercadolibre-webhooks');
        if (!is_dir($directory)) {
            return ['processed' => 0, 'completed' => 0, 'quarantined' => 0, 'errors' => 0, 'files' => 0];
        }
        $processed = 0;
        $quarantined = 0;
        $errors = 0;
        $files = 0;
        $this->recoverAbandonedClaims($directory);
        foreach (glob($directory . '/webhooks-*.jsonl') ?: [] as $file) {
            if ($processed + $quarantined >= $limit || ($deadline !== null && microtime(true) >= $deadline - 0.25)) {
                break;
            }
            $claimed = $this->claim($directory, $file);
            if ($claimed === null) {
                continue;
            }
            $handle = @fopen($claimed, 'rb');
            if ($handle === false) {
                $errors++;
                continue;
            }
            $remaining = [];
            try {
                while (($line = fgets($handle)) !== false) {
                    if ($deadline !== null && microtime(true) >= $deadline - 0.25) {
                        $remaining[] = trim($line);
                        while (($rest = fgets($handle)) !== false) {
                            $remaining[] = trim($rest);
                        }
                        break;
                    }
                    $trimmed = trim($line);
                    $decoded = json_decode($trimmed, true);
                    $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : null;
                    if ($processed + $quarantined >= $limit) {
                        $remaining[] = $trimmed;
                        continue;
                    }
                    if ($payload === null) {
                        $this->quarantine($trimmed, 'invalid_spool_record');
                        $quarantined++;
                        continue;
                    }
                    $result = (new WebhookService())->receiveResult(
                        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                        'spool',
                        false
                    );
                    if (!empty($result['accepted'])) {
                        $processed++;
                    } elseif (!empty($result['terminal'])) {
                        $quarantined++;
                    } else {
                        $remaining[] = $trimmed;
                        $errors++;
                    }
                }
            } catch (Throwable) {
                $errors++;
            } finally {
                fclose($handle);
            }
            $files++;
            if ($remaining === []) {
                @unlink($claimed);
            } else {
                $this->restoreRemaining($directory, $claimed, $remaining);
            }
        }
        if ($processed + $quarantined > 0) {
            $this->adjustCounter($directory, -($processed + $quarantined));
        }
        return [
            'processed' => $processed,
            'completed' => $processed,
            'quarantined' => $quarantined,
            'errors' => $errors,
            'files' => $files,
        ];
    }

    /** @return array{valid:false,http_status:int,reason:string,message:string} */
    private function invalid(int $status, string $reason, string $message): array
    {
        return [
            'valid' => false,
            'http_status' => $status,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    public function pendingCount(): int
    {
        $directory = AppPaths::storage('spool/mercadolibre-webhooks');
        if (!is_dir($directory)) {
            return 0;
        }
        $cached = $this->readCounter($directory);
        if ($cached !== null && (int) $cached['reconciled_at'] >= time() - 300) {
            return max(0, (int) $cached['count']);
        }
        $lock = @fopen($directory . '/.spool.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return $cached !== null ? max(0, (int) $cached['count']) : $this->countFiles($directory);
        }
        try {
            $count = $this->countFiles($directory);
            $this->writeCounter($directory, $count);
            return $count;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{count:int,reconciled_at:int}|null */
    private function readCounter(string $directory): ?array
    {
        $decoded = json_decode((string) @file_get_contents($directory . '/.pending-count.json'), true);
        if (!is_array($decoded) || !isset($decoded['count'], $decoded['reconciled_at'])) {
            return null;
        }
        return [
            'count' => max(0, (int) $decoded['count']),
            'reconciled_at' => max(0, (int) $decoded['reconciled_at']),
        ];
    }

    private function writeCounter(string $directory, int $count): void
    {
        $temporary = $directory . '/.pending-count-' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode([
            'count' => max(0, $count),
            'reconciled_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || @file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temporary, $directory . '/.pending-count.json')) {
            @unlink($temporary);
        }
    }

    private function adjustCounter(string $directory, int $delta): void
    {
        $lock = @fopen($directory . '/.spool.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }
        try {
            $cached = $this->readCounter($directory);
            $count = $cached !== null ? (int) $cached['count'] : $this->countFiles($directory);
            $this->writeCounter($directory, max(0, $count + $delta));
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function countFiles(string $directory): int
    {
        $count = 0;
        $files = array_values(array_unique(array_merge(
            glob($directory . '/webhooks-*.jsonl') ?: [],
            glob($directory . '/webhooks-*.jsonl.processing-*') ?: []
        )));
        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }
        return $count;
    }

    private function claim(string $directory, string $file): ?string
    {
        $lock = @fopen($directory . '/.spool.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return null;
        }
        try {
            if (!is_file($file)) {
                return null;
            }
            $claimed = $file . '.processing-' . bin2hex(random_bytes(6));
            return @rename($file, $claimed) ? $claimed : null;
        } catch (Throwable) {
            return null;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<string> $remaining */
    private function restoreRemaining(string $directory, string $claimed, array $remaining): void
    {
        $temporary = $directory . '/.restore-' . bin2hex(random_bytes(8)) . '.tmp';
        $body = implode(PHP_EOL, $remaining) . PHP_EOL;
        if (@file_put_contents($temporary, $body, LOCK_EX) === false) {
            return;
        }
        $lock = @fopen($directory . '/.spool.lock', 'c+b');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            @unlink($temporary);
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }
        try {
            $target = $directory . '/webhooks-retry-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl';
            if (@rename($temporary, $target)) {
                @unlink($claimed);
            } else {
                @unlink($temporary);
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recoverAbandonedClaims(string $directory): void
    {
        $threshold = time() - 600;
        foreach (glob($directory . '/webhooks-*.jsonl.processing-*') ?: [] as $claimed) {
            $modifiedAt = (int) @filemtime($claimed);
            if ($modifiedAt <= 0 || $modifiedAt > $threshold) {
                continue;
            }
            $lock = @fopen($directory . '/.spool.lock', 'c+b');
            if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                return;
            }
            try {
                if (!is_file($claimed)) {
                    continue;
                }
                $target = $directory . '/webhooks-recovered-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl';
                @rename($claimed, $target);
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
