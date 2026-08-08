<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class VerifiedSalesCaptureService
{
    /**
     * @return array{valid:bool,state:string,reasons:list<string>,page_count:int,reported_total:?int,unique_total:int,evidence_hash:string}
     */
    public function verifyCoverage(int $runId): array
    {
        $pdo = Database::connectionFresh();
        $runStmt = $pdo->prepare(
            'SELECT id,company_id,meli_account_id FROM sync_sales_audit_runs WHERE id=? LIMIT 1'
        );
        $runStmt->execute([$runId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new \RuntimeException('La captura de ventas ya no existe.');
        }
        $pagesStmt = $pdo->prepare(
            'SELECT page_offset,page_limit,result_count,remote_reported_total,http_status,
                    content_missing_json,ids_hash
             FROM sync_sales_audit_run_pages
             WHERE sync_sales_audit_run_id=?
             ORDER BY page_offset ASC,id ASC'
        );
        $pagesStmt->execute([$runId]);
        $pages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);
        $reasons = [];
        $reportedTotals = [];
        $expectedOffset = 0;
        $hashes = [];
        foreach ($pages as $index => $page) {
            $offset = max(0, (int) $page['page_offset']);
            $count = max(0, (int) $page['result_count']);
            $total = max(0, (int) $page['remote_reported_total']);
            $reportedTotals[$total] = true;
            if ($offset !== $expectedOffset) {
                $reasons[] = 'Existe un hueco de paginación antes del offset ' . $offset . '.';
            }
            $httpStatus = (int) ($page['http_status'] ?? 0);
            if ($httpStatus !== 200 || !empty($page['content_missing_json'])) {
                $reasons[] = 'Mercado Libre entregó una página parcial o con campos ausentes.';
            }
            if ($count > max(1, (int) $page['page_limit'])) {
                $reasons[] = 'Una página contiene más resultados que el límite solicitado.';
            }
            $hash = (string) ($page['ids_hash'] ?? '');
            if ($count > 0 && $hash !== '' && isset($hashes[$hash]) && $hashes[$hash] !== $offset) {
                $reasons[] = 'Se recibió una página de IDs repetida en otro offset.';
            }
            if ($hash !== '') {
                $hashes[$hash] = $offset;
            }
            $isLast = $index === count($pages) - 1;
            if (!$isLast && $count !== (int) $page['page_limit']) {
                $reasons[] = 'Una página intermedia quedó incompleta.';
            }
            $expectedOffset = $offset + $count;
        }
        if ($pages === []) {
            $reasons[] = 'No existe evidencia de páginas consultadas.';
        }
        if (count($reportedTotals) > 1) {
            $reasons[] = 'El total informado por Mercado Libre cambió durante la captura.';
        }
        $reportedTotal = $reportedTotals === [] ? null : (int) array_key_first($reportedTotals);
        $uniqueStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT external_order_id)
             FROM sync_sales_audit_run_orders
             WHERE sync_sales_audit_run_id=? AND meli_account_id=?'
        );
        $uniqueStmt->execute([$runId, (int) $run['meli_account_id']]);
        $uniqueTotal = (int) $uniqueStmt->fetchColumn();
        if ($reportedTotal !== null && $expectedOffset < $reportedTotal) {
            $reasons[] = 'La captura terminó antes de alcanzar el total remoto informado.';
        }
        if ($reportedTotal !== null && $expectedOffset > $reportedTotal) {
            $reasons[] = 'La captura avanzó más allá del total remoto informado; la fuente cambió durante la lectura.';
        }
        if ($reportedTotal !== null && $uniqueTotal !== $reportedTotal) {
            $reasons[] = 'Los IDs únicos no coinciden con el total remoto informado.';
        }
        $reasons = array_values(array_unique($reasons));
        $valid = $reasons === [];
        $evidence = [
            'schema' => 'verified-sales-capture-v1',
            'run_id' => $runId,
            'pages' => array_map(static fn(array $page): array => [
                'offset' => (int) $page['page_offset'],
                'count' => (int) $page['result_count'],
                'total' => (int) $page['remote_reported_total'],
                'status' => (int) ($page['http_status'] ?? 0),
                'ids_hash' => (string) $page['ids_hash'],
            ], $pages),
            'reported_total' => $reportedTotal,
            'unique_total' => $uniqueTotal,
            'reasons' => $reasons,
        ];
        $evidenceHash = hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $state = $valid ? 'valid' : 'invalid';
        $pdo->prepare(
            'INSERT INTO sync_sales_capture_validations
             (sync_sales_audit_run_id,company_id,meli_account_id,validation_state,page_count,
              reported_total,unique_total,first_offset,last_offset,reasons_json,evidence_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE validation_state=VALUES(validation_state),
               page_count=VALUES(page_count),reported_total=VALUES(reported_total),
               unique_total=VALUES(unique_total),first_offset=VALUES(first_offset),
               last_offset=VALUES(last_offset),reasons_json=VALUES(reasons_json),
               evidence_hash=VALUES(evidence_hash),validated_at=UTC_TIMESTAMP(3)'
        )->execute([
            $runId,
            (int) $run['company_id'],
            (int) $run['meli_account_id'],
            $state,
            count($pages),
            $reportedTotal,
            $uniqueTotal,
            $pages !== [] ? (int) $pages[0]['page_offset'] : null,
            $pages !== [] ? (int) $pages[count($pages) - 1]['page_offset'] : null,
            json_encode($reasons, JSON_UNESCAPED_UNICODE),
            $evidenceHash,
        ]);
        return compact('valid', 'state', 'reasons', 'reportedTotal', 'uniqueTotal', 'evidenceHash') + [
            'page_count' => count($pages),
            'reported_total' => $reportedTotal,
            'unique_total' => $uniqueTotal,
            'evidence_hash' => $evidenceHash,
        ];
    }

    public function enqueueMonth(
        int $accountId,
        int $year,
        int $month,
        ?int $userId = null,
        int $companyId = 0
    ): int {
        return (new SalesAuditRunService())->createExactMonth($accountId, $year, $month, $userId, $companyId);
    }

    /** @return array<string,mixed> */
    public function processNextPage(int $pagesPerCycle = 1, ?float $deadline = null): array
    {
        return (new SalesAuditRunService())->processDue($pagesPerCycle, $deadline);
    }
}
