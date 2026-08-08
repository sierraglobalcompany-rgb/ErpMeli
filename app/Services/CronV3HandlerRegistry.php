<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CronV3WorkHandler;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class CronV3HandlerRegistry
{
    /** @var array<string,Closure(WorkEnvelope,CronV3ExecutionContext):WorkResult> */
    private array $handlers = [];

    public function __construct(private readonly CronV3WorkTypeRegistry $types)
    {
    }

    /** @param CronV3WorkHandler|callable(WorkEnvelope,CronV3ExecutionContext):WorkResult $handler */
    public function register(string $workType, string $lane, CronV3WorkHandler|callable $handler): void
    {
        $this->types->register($workType, $lane);
        if (isset($this->handlers[$workType])) {
            throw new InvalidArgumentException('Cron V3 handler is already registered.');
        }

        $this->handlers[$workType] = $handler instanceof CronV3WorkHandler
            ? Closure::fromCallable([$handler, 'executeOne'])
            : Closure::fromCallable($handler);
    }

    public function execute(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $handler = $this->handlers[$work->workType] ?? null;
        if (!$handler instanceof Closure) {
            throw new RuntimeException('Cron V3 handler is not registered.');
        }
        return $handler($work, $context);
    }

    /** @return list<string> */
    public function typesForLane(string $lane): array
    {
        return array_values(array_filter(
            array_keys($this->handlers),
            fn (string $type): bool => $this->types->admits($type, $lane),
        ));
    }
}
