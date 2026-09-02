<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliCliRuntimeCapabilityService;
use App\Services\MeliApiException;
use App\Services\OAuthRefreshBusyException;
use App\Services\QueueOAuthDurableRecoveryStore;
use App\Services\RemoteResultUncertainException;
use App\Services\RotatedCredentialRecoveryUnavailableException;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanOAuthSupervisor
{
    public const MAX_PHYSICAL_POSTS_PER_RUN = 1;
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private \Closure $refresh;
    private readonly MeliCliRuntimeCapabilityService $runtimeCapabilities;
    private readonly QueueV4CleanSafeDiagnosticService $diagnostics;

    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueV4CleanOAuthOperationRepository $operations,
        private readonly AppSettingsService $settings = new AppSettingsService(),
        ?callable $refresh = null,
        ?callable $clientFactory = null,
        ?MeliCliRuntimeCapabilityService $runtimeCapabilities = null,
        ?QueueV4CleanSafeDiagnosticService $diagnostics = null,
    ) {
        $factory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId);
        $this->refresh = $refresh !== null
            ? \Closure::fromCallable($refresh)
            : static fn (array $operation): array => $factory((int) $operation['meli_account_id'])->refreshOAuthToken();
        $this->runtimeCapabilities = $runtimeCapabilities ?? new MeliCliRuntimeCapabilityService();
        $this->diagnostics = $diagnostics ?? new QueueV4CleanSafeDiagnosticService();
    }

    /** @return array<string,mixed> */
    public function run(string $schedulerOwner): array
    {
        QueueV4CleanOAuthStageContext::reset();
        $summary = ['scheduled' => 0, 'claimed' => 0, 'completed' => 0, 'waiting' => 0, 'uncertain' => 0, 'reconnect' => 0, 'failed' => 0, 'physical_posts' => 0, 'physical_posts_known' => true, 'abort_scheduler' => false];
        try {
            QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_OPERATION_REPAIR);
            $this->reconcileStaleEscrows();
            $this->operations->repairStale();
            QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_RUNTIME_PREFLIGHT);
            $capabilities = $this->runtimeCapabilities->inspect();
            if (!$this->runtimeCapabilities->oauthReady($capabilities)) {
                $diagnostic = $this->diagnostics->capture(
                    new RuntimeException('queue_v4_clean_oauth_runtime_capability_missing'),
                    QueueV4CleanOAuthStageContext::OAUTH_RUNTIME_PREFLIGHT,
                    'QUEUE_V4_CLEAN_OAUTH_RUNTIME_BLOCKED'
                );
                return array_replace($summary, [
                    'status' => 'oauth_control_plane_blocked',
                    'abort_scheduler' => true,
                    'diagnostic' => $diagnostic,
                    'runtime_capabilities' => $capabilities,
                ]);
            }
            $summary['scheduled'] = $this->scheduleDue();
            $owner = bin2hex(random_bytes(16));
            QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_OPERATION_CLAIM);
            $operation = $this->operations->claimOne($owner);
        } catch (Throwable $error) {
            return array_replace($summary, [
                'status' => 'oauth_control_plane_blocked',
                'abort_scheduler' => true,
                'diagnostic' => $this->diagnostics->capture(
                    $error,
                    QueueV4CleanOAuthStageContext::current(),
                    'QUEUE_V4_CLEAN_OAUTH_PRECLAIM_FAILED'
                ),
            ]);
        }
        if ($operation === null) {
            return $summary;
        }
        $summary['claimed'] = 1;
        $before = null;
        try {
            QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_OPERATION_CLAIMED);
            $context = [
                'source' => 'queue_v4_clean_oauth',
                'job_type' => 'oauth',
                'company_id' => (int) $operation['company_id'],
                'account_id' => (int) $operation['meli_account_id'],
                'expected_meli_user_id' => (string) $operation['expected_meli_user_id'],
                'expected_refresh_version' => (int) $operation['expected_refresh_version'],
                'oauth_operation_id' => (int) $operation['id'],
                'oauth_lease_owner' => (string) $operation['lease_owner'],
                'oauth_lease_generation' => (int) $operation['lease_generation'],
                'scheduler_lease_owner' => $schedulerOwner,
            ];
            $before = $this->remoteAttemptCount($operation);
            try {
                QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_REFRESH_SERVICE);
                $token = ApiExecutionMetadataContext::run(
                    $context,
                    fn (): array => ($this->refresh)($operation)
                );
                $currentVersion = (int) ($token['refresh_version'] ?? -1);
                $expectedVersion = (int) $operation['expected_refresh_version'];
                if ($currentVersion > $expectedVersion) {
                    $this->operations->complete($operation, 'refresh_completed');
                    $summary['completed'] = 1;
                } elseif ($currentVersion === $expectedVersion) {
                    $this->operations->wait(
                        $operation,
                        'oauth_not_due_after_recheck',
                        $this->nextDueAt((string) ($token['expires_at'] ?? '')),
                    );
                    $summary['waiting'] = 1;
                } else {
                    throw new RuntimeException('queue_v4_clean_oauth_refresh_version_regressed');
                }
            } catch (ApiRhythmDeferredException $error) {
                $fence = QueueV4CleanOAuthDispatchFence::state($operation);
                if ($error->reachedRemote
                    && !($fence['dispatch_state'] === 'RESPONSE_KNOWN' && $fence['http_status'] === 429)) {
                    $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'oauth_remote_non_429_deferred');
                    $summary['uncertain'] = 1;
                } else {
                    $this->operations->wait($operation, $this->failureClass($error), $error->nextSafeAt);
                    $summary['waiting'] = 1;
                }
            } catch (ApiBudgetExhaustedException|CronDeadlineDeferredException $error) {
                $this->operations->wait($operation, $this->failureClass($error), $error->nextSafeAt);
                $summary['waiting'] = 1;
            } catch (OAuthRefreshBusyException $error) {
                $this->operations->wait($operation, $this->failureClass($error), gmdate('Y-m-d H:i:s', time() + 60));
                $summary['waiting'] = 1;
            } catch (RemoteResultUncertainException|RotatedCredentialRecoveryUnavailableException $error) {
                $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', $this->failureClass($error));
                $summary['uncertain'] = 1;
            } catch (MeliApiException $error) {
                if ($error->httpStatus === 429) {
                    $this->operations->wait($operation, 'http_429', $this->knownRateLimitNextAttemptAt());
                    $summary['waiting'] = 1;
                } elseif (($error->httpStatus ?? 0) >= 500) {
                    $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'http_5xx');
                    $summary['uncertain'] = 1;
                } elseif ($this->invalidGrant($error)) {
                    $this->operations->terminal($operation, 'RECONNECT_REQUIRED', 'invalid_grant');
                    $summary['reconnect'] = 1;
                } else {
                    $this->operations->terminal($operation, 'FAILED', 'oauth_http_failure');
                    $summary['failed'] = 1;
                }
            }
            $after = $this->remoteAttemptCount($operation);
            $summary['physical_posts'] = max(0, $after - $before);
            if ($summary['physical_posts'] > self::MAX_PHYSICAL_POSTS_PER_RUN) {
                throw new RuntimeException('queue_v4_clean_oauth_physical_post_limit_exceeded');
            }
        } catch (Throwable $error) {
            return $this->containUnexpected($operation, $summary, $before, $error);
        }
        return $summary;
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $summary @return array<string,mixed> */
    private function containUnexpected(array $operation, array $summary, ?int $before, Throwable $error): array
    {
        $diagnostic = $this->diagnostics->capture($error);
        $fencePosts = null;
        try {
            $fence = QueueV4CleanOAuthDispatchFence::state($operation);
            $state = (string) $fence['dispatch_state'];
            $status = $fence['http_status'];
            $fencePosts = $state === 'NOT_DISPATCHED'
                ? 0
                : (in_array($state, ['MAY_HAVE_DISPATCHED', 'RESPONSE_KNOWN'], true) ? 1 : null);
            $errorClass = $this->failureClass($error);
            if ($state === 'NOT_DISPATCHED') {
                $this->operations->wait($operation, $errorClass, gmdate('Y-m-d H:i:s', time() + 60));
                $summary['waiting'] = 1;
            } elseif ($state === 'MAY_HAVE_DISPATCHED') {
                $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', $errorClass);
                $summary['uncertain'] = 1;
            } elseif ($state === 'RESPONSE_KNOWN' && $status === 429) {
                $this->operations->wait($operation, 'http_429', $this->knownRateLimitNextAttemptAt());
                $summary['waiting'] = 1;
            } elseif ($state === 'RESPONSE_KNOWN' && $status !== null && $status >= 200 && $status < 300) {
                if (!$this->reconcileKnownSuccess($operation, $summary)) {
                    $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'known_2xx_without_local_authority');
                    $summary['uncertain'] = 1;
                }
            } elseif ($state === 'RESPONSE_KNOWN' && $status !== null && $status >= 400 && $status < 500) {
                $this->operations->terminal($operation, 'FAILED', 'oauth_known_http_failure');
                $summary['failed'] = 1;
            } elseif ($state === 'RESPONSE_KNOWN') {
                $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'oauth_known_remote_failure');
                $summary['uncertain'] = 1;
            } else {
                throw new RuntimeException('queue_v4_clean_oauth_fence_state_unreadable');
            }
        } catch (Throwable $containmentError) {
            $containmentDiagnostic = $this->diagnostics->capture(
                $containmentError,
                QueueV4CleanOAuthStageContext::current(),
                'OAUTH_CONTAINMENT_FAILED'
            );
            $summary['abort_scheduler'] = true;
            $summary['status'] = 'oauth_containment_failed';
            $summary['diagnostic'] = $diagnostic;
            $summary['containment_diagnostic'] = $containmentDiagnostic;
            $this->recordContainedPhysicalPosts($summary, $operation, $before, $fencePosts);
            return $summary;
        }

        try {
            $this->recordContainedPhysicalPosts($summary, $operation, $before, $fencePosts);
        } catch (Throwable $countError) {
            $summary['abort_scheduler'] = true;
            $summary['status'] = 'oauth_containment_failed';
            $summary['physical_posts'] = null;
            $summary['physical_posts_known'] = false;
            $summary['diagnostic'] = $diagnostic;
            $summary['containment_diagnostic'] = $this->diagnostics->capture(
                $countError,
                QueueV4CleanOAuthStageContext::current(),
                'OAUTH_CONTAINMENT_FAILED'
            );
            return $summary;
        }
        $summary['abort_scheduler'] = true;
        $summary['status'] = 'oauth_unexpected_contained';
        $summary['diagnostic'] = $diagnostic;
        return $summary;
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $operation */
    private function recordContainedPhysicalPosts(
        array &$summary,
        array $operation,
        ?int $before,
        ?int $fencePosts,
    ): void {
        if ($before !== null) {
            try {
                $after = $this->remoteAttemptCount($operation);
                $summary['physical_posts'] = max(0, $after - $before);
                $summary['physical_posts_known'] = true;
                return;
            } catch (Throwable) {
                // The persisted dispatch fence below is the independent
                // authority when the counter cannot be re-read.
            }
        }
        if ($fencePosts !== null) {
            $summary['physical_posts'] = $fencePosts;
            $summary['physical_posts_known'] = true;
            return;
        }
        $summary['physical_posts'] = null;
        $summary['physical_posts_known'] = false;
    }

    private function scheduleDue(): int
    {
        $lead = max(300, min(7200, $this->settings->int('oauth.auto_refresh_lead_seconds', 3600)));
        $statement = $this->pdo->prepare(
            "SELECT ra.company_id,ra.meli_account_id,a.meli_user_id,a.status,
                    t.refresh_version,t.refresh_token_encrypted,t.expires_at
             FROM queue_v4_clean_readiness_accounts ra
             INNER JOIN queue_v4_clean_readiness_runs rr
               ON rr.id=ra.readiness_run_id AND rr.state='CERTIFIED'
             INNER JOIN meli_accounts a ON a.company_id=ra.company_id AND a.id=ra.meli_account_id
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE ra.readiness_run_id=(SELECT MAX(id) FROM queue_v4_clean_readiness_runs WHERE state='CERTIFIED')
               AND ra.outcome='PASS'
             ORDER BY a.company_id,a.id"
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 3) {
            throw new RuntimeException('queue_v4_clean_oauth_certified_account_set_invalid');
        }
        $scheduled = 0;
        foreach ($rows as $row) {
            $expiry = strtotime((string) ($row['expires_at'] ?? '') . ' UTC');
            if (!in_array((string) $row['status'], ['conectado', 'connected'], true)
                || trim((string) $row['meli_user_id']) === ''
                || trim((string) ($row['refresh_token_encrypted'] ?? '')) === ''
                || $expiry === false || $expiry > time() + $lead) {
                continue;
            }
            $this->operations->schedule(
                (int) $row['company_id'],
                (int) $row['meli_account_id'],
                (string) $row['meli_user_id'],
                (int) $row['refresh_version'],
            );
            $scheduled++;
        }
        return $scheduled;
    }

    private function reconcileStaleEscrows(): void
    {
        $rows = $this->pdo->query(
            "SELECT o.*,t.refresh_version
             FROM oauth_refresh_operations o
             INNER JOIN meli_accounts a ON a.company_id=o.company_id AND a.id=o.meli_account_id
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE o.state='RUNNING' AND o.lease_expires_at<UTC_TIMESTAMP(3)
               AND o.remote_dispatch_state IN ('MAY_HAVE_DISPATCHED','RESPONSE_KNOWN')
             ORDER BY o.id LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);
        $store = new QueueOAuthDurableRecoveryStore();
        foreach ($rows as $row) {
            $currentVersion = (int) $row['refresh_version'];
            $expected = (int) $row['expected_refresh_version'];
            if ($currentVersion > $expected) {
                $completed = $this->pdo->prepare(
                    "UPDATE oauth_refresh_operations SET state='COMPLETED',lease_owner=NULL,lease_expires_at=NULL,
                            last_error_class='refresh_version_already_advanced',completed_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='RUNNING'"
                );
                $completed->execute([(int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id']]);
                if ($completed->rowCount() === 1) {
                    $this->clearCommittedEscrowBestEffort((int) $row['meli_account_id'], $expected + 1);
                }
                continue;
            }
            if ((string) $row['remote_dispatch_state'] === 'RESPONSE_KNOWN'
                && (int) ($row['last_http_status'] ?? 0) === 429) {
                continue;
            }
            try {
                $escrow = $store->load(
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    (string) $row['expected_meli_user_id'],
                );
            } catch (RuntimeException) {
                $this->terminalizeStaleEscrowMismatch($row);
                continue;
            }
            if ($escrow !== null
                && ((int) ($escrow['previous_refresh_version'] ?? -1) !== $expected
                    || (int) ($escrow['target_refresh_version'] ?? -1) !== $expected + 1)) {
                $this->terminalizeStaleEscrowMismatch($row);
                continue;
            }
            if ($escrow !== null) {
                $this->pdo->prepare(
                    "UPDATE oauth_refresh_operations SET state='WAITING',next_attempt_at=UTC_TIMESTAMP(3),
                            remote_dispatch_state='NOT_DISPATCHED',lease_owner=NULL,lease_expires_at=NULL,
                            last_error_class='durable_recovery_pending'
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='RUNNING'"
                )->execute([(int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id']]);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function terminalizeStaleEscrowMismatch(array $row): void
    {
        $this->pdo->prepare(
            "UPDATE oauth_refresh_operations
             SET state='REMOTE_UNCERTAIN',lease_owner=NULL,lease_expires_at=NULL,
                 last_error_class='oauth_escrow_mismatch',completed_at=UTC_TIMESTAMP(3)
             WHERE id=? AND company_id=? AND meli_account_id=? AND state='RUNNING'"
        )->execute([(int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id']]);
    }

    private function remoteAttemptCount(array $operation): int
    {
        $statement = $this->pdo->prepare(
            'SELECT remote_attempt_count FROM oauth_refresh_operations WHERE id=? AND company_id=? AND meli_account_id=?'
        );
        $statement->execute([(int) $operation['id'], (int) $operation['company_id'], (int) $operation['meli_account_id']]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,int> $summary */
    private function reconcileKnownSuccess(array $operation, array &$summary): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT t.refresh_version
             FROM meli_tokens t
             INNER JOIN meli_accounts a ON a.id=t.meli_account_id
             WHERE a.company_id=? AND a.id=? AND a.meli_user_id=? LIMIT 1'
        );
        $statement->execute([
            (int) $operation['company_id'],
            (int) $operation['meli_account_id'],
            (string) $operation['expected_meli_user_id'],
        ]);
        $current = $statement->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('queue_v4_clean_oauth_known_success_identity_lost');
        }
        $expected = (int) $operation['expected_refresh_version'];
        if ((int) $current > $expected) {
            $this->operations->complete($operation, 'refresh_version_already_advanced');
            $this->clearCommittedEscrowBestEffort((int) $operation['meli_account_id'], $expected + 1);
            $summary['completed'] = 1;
            return true;
        }
        try {
            $escrow = (new QueueOAuthDurableRecoveryStore())->load(
                (int) $operation['company_id'],
                (int) $operation['meli_account_id'],
                (string) $operation['expected_meli_user_id'],
            );
        } catch (RuntimeException) {
            $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'oauth_escrow_mismatch');
            $summary['uncertain'] = 1;
            return true;
        }
        if ($escrow === null) {
            return false;
        }
        if ((int) ($escrow['previous_refresh_version'] ?? -1) !== $expected
            || (int) ($escrow['target_refresh_version'] ?? -1) !== $expected + 1) {
            $this->operations->terminal($operation, 'REMOTE_UNCERTAIN', 'oauth_escrow_generation_mismatch');
            $summary['uncertain'] = 1;
            return true;
        }
        $this->operations->waitForDurableRecovery($operation);
        $summary['waiting'] = 1;
        return true;
    }

    private function clearCommittedEscrowBestEffort(int $accountId, int $targetVersion): void
    {
        try {
            (new QueueOAuthDurableRecoveryStore())->clear($accountId, $targetVersion);
        } catch (Throwable $error) {
            $this->diagnostics->capture(
                $error,
                QueueV4CleanOAuthStageContext::TOKEN_ESCROW,
                'QUEUE_V4_OAUTH_ESCROW_CLEANUP_DEFERRED'
            );
        }
    }

    private function knownRateLimitNextAttemptAt(): string
    {
        return (new ApiRhythmPolicyService())->conservativeRateLimitNextSafeAt();
    }

    private function failureClass(Throwable $error): string
    {
        return substr(strtolower((new \ReflectionClass($error))->getShortName()), 0, 100);
    }

    private function invalidGrant(MeliApiException $error): bool
    {
        return strtolower((string) ($error->response['error'] ?? '')) === 'invalid_grant';
    }

    private function nextDueAt(string $expiresAt): string
    {
        $expiry = strtotime(trim($expiresAt) . ' UTC');
        $lead = max(300, min(7200, $this->settings->int('oauth.auto_refresh_lead_seconds', 3600)));
        $timestamp = $expiry === false ? time() + 60 : max(time() + 1, $expiry - $lead);
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
