<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;

final class SyncCoverageService
{
    public function markChunk(int $accountId, DateTimeImmutable $from, DateTimeImmutable $to, string $status, int $ordersCount, ?int $chunkId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO meli_sync_coverage (meli_account_id,sync_type,date_from,date_to,coverage_status,orders_count,source_chunk_id,checked_at)
             VALUES (:account,"orders",:from,:to,:status,:count,:chunk,NOW())
             ON DUPLICATE KEY UPDATE coverage_status=VALUES(coverage_status),orders_count=VALUES(orders_count),source_chunk_id=VALUES(source_chunk_id),checked_at=NOW()'
        )->execute([
            'account' => $accountId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'status' => in_array($status, ['complete', 'partial', 'error'], true) ? $status : 'unknown',
            'count' => max(0, $ordersCount),
            'chunk' => $chunkId,
        ]);
    }

    public function summary(int $accountId, string $fromDate, string $toDate): array
    {
        if ($accountId <= 0 || !$this->validDate($fromDate) || !$this->validDate($toDate)) {
            return ['status' => 'unknown', 'message' => 'Seleccione una cuenta y rango válido para validar cobertura.', 'missing_days' => [], 'covered_days' => 0, 'total_days' => 0];
        }
        $from = new DateTimeImmutable($fromDate . ' 00:00:00');
        $to = (new DateTimeImmutable($toDate . ' 00:00:00'))->modify('+1 day');
        if ($to < $from) {
            return ['status' => 'unknown', 'message' => 'El rango de cobertura es inválido.', 'missing_days' => [], 'covered_days' => 0, 'total_days' => 0];
        }
        $stmt = Database::connection()->prepare(
            'SELECT date_from,date_to,coverage_status FROM meli_sync_coverage
             WHERE meli_account_id=:account AND sync_type="orders"
               AND date_to>:from AND date_from<:to'
        );
        $stmt->execute([
            'account' => $accountId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $days = [];
        foreach (new DatePeriod($from, new DateInterval('P1D'), $to) as $day) {
            $key = $day->format('Y-m-d');
            $days[$key] = false;
            foreach ($rows as $row) {
                if ($row['coverage_status'] !== 'complete') {
                    continue;
                }
                $rowFrom = new DateTimeImmutable((string) $row['date_from']);
                $rowTo = new DateTimeImmutable((string) $row['date_to']);
                if ($day >= $rowFrom->setTime(0, 0) && $day < $rowTo) {
                    $days[$key] = true;
                    break;
                }
            }
        }
        $missing = array_keys(array_filter($days, static fn(bool $covered): bool => !$covered));
        $covered = count($days) - count($missing);
        if ($days === []) {
            $status = 'unknown';
        } elseif ($missing === []) {
            $status = 'complete';
        } elseif ($covered > 0) {
            $status = 'partial';
        } else {
            $status = 'unknown';
        }

        return [
            'status' => $status,
            'message' => match ($status) {
                'complete' => 'Cobertura completa: el rango tiene sincronización marcada como completa.',
                'partial' => 'Cobertura parcial: algunos días del rango no tienen sincronización completa.',
                default => 'Cobertura desconocida: no hay evidencia suficiente de sincronización completa para este rango.',
            },
            'missing_days' => array_slice($missing, 0, 20),
            'covered_days' => $covered,
            'total_days' => count($days),
        ];
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
