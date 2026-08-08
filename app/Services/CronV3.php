<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use PDO;

final class CronV3
{
    private static ?self $instance = null;

    private readonly CronV3WorkTypeRegistry $types;
    private readonly CronV3HandlerRegistry $handlers;
    private readonly CronV3Enqueuer $enqueuer;
    private readonly CronV3WorkRepository $repository;
    private readonly CronV3RateGate $rateGate;

    private function __construct(private readonly PDO $pdo)
    {
        $this->types = new CronV3WorkTypeRegistry();
        $this->handlers = new CronV3HandlerRegistry($this->types);
        $this->enqueuer = new CronV3Enqueuer($pdo, $this->types);
        $this->repository = new CronV3WorkRepository($pdo);
        $this->rateGate = new CronV3RateGate($pdo);
        CronV3DefaultHandlers::register($this->handlers);
        CronV3DefaultHandlerBootstrap::register($this->handlers);
    }

    public static function boot(?PDO $pdo = null): self
    {
        return self::$instance ??= new self($pdo ?? Database::connection());
    }

    /** @param CronV3WorkHandler|callable(WorkEnvelope,CronV3ExecutionContext):WorkResult $handler */
    public static function registerHandler(
        string $workType,
        string $lane,
        CronV3WorkHandler|callable $handler,
    ): void {
        self::boot()->handlers->register($workType, $lane, $handler);
    }

    public static function registerType(string $workType, string $lane): void
    {
        self::boot()->types->register($workType, $lane);
    }

    /** @return array{id:int,created:bool,status:string} */
    public static function enqueue(WorkEnvelope $work, ?string $availableAt = null): array
    {
        return self::boot()->enqueuer->enqueue($work, $availableAt);
    }

    /** @return array{id:int,created:bool,status:string} */
    public static function enqueueWithStatus(
        WorkEnvelope $work,
        string $status,
        ?string $reasonCode = null,
        ?string $availableAt = null
    ): array {
        return self::boot()->enqueuer->enqueueWithStatus($work, $status, $reasonCode, $availableAt);
    }

    public function runner(int $rateLimit = 10): CronV3Runner
    {
        return new CronV3Runner(
            $this->repository,
            $this->handlers,
            $this->types,
            $this->rateGate,
            $this->enqueuer,
            $rateLimit,
        );
    }

    public function repository(): CronV3WorkRepository
    {
        return $this->repository;
    }

    public function types(): CronV3WorkTypeRegistry
    {
        return $this->types;
    }

    public function handlers(): CronV3HandlerRegistry
    {
        return $this->handlers;
    }

    public static function resetForTests(): void
    {
        self::$instance = null;
    }
}
