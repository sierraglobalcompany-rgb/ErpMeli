<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;

/**
 * Verificador bounded de identidades; no consulta Mercado Libre ni persiste
 * bodies. La lista remota debe provenir de un transporte fake o de un proceso
 * futuro con presupuesto explícito.
 */
final class QueueCoreConvergenceService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<string|int> $remoteOrderIds
     * @return array<string,mixed>
     */
    public function compare(
        int $companyId,
        int $accountId,
        array $remoteOrderIds,
        string $fromUtc,
        string $toUtc,
        int $limit = 500,
    ): array {
        $limit = max(1, min(1000, $limit));
        if ($companyId < 1 || $accountId < 1 || strtotime($fromUtc . ' UTC') === false
            || strtotime($toUtc . ' UTC') === false || strtotime($fromUtc . ' UTC') > strtotime($toUtc . ' UTC')) {
            throw new RuntimeException('Queue Core convergence scope or window is invalid.');
        }
        $remote = [];
        foreach ($remoteOrderIds as $id) {
            $value = trim((string) $id);
            if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
                throw new RuntimeException('Queue Core convergence received an invalid remote identity.');
            }
            $remote[$value] = true;
            if (count($remote) > $limit) {
                throw new RuntimeException('Queue Core convergence window exceeds its bounded limit.');
            }
        }
        $dateColumn = $this->orderDateColumn();
        $statement = $this->pdo->prepare(
            'SELECT o.external_order_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?
               AND o.' . $dateColumn . ' BETWEEN ? AND ?
             ORDER BY o.external_order_id
             LIMIT ' . ($limit + 1)
        );
        $statement->execute([$companyId, $accountId, $fromUtc, $toUtc]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) > $limit) {
            throw new RuntimeException('Queue Core local convergence window exceeds its bounded limit.');
        }
        $local = [];
        foreach ($rows as $id) {
            $value = trim((string) $id);
            if ($value !== '') {
                $local[$value] = true;
            }
        }
        $missingLocal = array_values(array_diff(array_keys($remote), array_keys($local)));
        $unexpectedLocal = array_values(array_diff(array_keys($local), array_keys($remote)));
        sort($missingLocal, SORT_STRING);
        sort($unexpectedLocal, SORT_STRING);
        $passed = $missingLocal === [];
        return [
            'ok' => $passed,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'window_from_utc' => $fromUtc,
            'window_to_utc' => $toUtc,
            'remote_identity_count' => count($remote),
            'local_identity_count' => count($local),
            'missing_local_count' => count($missingLocal),
            'unexpected_local_count' => count($unexpectedLocal),
            // Evidence contains only irreversible hashes, not order IDs.
            'missing_local_hashes' => array_map(static fn(string $id): string => hash('sha256', $id), $missingLocal),
            'unexpected_local_hashes' => array_map(static fn(string $id): string => hash('sha256', $id), $unexpectedLocal),
            'remote_http_calls' => 0,
            'business_db_writes' => 0,
        ];
    }

    private function orderDateColumn(): string
    {
        foreach (['date_created_utc', 'date_created'] as $candidate) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name="meli_orders" AND column_name=?'
            );
            $statement->execute([$candidate]);
            if ((int) $statement->fetchColumn() === 1) {
                return $candidate;
            }
        }
        throw new RuntimeException('Queue Core convergence order timestamp authority is unavailable.');
    }
}
