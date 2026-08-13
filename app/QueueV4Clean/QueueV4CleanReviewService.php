<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

/** Read model forense y recuperación exacta; nunca interpreta un error genérico como 429. */
final class QueueV4CleanReviewService
{
    private const RECOVERABLE = [
        'apirhythmdeferredexception',
        'apibudgetexhaustedexception',
        'crondeadlinedeferredexception',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $rows = $this->pdo->query(
            "SELECT j.company_id,j.meli_account_id,a.account_name,j.job_type,j.last_error_class,
                    COUNT(*) row_count,MIN(j.updated_at) oldest,MAX(j.updated_at) newest
             FROM queue_v4_clean_jobs j
             INNER JOIN meli_accounts a
               ON a.company_id=j.company_id AND a.id=j.meli_account_id
             WHERE j.state='review'
             GROUP BY j.company_id,j.meli_account_id,a.account_name,j.job_type,j.last_error_class
             ORDER BY j.company_id,j.meli_account_id,j.job_type,j.last_error_class"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byClass = $byType = $byAccount = [];
        $oldest = $newest = null;
        $recoverable = $functional = $ambiguous = 0;
        foreach ($rows as $row) {
            $count = max(0, (int) $row['row_count']);
            $error = (string) ($row['last_error_class'] ?? 'unknown');
            $classification = $this->classification($error);
            $byClass[$classification . ':' . $error] = ($byClass[$classification . ':' . $error] ?? 0) + $count;
            $byType[(string) $row['job_type']] = ($byType[(string) $row['job_type']] ?? 0) + $count;
            $accountKey = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'] . ':' . (string) $row['account_name'];
            $byAccount[$accountKey] = ($byAccount[$accountKey] ?? 0) + $count;
            $oldest = $oldest === null || (string) $row['oldest'] < $oldest ? (string) $row['oldest'] : $oldest;
            $newest = $newest === null || (string) $row['newest'] > $newest ? (string) $row['newest'] : $newest;
            match ($classification) {
                'NON_FAILURE_TECHNICAL' => $recoverable += $count,
                'FUNCTIONAL_ERROR' => $functional += $count,
                default => $ambiguous += $count,
            };
        }
        ksort($byClass);
        ksort($byType);
        ksort($byAccount);
        return [
            'total' => array_sum($byClass),
            'by_failure_class' => $byClass,
            'by_job_type' => $byType,
            'by_account' => $byAccount,
            'oldest' => $oldest,
            'newest' => $newest,
            'recoverable_count' => $recoverable,
            'functional_count' => $functional,
            'ambiguous_count' => $ambiguous,
        ];
    }

    /**
     * Recupera un único job exacto. Sólo acepta intentos cuya evidencia
     * completa sea un aplazamiento no-fallido conocido.
     *
     * @return array<string,mixed>
     */
    public function recoverExact(int $companyId, int $accountId, int $jobId, string $nextSafeAt): array
    {
        if ($companyId < 1 || $accountId < 1 || $jobId < 1) {
            throw new RuntimeException('queue_v4_review_recovery_identity_invalid');
        }
        $timestamp = strtotime(trim($nextSafeAt) . ' UTC');
        if ($timestamp === false || $timestamp < time()) {
            throw new RuntimeException('queue_v4_review_recovery_next_safe_at_invalid');
        }
        $this->pdo->beginTransaction();
        try {
            $jobStmt = $this->pdo->prepare(
                "SELECT j.id,j.state,j.attempt_count,j.last_error_class,j.available_at
                 FROM queue_v4_clean_jobs j
                 INNER JOIN meli_accounts a
                   ON a.company_id=j.company_id AND a.id=j.meli_account_id
                 WHERE j.id=? AND j.company_id=? AND j.meli_account_id=?
                   AND j.state IN ('review','waiting')
                 FOR UPDATE"
            );
            $jobStmt->execute([$jobId, $companyId, $accountId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($job)
                && (string)$job['state'] === 'waiting'
                && hash_equals('capacity_recovery', (string)$job['last_error_class'])) {
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'company_id' => $companyId,
                    'meli_account_id' => $accountId,
                    'job_id' => $jobId,
                    'state' => 'waiting',
                    'next_safe_at' => (string)$job['available_at'],
                    'idempotent_replay' => true,
                ];
            }
            if (!is_array($job) || $this->classification((string) $job['last_error_class']) !== 'NON_FAILURE_TECHNICAL') {
                throw new RuntimeException('queue_v4_review_recovery_not_proven');
            }
            $attemptStmt = $this->pdo->prepare(
                'SELECT error_class FROM queue_v4_clean_attempts
                 WHERE job_id=? AND company_id=? AND meli_account_id=? ORDER BY id FOR UPDATE'
            );
            $attemptStmt->execute([$jobId, $companyId, $accountId]);
            $attempts = $attemptStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($attempts === [] || count($attempts) !== (int) $job['attempt_count']) {
                throw new RuntimeException('queue_v4_review_recovery_attempt_authority_incomplete');
            }
            foreach ($attempts as $errorClass) {
                if ($this->classification((string) $errorClass) !== 'NON_FAILURE_TECHNICAL') {
                    throw new RuntimeException('queue_v4_review_recovery_attempt_mixed');
                }
            }
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='waiting',attempt_count=0,available_at=?,last_error_class='capacity_recovery',
                     lease_owner=NULL,lease_expires_at=NULL,completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='review'
                   AND attempt_count=?"
            );
            $update->execute([gmdate('Y-m-d H:i:s', $timestamp), $jobId, $companyId, $accountId, (int) $job['attempt_count']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_review_recovery_cas_lost');
            }
            $this->pdo->commit();
            return [
                'ok' => true,
                'company_id' => $companyId,
                'meli_account_id' => $accountId,
                'job_id' => $jobId,
                'state' => 'waiting',
                'next_safe_at' => gmdate('Y-m-d H:i:s', $timestamp),
                'idempotent_replay' => false,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function classification(string $errorClass): string
    {
        $normalized = strtolower(trim($errorClass));
        if (str_starts_with($normalized, 'rate_limit_deferred:')
            || str_starts_with($normalized, 'capacity_deferred:')
            || in_array($normalized, self::RECOVERABLE, true)) {
            return 'NON_FAILURE_TECHNICAL';
        }
        if ($normalized === '' || $normalized === 'meliapiexception' || $normalized === 'unknown') {
            return 'AMBIGUOUS';
        }
        return 'FUNCTIONAL_ERROR';
    }
}
