<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JobHandler;
use App\ValueObjects\JobLease;
use App\ValueObjects\JobResult;

/**
 * Adaptador común para ejecutar handlers sin reemplazar las colas históricas.
 */
final class SchedulerService
{
    public function __construct(private readonly CronWorkCoordinator $coordinator)
    {
    }

    /** @return array<string,mixed> */
    public function run(string $name, int $priority, JobHandler $handler, ?int $accountId = null, ?int $jobId = null): array
    {
        return $this->coordinator->run(
            $name,
            $priority,
            function (float $deadline) use ($name, $handler, $accountId, $jobId): array {
                $lease = new JobLease($name, bin2hex(random_bytes(16)), $deadline, $accountId, $jobId);
                return $handler->handle($lease)->toArray();
            }
        );
    }

    /**
     * @param callable(JobLease):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function runCallable(string $name, int $priority, callable $callback, ?int $accountId = null): array
    {
        $handler = new class(\Closure::fromCallable($callback)) implements JobHandler {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function handle(JobLease $lease): JobResult
            {
                $payload = ($this->callback)($lease);
                return new JobResult(
                    (string) ($payload['status'] ?? 'complete'),
                    (int) ($payload['processed'] ?? $payload['processed_chunks'] ?? 0),
                    (int) ($payload['errors'] ?? $payload['error_chunks'] ?? 0),
                    isset($payload['next_run_at']) ? (string) $payload['next_run_at'] : null,
                    isset($payload['stop_reason']) ? (string) $payload['stop_reason'] : null,
                    $payload
                );
            }
        };
        return $this->run($name, $priority, $handler, $accountId);
    }
}
