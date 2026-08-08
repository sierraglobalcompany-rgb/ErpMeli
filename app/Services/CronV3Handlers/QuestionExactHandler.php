<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\QuestionSyncService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class QuestionExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,string):mixed */
    private readonly Closure $sync;

    /** @param null|callable(WorkEnvelope,string):mixed $sync */
    public function __construct(?callable $sync = null)
    {
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static fn (WorkEnvelope $work, string $id): int =>
                (new QuestionSyncService())->syncQuestionById($work->meliAccountId, $id);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $id = trim((string) ($work->payload['question_id'] ?? ''));
        if ($id === '' || preg_match('/^[0-9]+$/', $id) !== 1) {
            throw new InvalidArgumentException('question_exact requiere question_id numérico.');
        }
        $localId = $context->logicalRemoteCall(fn (): mixed => ($this->sync)($work, $id));

        return WorkResult::completed([
            'question_id' => $id,
            'local_id' => is_numeric($localId) ? (int) $localId : null,
        ]);
    }
}
