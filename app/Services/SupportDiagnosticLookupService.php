<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

final class SupportDiagnosticLookupService
{
    /** @return array<string,mixed> */
    public function find(string $reference): array
    {
        $reference = trim($reference);
        if (preg_match('/^ERR-\d{8}-\d{6}-[a-f0-9]{6}$/i', $reference) !== 1) {
            throw new HttpException(404, 'No se encontró el diagnóstico solicitado.');
        }

        $stmt = Database::connectionFresh()->prepare(
            'SELECT id,level,message,context_json,created_at
             FROM system_logs
             WHERE message LIKE ?
                OR context_json LIKE ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $like = '%' . $reference . '%';
        $stmt->execute([$like, $like]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró el diagnóstico solicitado.');
        }

        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        $context = is_array($context) ? Logger::redact($context) : [];
        $safeMessage = $this->safeCause($context);

        return [
            'reference' => $reference,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'level' => (string) ($row['level'] ?? 'error'),
            'route' => $this->safeRoute((string) ($context['route'] ?? '')),
            'method' => (string) ($context['method'] ?? ''),
            'exception' => $this->safeClass((string) ($context['exception'] ?? '')),
            'sqlstate' => $this->shortToken((string) ($context['sqlstate'] ?? '')),
            'driver_code' => $this->shortToken((string) ($context['driver_code'] ?? '')),
            'safe_message' => $safeMessage,
            'recommended_action' => $this->recommendedAction($context, $safeMessage),
        ];
    }

    /** @param array<string,mixed> $context */
    private function safeCause(array $context): string
    {
        $raw = Logger::redactString((string) ($context['error'] ?? ''));
        $raw = preg_replace('/\b(?:[A-Z]:\\\\|\/home\/|\/var\/www\/|\/public_html\/)[^\s]+/i', '[ruta privada]', $raw) ?? $raw;
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        return mb_substr(trim($raw), 0, 500) ?: 'El diagnóstico no conservó un mensaje técnico seguro.';
    }

    private function safeRoute(string $route): string
    {
        if ($route === '' || str_contains($route, '..')) {
            return '';
        }
        return mb_substr($route, 0, 180);
    }

    private function safeClass(string $class): string
    {
        if ($class === '') {
            return '';
        }
        $parts = explode('\\', $class);
        return preg_replace('/[^A-Za-z0-9_]/', '', end($parts) ?: '') ?: 'Error';
    }

    private function shortToken(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,16}$/', $value) === 1 ? $value : '';
    }

    /** @param array<string,mixed> $context */
    private function recommendedAction(array $context, string $safeMessage): string
    {
        $driver = (string) ($context['driver_code'] ?? '');
        $sqlstate = (string) ($context['sqlstate'] ?? '');
        $lower = strtolower($safeMessage);
        if ($sqlstate === '42S22' || $driver === '1054' || str_contains($lower, 'unknown column')) {
            return 'Revisar desalineación entre código y esquema. Aplique la actualización vigente y vuelva a probar la ruta.';
        }
        if (str_contains($lower, 'base table') || str_contains($lower, 'table') && str_contains($lower, "doesn't exist")) {
            return 'Revisar migraciones pendientes o subida incompleta de archivos.';
        }
        if (str_contains($lower, 'access denied') || str_contains($lower, 'permission')) {
            return 'Revisar permisos locales de base de datos o alcance de la cuenta.';
        }
        return 'Abrir la ruta indicada después de aplicar el hotfix; si se repite, conservar esta referencia para soporte.';
    }
}
