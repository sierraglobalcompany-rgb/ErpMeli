<?php
declare(strict_types=1);
namespace App\QueueCore;
final readonly class QueueExecutionContext
{
    public function __construct(public int $attemptId,public float $deadline,public string $launcher) {}
    public function hasTime(float $safeCloseSeconds=1.0): bool { return microtime(true)+max(0.0,$safeCloseSeconds)<$this->deadline; }
}
