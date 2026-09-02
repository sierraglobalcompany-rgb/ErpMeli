<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use Throwable;

final class QueueEngineControlCli
{
    /** @param list<string> $argv @return array<string,mixed> */
    public function run(array $argv): array
    {
        try {
            $desired = $this->option($argv, 'set');
            $readiness = $this->option($argv, 'readiness');
            if ($readiness !== null || ($desired !== null && $desired !== 'disabled')) {
                return [
                    'ok' => false,
                    'status' => 'legacy_engine_activation_retired',
                    'remote' => false,
                    'http' => 0,
                ];
            }
            Database::useProfile('cli');
            $service = new QueueEngineControlService(Database::connectionFresh());
            if ($desired === null && $readiness === null) {
                return ['ok' => true, 'status' => 'read_only'] + $service->snapshot();
            }
            $expected = $this->integerOption($argv, 'expected-generation');
            if ($expected === null) {
                return ['ok' => false, 'status' => 'expected_generation_required'] + $service->snapshot();
            }
            $result = $service->compareAndSwap('disabled', $expected, 'queue_engine_control_cli');
            return ['status' => $result['ok'] ? 'changed' : 'not_changed'] + $result;
        } catch (Throwable) {
            return ['ok' => false, 'status' => 'control_unavailable'];
        }
    }

    /** @param list<string> $argv */
    private function option(array $argv, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($argv as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return trim(substr($argument, strlen($prefix)));
            }
        }
        return null;
    }

    /** @param list<string> $argv */
    private function integerOption(array $argv, string $name): ?int
    {
        $raw = $this->option($argv, $name);
        return $raw !== null && ctype_digit($raw) ? (int) $raw : null;
    }
}
