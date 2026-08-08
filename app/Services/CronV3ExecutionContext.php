<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use LogicException;
use RuntimeException;

final class CronV3ExecutionContext
{
    private int $logicalCalls = 0;

    /** @param null|Closure():array{allowed:bool,retry_at:?string,reason:string} $permit */
    private function __construct(
        private readonly string $lane,
        private readonly bool $shadow,
        private readonly ?Closure $permit,
    ) {
    }

    public static function local(bool $shadow = false): self
    {
        return new self('local', $shadow, null);
    }

    /** @param callable():array{allowed:bool,retry_at:?string,reason:string} $permit */
    public static function remote(callable $permit, bool $shadow = false): self
    {
        return new self('remote', $shadow, Closure::fromCallable($permit));
    }

    public static function shadow(string $lane): self
    {
        if (!in_array($lane, ['local', 'remote'], true)) {
            throw new LogicException('Invalid shadow lane.');
        }
        return new self($lane, true, null);
    }

    /**
     * @template T
     * @param callable():T $transport
     * @return T
     */
    public function logicalRemoteCall(callable $transport): mixed
    {
        if ($this->shadow) {
            throw new LogicException('Cron V3 shadow mode forbids transport.');
        }
        if ($this->lane !== 'remote') {
            throw new LogicException('Cron V3 local lane forbids Mercado Libre transport.');
        }
        if ($this->logicalCalls >= 1) {
            throw new LogicException('Cron V3 permits exactly one logical remote call per attempt.');
        }
        if ($this->permit === null) {
            throw new RuntimeException('Cron V3 remote rate authority is unavailable.');
        }

        CronDeadlineContext::assertCanStartRemote(2.0);
        $reservation = ($this->permit)();
        if (!$reservation['allowed']) {
            throw new CronV3RateLimitedException(
                $reservation['reason'],
                $reservation['retry_at'] ?? gmdate('Y-m-d H:i:s', time() + 60),
            );
        }

        $this->logicalCalls++;
        return $transport();
    }

    public function logicalCallCount(): int
    {
        return $this->logicalCalls;
    }
}

final class CronV3RateLimitedException extends RuntimeException
{
    public function __construct(string $reason, public readonly string $retryAt)
    {
        parent::__construct($reason);
    }
}
