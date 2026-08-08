<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\MeliApiClient;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;

final class OAuthRefreshHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope):mixed */
    private readonly Closure $refresh;

    /** @param null|callable(WorkEnvelope):mixed $refresh */
    public function __construct(?callable $refresh = null)
    {
        $this->refresh = $refresh !== null
            ? Closure::fromCallable($refresh)
            : static fn (WorkEnvelope $work): array =>
                (new MeliApiClient($work->meliAccountId))->refreshOAuthToken();
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $context->logicalRemoteCall(fn (): mixed => ($this->refresh)($work));

        return WorkResult::completed([
            'account_id' => $work->meliAccountId,
            'refreshed' => true,
        ]);
    }
}
