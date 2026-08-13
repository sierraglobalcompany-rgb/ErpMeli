<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use Throwable;

final class QueueV4CleanSafeDiagnosticService
{
    /** @return array{diagnostic_id:string,error_class:string,safe_stage:string,file:string,line:int} */
    public function capture(Throwable $error, ?string $stage = null, string $event = 'QUEUE_V4_CLEAN_FAILED'): array
    {
        $receipt = [
            'diagnostic_id' => gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)),
            'error_class' => $this->safeClass($error),
            'safe_stage' => $this->safeStage($stage ?? QueueV4CleanOAuthStageContext::current()),
            'file' => substr(basename($error->getFile()), 0, 120),
            'line' => max(0, $error->getLine()),
        ];
        error_log($event . ' ' . json_encode($receipt, JSON_UNESCAPED_SLASHES));
        return $receipt;
    }

    private function safeClass(Throwable $error): string
    {
        $short = strtolower((new \ReflectionClass($error))->getShortName());
        return substr(preg_replace('/[^a-z0-9_]+/', '_', $short) ?: 'throwable', 0, 80);
    }

    private function safeStage(string $stage): string
    {
        return in_array($stage, QueueV4CleanOAuthStageContext::all(), true)
            ? $stage
            : QueueV4CleanOAuthStageContext::SCHEDULER_START;
    }
}
