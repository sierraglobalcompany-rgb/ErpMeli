<?php
declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

final class QueueHandlerRegistry
{
    /** @var array<string,QueueHandler> */
    private array $handlers = [];

    public function register(string $workType, QueueHandler $handler): void
    {
        if (isset($this->handlers[$workType])) {
            throw new RuntimeException('Queue Core handler already registered.');
        }
        $this->handlers[$workType] = $handler;
    }

    public function get(string $workType): QueueHandler
    {
        return $this->handlers[$workType]
            ?? throw new RuntimeException('Queue Core has no handler for this work type.');
    }

    /** @return list<string> */
    public function workTypes(): array
    {
        return array_keys($this->handlers);
    }
}
