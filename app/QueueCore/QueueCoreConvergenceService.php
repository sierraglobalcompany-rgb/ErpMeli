<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;

/**
 * Convergencia derivada exclusivamente del journal V4 y de la persistencia
 * local. No acepta una lista de identidades suministrada por el operador.
 */
final class QueueCoreConvergenceService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function compare(
        int $companyId,
        int $accountId,
        string $fromUtc,
        string $toUtc,
        int $limit = 500,
    ): array {
        $limit = max(1, min(1000, $limit));
        $from = strtotime($fromUtc . ' UTC');
        $to = strtotime($toUtc . ' UTC');
        if ($companyId < 1 || $accountId < 1 || $from === false || $to === false || $from > $to
            || !$this->accountBelongsToCompany($companyId, $accountId)) {
            throw new RuntimeException('Queue Core convergence scope or window is invalid.');
        }
        $attempts = $this->pdo->prepare(
            "SELECT j.payload_json,a.resources_discovered,a.http_status
             FROM queue_core_attempts a
             JOIN queue_core_jobs j ON j.id=a.job_id
               AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
             WHERE a.company_id=? AND a.meli_account_id=?
               AND j.work_type='fresh_orders_discovery'
               AND a.physical_http_calls=1 AND a.response_known_at IS NOT NULL
               AND a.source_closed_at IS NOT NULL AND a.outcome='completed'
               AND a.http_status BETWEEN 200 AND 299
             ORDER BY a.id ASC LIMIT " . ($limit + 1)
        );
        $attempts->execute([$companyId, $accountId]);
        $rows = $attempts->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            throw new RuntimeException('Queue Core convergence evidence exceeds its bounded limit.');
        }
        $windows = [];
        $discovered = 0;
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload)) {
                continue;
            }
            $windowFrom = strtotime((string) ($payload['from'] ?? '') . ' UTC');
            $windowTo = strtotime((string) ($payload['to'] ?? '') . ' UTC');
            if ($windowFrom === false || $windowTo === false || $windowTo < $from || $windowFrom > $to) {
                continue;
            }
            $windows[] = [max($from, $windowFrom), min($to, $windowTo)];
            $discovered += max(0, (int) $row['resources_discovered']);
        }
        $coverage = $this->continuousCoverage($windows, $from, $to);
        $unresolved = $this->unresolvedExactCount($companyId, $accountId);
        $local = $this->localOrderCount($companyId, $accountId, $fromUtc, $toUtc, $limit);
        $emptyWindow = $coverage && $discovered === 0;
        $passed = $coverage && $unresolved === 0 && ($emptyWindow || $local >= $discovered);
        return [
            'ok' => $passed,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'window_from_utc' => gmdate('Y-m-d H:i:s', $from),
            'window_to_utc' => gmdate('Y-m-d H:i:s', $to),
            'authoritative_page_count' => count($windows),
            'remote_identity_count' => $discovered,
            'local_identity_count' => $local,
            'unresolved_exact_count' => $unresolved,
            'continuous_coverage' => $coverage,
            'authoritative_empty_window' => $emptyWindow,
            'remote_http_calls' => 0,
            'business_db_writes' => 0,
        ];
    }

    /** @param list<array{0:int,1:int}> $windows */
    private function continuousCoverage(array $windows, int $from, int $to): bool
    {
        if ($windows === []) {
            return false;
        }
        usort($windows, static fn(array $left,array $right):int=>$left[0]<=>$right[0]);
        $covered = $from;
        foreach ($windows as [$start,$end]) {
            if ($start > $covered + 1) {
                return false;
            }
            $covered = max($covered, $end);
            if ($covered >= $to) {
                return true;
            }
        }
        return false;
    }

    private function unresolvedExactCount(int $companyId,int $accountId): int
    {
        $statement=$this->pdo->prepare(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE company_id=? AND meli_account_id=?
               AND work_type IN ('order_exact','webhook_order_exact')
               AND state IN ('pending','claimed','running','retry_wait','waiting_oauth','review','dead')"
        );
        $statement->execute([$companyId,$accountId]);
        return max(0,(int)$statement->fetchColumn());
    }

    private function localOrderCount(int $companyId,int $accountId,string $fromUtc,string $toUtc,int $limit): int
    {
        $dateColumn=$this->orderDateColumn();
        $statement=$this->pdo->prepare(
            'SELECT COUNT(*) FROM (SELECT o.id FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.`'.$dateColumn.'` BETWEEN ? AND ?
             LIMIT '.($limit+1).') bounded_orders'
        );
        $statement->execute([$companyId,$accountId,$fromUtc,$toUtc]);
        $count=(int)$statement->fetchColumn();
        if($count>$limit)throw new RuntimeException('Queue Core local convergence window exceeds its bounded limit.');
        return max(0,$count);
    }

    private function orderDateColumn(): string
    {
        foreach(['date_created_ml','date_created_utc','date_created'] as $candidate){
            $statement=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="meli_orders" AND column_name=?');
            $statement->execute([$candidate]);
            if((int)$statement->fetchColumn()===1)return $candidate;
        }
        throw new RuntimeException('Queue Core convergence order timestamp authority is unavailable.');
    }

    private function accountBelongsToCompany(int $companyId,int $accountId): bool
    {
        $statement=$this->pdo->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND status IN ('conectado','connected')");
        $statement->execute([$accountId,$companyId]);
        return (int)$statement->fetchColumn()===1;
    }
}
