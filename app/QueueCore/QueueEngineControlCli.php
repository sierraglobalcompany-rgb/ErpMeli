<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Core\Env;
use Throwable;

final class QueueEngineControlCli
{
    /** @param list<string> $argv @return array<string,mixed> */
    public function run(array $argv): array
    {
        try {
            Database::useProfile('cli');
            $service = new QueueEngineControlService(Database::connectionFresh());
            $desired = $this->option($argv, 'set');
            $readiness = $this->option($argv, 'readiness');
            if ($desired === null && $readiness === null) {
                return ['ok' => true, 'status' => 'read_only'] + $service->snapshot();
            }
            $expected = $this->integerOption($argv, 'expected-generation');
            if ($expected === null) {
                return ['ok' => false, 'status' => 'expected_generation_required'] + $service->snapshot();
            }
            if (($desired !== null && $desired !== 'disabled') && Env::bool('ML_WRITE_ENABLED', false)) {
                return ['ok' => false, 'status' => 'blocked_ml_write_enabled'] + $service->snapshot();
            }
            $result = $readiness !== null
                ? $service->compareAndSwapReadiness($readiness, $expected, 'queue_engine_control_cli')
                : $service->compareAndSwap((string) $desired, $expected, 'queue_engine_control_cli');
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
