<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use JsonException;

final readonly class WorkEnvelope
{
    /** @param array<string,mixed> $payload */
    private function __construct(
        public ?int $id,
        public int $companyId,
        public int $meliAccountId,
        public string $workType,
        public string $lane,
        public string $dedupeKey,
        public string $inputVersion,
        public ?string $sourceRef,
        public array $payload,
        public int $priority,
        public ?string $ownerToken = null,
        public int $leaseGeneration = 0,
        public ?string $leaseUntil = null,
    ) {
        if ($companyId < 1 || $meliAccountId < 1) {
            throw new InvalidArgumentException('Cron V3 requires company_id and meli_account_id.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $workType) !== 1) {
            throw new InvalidArgumentException('Invalid Cron V3 work_type.');
        }
        if (!in_array($lane, ['local', 'remote'], true)) {
            throw new InvalidArgumentException('Invalid Cron V3 lane.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $dedupeKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $inputVersion) !== 1) {
            throw new InvalidArgumentException('Cron V3 dedupe and input version must be SHA-256 digests.');
        }
        if ($sourceRef !== null && strlen($sourceRef) > 190) {
            throw new InvalidArgumentException('Cron V3 source_ref is too long.');
        }
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('Cron V3 priority is outside the supported range.');
        }
        self::assertPayloadHasNoSecrets($payload);
    }

    /** @param array<string,mixed> $payload */
    public static function create(
        int $companyId,
        int $meliAccountId,
        string $workType,
        string $lane,
        string $resourceKey,
        string $inputVersion,
        array $payload = [],
        ?string $sourceRef = null,
        int $priority = 100,
    ): self {
        if ($resourceKey === '' || $inputVersion === '') {
            throw new InvalidArgumentException('Cron V3 resource key and input version are required.');
        }

        return new self(
            null,
            $companyId,
            $meliAccountId,
            $workType,
            $lane,
            hash('sha256', $resourceKey),
            hash('sha256', $inputVersion),
            $sourceRef,
            $payload,
            $priority,
        );
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = [];
        }

        return new self(
            (int) $row['id'],
            (int) $row['company_id'],
            (int) $row['meli_account_id'],
            (string) $row['work_type'],
            (string) $row['lane'],
            (string) $row['dedupe_key'],
            (string) $row['input_version'],
            isset($row['source_ref']) ? (string) $row['source_ref'] : null,
            is_array($payload) ? $payload : [],
            (int) ($row['priority'] ?? 100),
            isset($row['owner_token']) ? (string) $row['owner_token'] : null,
            (int) ($row['lease_generation'] ?? 0),
            isset($row['lease_until']) ? (string) $row['lease_until'] : null,
        );
    }

    /** @param array<string|int,mixed> $payload */
    private static function assertPayloadHasNoSecrets(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)
                && preg_match('/token|secret|password|authorization|credential|access_key/i', $key) === 1) {
                throw new InvalidArgumentException('Cron V3 payload cannot contain secrets or tokens.');
            }
            if (is_array($value)) {
                self::assertPayloadHasNoSecrets($value);
            }
        }
    }
}
