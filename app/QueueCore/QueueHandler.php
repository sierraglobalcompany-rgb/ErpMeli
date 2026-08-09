<?php
declare(strict_types=1);
namespace App\QueueCore;
interface QueueHandler { public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult; }
