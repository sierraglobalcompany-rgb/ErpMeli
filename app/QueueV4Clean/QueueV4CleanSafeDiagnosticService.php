<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use Throwable;

final class QueueV4CleanSafeDiagnosticService
{
    /** @return array{diagnostic_id:string,error_class:string,safe_stage:string,file:string,line:int} */
    public function capture(Throwable $error, ?string $stage = null, string $event = 'QUEUE_V4_CLEAN_FAILED'): array
    {
        // Diagnostics are part of the final containment path. They must never
        // replace the primary Throwable or prevent the persisted dispatch
        // fence from deciding the operation's terminal state.
        try {
            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (Throwable) {
                $suffix = substr(hash('sha256', $error::class . '|' . $error->getFile() . '|' . $error->getLine()), 0, 8);
            }
            $receipt = [
                'diagnostic_id' => gmdate('YmdHis') . '-' . $suffix,
                'error_class' => $this->safeClass($error),
                'safe_stage' => $this->safeStage($stage ?? QueueV4CleanOAuthStageContext::current()),
                'file' => substr(basename($error->getFile()), 0, 120),
                'line' => max(0, $error->getLine()),
            ];
        } catch (Throwable) {
            $receipt = [
                'diagnostic_id' => gmdate('YmdHis') . '-fallback',
                'error_class' => 'throwable',
                'safe_stage' => QueueV4CleanOAuthStageContext::SCHEDULER_START,
                'file' => '',
                'line' => 0,
            ];
        }
        try {
            $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            @error_log($event . ' ' . $encoded);
        } catch (Throwable) {
            // Logging is best-effort. The structured in-memory receipt remains
            // available to the caller and containment continues.
        }
        return $receipt;
    }

    private function safeClass(Throwable $error): string
    {
        $class = $error::class;
        $separator = strrpos($class, '\\');
        $short = strtolower($separator === false ? $class : substr($class, $separator + 1));
        return substr(preg_replace('/[^a-z0-9_]+/', '_', $short) ?: 'throwable', 0, 80);
    }

    private function safeStage(string $stage): string
    {
        return in_array($stage, QueueV4CleanOAuthStageContext::all(), true)
            ? $stage
            : QueueV4CleanOAuthStageContext::SCHEDULER_START;
    }
}
