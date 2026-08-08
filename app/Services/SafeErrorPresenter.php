<?php

declare(strict_types=1);

namespace App\Services;

use PDOException;
use Throwable;

final class SafeErrorPresenter
{
    /**
     * @param array<string,mixed> $context
     * @return array{reference:string,message:string}
     */
    public static function report(Throwable $error, string $userMessage = 'No fue posible completar la operación.', array $context = []): array
    {
        $reference = self::reference();
        $technical = [
            'reference' => $reference,
            'exception' => $error::class,
            'code' => (string) $error->getCode(),
            'sqlstate' => $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? '') : '',
            'driver_code' => $error instanceof PDOException ? (string) ($error->errorInfo[1] ?? '') : '',
            'error' => Logger::redactString(mb_substr($error->getMessage(), 0, 1000)),
        ] + $context;
        Logger::write('error', $userMessage . ' Referencia: ' . $reference, $technical);

        return [
            'reference' => $reference,
            'message' => rtrim($userMessage) . ' Código de diagnóstico: ' . $reference . '.',
        ];
    }

    public static function reference(): string
    {
        return 'ERR-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    /** @param array<string,mixed> $context */
    public static function message(Throwable $error, string $userMessage = 'No fue posible completar la operación.', array $context = []): string
    {
        return self::report($error, $userMessage, $context)['message'];
    }
}
