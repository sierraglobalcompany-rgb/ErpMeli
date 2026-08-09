<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Services\AppSettingsService;
use PDO;

/** Detecta tokens próximos a vencer y materializa un único trabajo por generación. */
final class QueueCoreOAuthSupervisor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueCoreRepository $repository,
    ) {
    }

    /** @return array{inspected:int,due:int,enqueued:int} */
    public function scheduleDueAccounts(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
        $statement = $this->pdo->prepare(
            "SELECT a.company_id,a.id AS meli_account_id,a.meli_user_id,
                    t.expires_at,COALESCE(t.refresh_version,0) AS refresh_version
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.company_id>0
               AND a.status IN ('conectado','connected')
               AND a.meli_user_id IS NOT NULL AND a.meli_user_id<>''
               AND t.refresh_token_encrypted IS NOT NULL AND t.refresh_token_encrypted<>''
               AND (t.expires_at IS NULL OR t.expires_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))
             ORDER BY COALESCE(t.expires_at,'1970-01-01 00:00:00') ASC,a.id ASC
             LIMIT " . $limit
        );
        $statement->execute([$skew]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $enqueued = 0;
        foreach ($rows as $row) {
            $accountId = (int) $row['meli_account_id'];
            $version = max(0, (int) $row['refresh_version']);
            $jobId = $this->repository->enqueue(new QueueJob(
                (int) $row['company_id'],
                $accountId,
                'oauth_refresh',
                'oauth_account',
                (string) $accountId,
                'recovery',
                0,
                'oauth-refresh:account:' . $accountId,
                'refresh-version:' . $version,
                'queue_core_oauth_supervisor',
                'account:' . $accountId,
                [
                    'expected_meli_user_id' => (string) $row['meli_user_id'],
                    'expected_refresh_version' => $version,
                ],
                ['detected_at' => gmdate(DATE_ATOM)],
                5,
                null,
                'operational',
            ));
            if ($jobId > 0) {
                $created=$this->repository->lastEnqueueCreated();
                // A transiently exhausted refresh is revived in place: the
                // account/version dedupe identity and FIFO position remain
                // stable, while permanent/uncertain OAuth failures stay put.
                $revived=$this->repository->reviveExhaustedTransient(
                    $jobId,
                    'oauth_refresh',
                    'refresh-version:' . $version,
                    (int) $row['company_id'],
                    $accountId,
                );
                if($created||$revived)$enqueued++;
            }
        }
        return ['inspected' => count($rows), 'due' => count($rows), 'enqueued' => $enqueued];
    }
}
