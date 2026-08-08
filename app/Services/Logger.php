<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\AppPaths;
use Throwable;

final class Logger
{
    private const REDACT_KEY_PATTERN = '/(?:^|_)(?:access_?token|refresh_?token|client_?secret|authorization|password|passwd|app_?key|api_?key|cookie|session|secret)(?:$|_)/i';

    public static function write(string $level, string $message, array $context = []): void
    {
        $safe = self::redact($context);
        if (!Database::connectionUnavailable()) {
            try {
                $stmt = Database::connection()->prepare('INSERT INTO system_logs (level, message, context_json) VALUES (:level, :message, :context)');
                $stmt->execute(['level' => $level, 'message' => mb_substr($message, 0, 500), 'context' => json_encode($safe, JSON_UNESCAPED_UNICODE)]);
                return;
            } catch (Throwable) {
                // El fallback local no vuelve a abrir una conexión fallida.
            }
        }
        self::writeLocal($level, $message);
    }

    public static function writeLocal(string $level, string $message): void
    {
        $safeLevel = preg_replace('/[^a-z]/i', '', $level) ?: 'info';
        $safeMessage = mb_substr(self::redactString($message), 0, 500);
        $line = sprintf("[%s] %s %s\n", date('c'), strtoupper($safeLevel), $safeMessage);
        $logDirectory = AppPaths::storage('logs');
        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0770, true);
        }
        @file_put_contents($logDirectory . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (preg_match(self::REDACT_KEY_PATTERN, (string) $key) === 1) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = self::redact($item);
            } elseif (is_string($item)) {
                $value[$key] = self::redactString($item);
            }
        }
        return $value;
    }

    public static function redactString(string $value): string
    {
        $safe = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
        $safe = preg_replace(
            '/([?&](?:access_token|refresh_token|token|client_secret|app_key|api_key|password)=)[^&#\s]+/i',
            '$1[REDACTED]',
            $safe
        ) ?? $safe;
        $safe = preg_replace(
            '/("(?:access_token|refresh_token|client_secret|authorization|password|app_key|api_key)"\s*:\s*")[^"]*(")/i',
            '$1[REDACTED]$2',
            $safe
        ) ?? $safe;
        return $safe;
    }
}
