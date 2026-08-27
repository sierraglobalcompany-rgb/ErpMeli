<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ApiBudgetExhaustedException extends RuntimeException
{
    /** @param list<array<string,mixed>|string> $blockedScopes */
    public function __construct(
        string $message,
        public readonly ?string $nextSafeAt = null,
        public readonly array $blockedScopes = []
    ) {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function blockedScopeNames(): array
    {
        $names = [];
        foreach ($this->blockedScopes as $scope) {
            $name = is_array($scope) ? (string) ($scope['scope'] ?? '') : (string) $scope;
            $name = strtolower(trim($name));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
