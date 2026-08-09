<?php

declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

/** Closed registry: unlisted legacy queues can never materialize Queue Core work. */
final class HistoricalBacklogSourceRegistry
{
    /** @var array<string,HistoricalBacklogSource> */
    private array $sources = [];

    /** @param list<HistoricalBacklogSource>|null $sources */
    public function __construct(?array $sources = null)
    {
        foreach ($sources ?? [new NotificationOrderHistoricalSource()] as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    public function get(string $sourceKey): HistoricalBacklogSource
    {
        if (!isset($this->sources[$sourceKey])) {
            throw new RuntimeException('Historical source is not certified.');
        }
        return $this->sources[$sourceKey];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
