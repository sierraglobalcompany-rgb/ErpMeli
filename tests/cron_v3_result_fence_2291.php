<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runner = (string) file_get_contents($root . '/app/Services/CronV3Runner.php');
$repository = (string) file_get_contents($root . '/app/Services/CronV3WorkRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$finalizePosition = strpos($runner, '$this->repository->finalize(');
$recordPosition = strpos($runner, '$this->rateGate->recordResult(');
$assert($finalizePosition !== false && $recordPosition !== false && $finalizePosition < $recordPosition,
    'Rate/circuit recording must be a callback inside fenced finalization.');
$assert(str_contains($runner, 'fn (): bool => $this->rateGate->recordResult('),
    'Rate/circuit effects are not attached to the fenced transaction.');

$lastCatch = strrpos($runner, '} catch (');
$assert($lastCatch !== false && $lastCatch < $finalizePosition, 'An exception path bypasses common fenced finalization.');
foreach (['completed', 'deferred', 'review', 'dead'] as $status) {
    $assert(str_contains($runner, 'WorkResult::' . $status . '('), $status . ' has no result path through the common fence.');
}
foreach ([
    'CronDeadlineDeferredException',
    'CronV3RateLimitedException',
    'OAuthRefreshRequiredException',
    'ApiRhythmDeferredException',
    'ApiBudgetExhaustedException',
    'RemoteResultUncertainException',
    'ManualRemoteCallLimitException',
    'MeliApiException',
    'Throwable',
] as $exception) {
    $assert(
        str_contains(substr($runner, 0, $finalizePosition), 'catch (' . $exception),
        $exception . ' does not converge on common fenced finalization.'
    );
}

$fence = 'AND status="leased" AND owner_token=? AND lease_generation=?';
$assert(str_contains($repository, $fence), 'Repository finalization is not fenced by status, token and generation.');
$assert(str_contains($repository, '$wonFence && $afterFence !== null && $afterFence() !== true'),
    'Repository does not persist side effects inside the winning fenced transaction.');
$assert(!str_contains($repository, 'SKIP LOCKED'), 'MariaDB 10.4 does not support SKIP LOCKED.');
$commitPosition = strpos($repository, '$this->pdo->commit();', strpos($repository, 'public function finalize('));
$returnPosition = strpos($repository, 'return $wonFence;', strpos($repository, 'public function finalize('));
$assert(
    $commitPosition !== false && $returnPosition !== false && $commitPosition < $returnPosition,
    'Repository must commit the fenced transition before confirming ownership to the runner.'
);

echo 'PASS cron_v3_result_fence_2291' . PHP_EOL;
