<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final readonly class WorkResult
{
    /** @param array<string,mixed> $metadata */
    private function __construct(
        public string $status,
        public ?string $availableAt,
        public ?string $code,
        public array $metadata,
    ) {
        if (!in_array($status, ['completed', 'deferred', 'review', 'dead'], true)) {
            throw new InvalidArgumentException('Invalid Cron V3 result state.');
        }
        if ($status === 'deferred' && $availableAt === null) {
            throw new InvalidArgumentException('Deferred work requires available_at.');
        }
    }

    /** @param array<string,mixed> $metadata */
    public static function completed(array $metadata = []): self
    {
        return new self('completed', null, null, $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function deferred(string $availableAt, string $code, array $metadata = []): self
    {
        return new self('deferred', $availableAt, self::safeCode($code), $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function review(string $code, array $metadata = []): self
    {
        return new self('review', null, self::safeCode($code), $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function dead(string $code, array $metadata = []): self
    {
        return new self('dead', null, self::safeCode($code), $metadata);
    }

    private static function safeCode(string $code): string
    {
        $safe = preg_replace('/[^a-z0-9_.-]/i', '_', trim($code)) ?? '';
        if ($safe === '') {
            throw new InvalidArgumentException('Cron V3 result code is required.');
        }
        return substr($safe, 0, 100);
    }
}
