<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SyncDiagnosticService
{
    public function latest(int $accountId, int $year, int $month): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sync_diagnostics
             WHERE meli_account_id=:account AND period_year=:year AND period_month=:month
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'year' => $year, 'month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['sample'] = json_decode((string) $row['sample_json'], true) ?: [];
        return $row;
    }

    public function run(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden ejecutar diagnósticos de sincronización.');
        }
        if ($accountId <= 0 || $year < 2020 || $month < 1 || $month > 12) {
            throw new RuntimeException('Seleccione cuenta, año y mes válidos.');
        }
        $api = new MeliApiClient($accountId);
        $sellerId = $this->sellerId($accountId);
        $dates = $this->sampleDates($year, $month);
        $samples = [];
        $total = 0;
        foreach ($dates as $date) {
            $from = new DateTimeImmutable($date . ' 00:00:00');
            $to = new DateTimeImmutable($date . ' 23:59:59');
            $page = $api->get('/orders/search', [
                'seller' => $sellerId,
                'order.date_created.from' => $from->format(DATE_ATOM),
                'order.date_created.to' => $to->format(DATE_ATOM),
                'offset' => 0,
                'limit' => 1,
            ], ['job_type' => 'sales_audit']);
            $count = (int) ($page['paging']['total'] ?? 0);
            $samples[] = [
                'date' => $date,
                'weekday' => (int) $from->format('N'),
                'orders' => $count,
            ];
            $total += $count;
        }
        $average = count($samples) > 0 ? $total / count($samples) : 0.0;
        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $estimatedMonth = (int) round($average * $daysInMonth);
        $maxOrders = (new SyncSettingsService())->maxOrdersPerRun();
        $recommendedParts = max(1, (int) ceil($estimatedMonth / max(1, $maxOrders)));
        $recommendedMode = $recommendedParts <= 5 ? 'weekly' : 'parts';
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_diagnostics
             (meli_account_id,period_year,period_month,sample_json,average_daily_orders,estimated_month_orders,recommended_mode,recommended_parts,created_by)
             VALUES (:account,:year,:month,:sample,:average,:estimated,:mode,:parts,:user)'
        );
        $stmt->execute([
            'account' => $accountId,
            'year' => $year,
            'month' => $month,
            'sample' => json_encode($samples, JSON_UNESCAPED_UNICODE),
            'average' => round($average, 2),
            'estimated' => $estimatedMonth,
            'mode' => $recommendedMode,
            'parts' => min(31, max(1, $recommendedParts)),
            'user' => Auth::id(),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    private function sampleDates(int $year, int $month): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end = $start->modify('last day of this month');
        $byWeekday = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $day) {
            $weekday = (int) $day->format('N');
            $byWeekday[$weekday][] = $day->format('Y-m-d');
        }
        $dates = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            if (!empty($byWeekday[$weekday])) {
                $idx = (int) floor((count($byWeekday[$weekday]) - 1) / 2);
                $dates[] = $byWeekday[$weekday][$idx];
            }
        }
        sort($dates);
        return $dates;
    }

    private function sellerId(int $accountId): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id');
        $stmt->execute(['id' => $accountId]);
        return (int) $stmt->fetchColumn();
    }
}
