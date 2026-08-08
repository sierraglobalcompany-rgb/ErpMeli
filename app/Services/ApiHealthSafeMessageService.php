<?php

declare(strict_types=1);

namespace App\Services;

final class ApiHealthSafeMessageService
{
    /** @return array{safe_message:?string,diagnostic_id:?string,normalized_error_code:?string} */
    public function present(?string $message, ?string $errorCode = null): array
    {
        $message = trim((string) $message);
        $errorCode = trim((string) $errorCode);
        $safeErrorCode = $this->normalizedErrorCode($errorCode);
        if ($message === '') {
            return [
                'safe_message' => null,
                'diagnostic_id' => null,
                'normalized_error_code' => $safeErrorCode,
            ];
        }

        $redacted = Logger::redactString($message);
        $technicalCode = $this->technicalCode($redacted);
        if ($technicalCode !== null) {
            $diagnostic = SafeErrorPresenter::reference();
            Logger::write('warning', 'Salud API ocultó un detalle técnico antes de persistirlo.', [
                'diagnostic_id' => $diagnostic,
                'normalized_error_code' => $technicalCode,
            ]);
            return [
                'safe_message' => 'Ocurrió un problema local. Revise el diagnóstico ' . $diagnostic . '.',
                'diagnostic_id' => $diagnostic,
                'normalized_error_code' => $safeErrorCode ?? $technicalCode,
            ];
        }

        $redacted = preg_replace('/(?:[A-Z]:\\\\|\/home\/|\/var\/www\/)[^\s"\']+/i', '[RUTA PRIVADA]', $redacted) ?? $redacted;
        $redacted = preg_replace('/\s+/', ' ', $redacted) ?? $redacted;
        return [
            'safe_message' => mb_substr($redacted, 0, 500),
            'diagnostic_id' => null,
            'normalized_error_code' => $safeErrorCode,
        ];
    }

    private function technicalCode(string $message): ?string
    {
        return match (true) {
            preg_match('/SQLSTATE\s*\[/i', $message) === 1 => 'database_error',
            preg_match('/PDOException/i', $message) === 1 => 'database_error',
            preg_match('/(?:SELECT|INSERT|UPDATE|DELETE)\s+[^\r\n]{8,}/i', $message) === 1 => 'database_error',
            preg_match('/(?:unknown column|illegal mix of collations|access denied for user)/i', $message) === 1 => 'database_error',
            preg_match('/(?:[A-Z]:\\\\|\/home\/|\/var\/www\/)/i', $message) === 1 => 'private_path',
            default => null,
        };
    }

    private function normalizedErrorCode(string $errorCode): ?string
    {
        if ($errorCode === '') {
            return null;
        }
        if ($this->technicalCode($errorCode) !== null || preg_match('/SQLSTATE|PDO/i', $errorCode) === 1) {
            return 'database_error';
        }
        $normalized = preg_replace('/[^a-zA-Z0-9_.:\-]/', '_', $errorCode) ?? 'unknown_error';
        return mb_substr(trim($normalized, '_'), 0, 120) ?: 'unknown_error';
    }
}
