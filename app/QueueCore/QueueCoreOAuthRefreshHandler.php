<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\ApiExecutionMetadataContext;
use App\Services\MeliApiClient;
use App\Services\MeliApiException;
use App\Services\OAuthRefreshBusyException;
use App\Services\RotatedCredentialRecoveryUnavailableException;
use Closure;
use PDO;
use Throwable;

final class QueueCoreOAuthRefreshHandler implements QueueHandler
{
    /** @var Closure(int):array<string,mixed> */
    private readonly Closure $refresh;

    /** @param null|callable(int):array<string,mixed> $refresh */
    public function __construct(?callable $refresh = null, private readonly ?PDO $pdo = null)
    {
        $this->refresh = $refresh !== null
            ? Closure::fromCallable($refresh)
            : static fn (int $accountId): array => (new MeliApiClient($accountId))->refreshOAuthToken();
    }

    public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        $expectedIdentity = trim((string) ($job->payload['expected_meli_user_id'] ?? ''));
        $expectedVersion = max(0, (int) ($job->payload['expected_refresh_version'] ?? -1));
        $pdo = $this->pdo ?? Database::connectionFresh();
        $statement = $pdo->prepare(
            "SELECT a.meli_user_id,a.status,COALESCE(t.refresh_version,0) AS refresh_version
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.id=? AND a.company_id=? LIMIT 1"
        );
        $statement->execute([$job->meliAccountId, $job->companyId]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source) || $expectedIdentity === ''
            || !hash_equals($expectedIdentity, trim((string) ($source['meli_user_id'] ?? '')))) {
            return QueueResult::review('oauth_source_identity_changed');
        }
        if (!in_array((string) ($source['status'] ?? ''), ['conectado', 'connected'], true)) {
            return QueueResult::review('oauth_account_not_connected');
        }
        if ((int) ($source['refresh_version'] ?? 0) < $expectedVersion) {
            return QueueResult::review('oauth_refresh_version_regressed');
        }
        if (!$context->hasTime(2.0)) {
            return QueueResult::retry('deadline_deferred', gmdate('Y-m-d H:i:s', time() + 30));
        }

        try {
            ApiExecutionMetadataContext::withTransportMetadata([
                'transport_meli_account_id' => $job->meliAccountId,
                'expected_meli_user_id' => $expectedIdentity,
                'queue_core_oauth_refresh' => 1,
                'job_type' => 'oauth_refresh',
            ], fn (): array => ($this->refresh)($job->meliAccountId));
            return QueueResult::completed(1);
        } catch (OAuthRefreshBusyException) {
            return QueueResult::retry('oauth_refresh_busy', gmdate('Y-m-d H:i:s', time() + 5));
        } catch (RotatedCredentialRecoveryUnavailableException) {
            return QueueResult::review('rotated_credential_recovery_unavailable');
        } catch (MeliApiException $error) {
            $invalidGrant = strtolower((string) ($error->response['error'] ?? '')) === 'invalid_grant'
                || str_contains(strtolower($error->getMessage()), 'invalid_grant');
            if ($invalidGrant) {
                $this->markReconnectRequired($pdo, $job->companyId, $job->meliAccountId);
            }
            return $invalidGrant
                ? QueueResult::review('oauth_invalid_grant', $error->httpStatus)
                : QueueResult::retry('oauth_remote_failure', gmdate('Y-m-d H:i:s', time() + 60), $error->httpStatus);
        } catch (Throwable) {
            return QueueResult::retry('oauth_local_failure', gmdate('Y-m-d H:i:s', time() + 30));
        }
    }

    private function markReconnectRequired(PDO $pdo, int $companyId, int $accountId): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare(
                'SELECT id FROM meli_accounts WHERE id=? AND company_id=? FOR UPDATE'
            );
            $lock->execute([$accountId, $companyId]);
            if ((int) $lock->fetchColumn() === $accountId) {
                $update = $pdo->prepare(
                    "UPDATE meli_accounts
                     SET status='vencido',last_error=?
                     WHERE id=? AND company_id=?"
                );
                $update->execute([
                    'La autorización de Mercado Libre venció; conecte nuevamente la cuenta.',
                    $accountId,
                    $companyId,
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
