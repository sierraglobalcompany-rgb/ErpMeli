<?php

declare(strict_types=1);

namespace App\Services;

final class ApiRhythmDeferredException extends ApiBudgetExhaustedException
{
    public function __construct(
        string $message,
        ?string $nextSafeAt = null,
        public readonly string $blockingScope = 'rhythm',
        public readonly bool $reachedRemote = false
    ) {
        parent::__construct($message, $nextSafeAt);
    }
}
