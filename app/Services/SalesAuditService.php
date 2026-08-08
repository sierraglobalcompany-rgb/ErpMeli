<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use Throwable;

final class SalesAuditService
{
    public function accounts(): array
    {
        return (new SyncCenterService())->accounts();
    }

    public function latest(int $accountId, int $year, int $month): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*,m.account_name
             FROM sync_sales_audits a
             JOIN meli_accounts m ON m.id=a.meli_account_id
             WHERE a.meli_account_id=:account AND a.period_year=:year AND a.period_month=:month
             LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'year' => $year, 'month' => $month]);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$audit) {
            return null;
        }
        $days = Database::connection()->prepare('SELECT * FROM sync_sales_audit_days WHERE sync_sales_audit_id=:id ORDER BY audit_date');
        $days->execute(['id' => (int) $audit['id']]);
        $audit['days'] = $days->fetchAll(PDO::FETCH_ASSOC);
        $audit['exact_differences'] = $this->exactDifferences((int) $audit['id']);
        return $audit;
    }

    public function recentWithDifference(int $limit = 5, int $accountId = 0, int $companyId = 0): array
    {
        try {
            $where = ['a.status IN ("incomplete","error","blocked")'];
            $params = [];
            if ($accountId > 0) {
                $account = (new BusinessScopeContext())->account($accountId, $companyId);
                $where[] = 'a.meli_account_id=?';
                $params[] = (int) $account['id'];
            } else {
                $scope = (new BusinessScopeContext())->accountPredicate('a.meli_account_id', null, $companyId);
                $where[] = $scope['sql'];
                $params = array_merge($params, $scope['params']);
            }
            $stmt = Database::connection()->prepare(
                'SELECT a.*,m.account_name
                 FROM sync_sales_audits a
                 JOIN meli_accounts m ON m.id=a.meli_account_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY a.checked_at DESC LIMIT ' . max(1, min(20, $limit))
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public function runMonth(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        return $this->runMonthSystem($accountId, $year, $month);
    }

    public function runMonthSystem(int $accountId, int $year, int $month): int
    {
        $this->assertAccountPeriod($accountId, $year, $month);
        $range = (new MeliDateRangeService())->localMonth($year, $month);
        $remote = $this->remoteTotal($accountId, $range['utc_from'], $range['utc_to']);
        $local = $this->localTotal($accountId, $range['local_from'], $range['local_to']);
        $status = $remote === $local ? 'complete' : 'incomplete';
        $auditId = $this->upsertAudit($accountId, $year, $month, $range, $remote, $local, $status, null);
        $this->auditDays($auditId, $accountId, $range['local_from'], $range['local_to']);
        $this->flagTimezoneSuspicion($auditId);
        return $auditId;
    }

    public function runDay(int $accountId, string $date): int
    {
        Auth::requireRole('admin', 'operador');
        $range = (new MeliDateRangeService())->localDay($date);
        $auditId = $this->ensureAudit($accountId, (int) $range['local_from']->format('Y'), (int) $range['local_from']->format('n'));
        return $this->auditOneDay($auditId, $accountId, $range);
    }

    public function compareMonthIds(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        return $this->compareMonthIdsSystem($accountId, $year, $month);
    }

    public function compareMonthIdsSystem(int $accountId, int $year, int $month): int
    {
        $auditId = $this->ensureAudit($accountId, $year, $month);
        $range = (new MeliDateRangeService())->localMonth($year, $month);
        $count = 0;
        foreach (new DatePeriod($range['local_from'], new DateInterval('P1D'), $range['local_to']) as $day) {
            $dayId = $this->auditOneDay($auditId, $accountId, (new MeliDateRangeService())->localDay($day->format('Y-m-d')));
            $count += $this->compareDayIdsSystem($dayId);
        }
        Database::connection()->prepare('UPDATE sync_sales_audits SET last_exact_audit_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id' => $auditId]);
        $this->flagTimezoneSuspicion($auditId);
        $this->updateAuditRollup($auditId);
        return $count;
    }

    public function compareDayIds(int $dayId): int
    {
        Auth::requireRole('admin', 'operador');
        return $this->compareDayIdsSystem($dayId);
    }

    public function compareDayIdsSystem(int $dayId): int
    {
        $day = $this->day($dayId);
        $range = (new MeliDateRangeService())->localDay((string) $day['audit_date']);
        $accountId = (int) $day['meli_account_id'];
        $remoteRows = $this->remoteOrderRows($accountId, $range['utc_from'], $range['utc_to']);
        $remoteIds = array_keys($remoteRows);
        $localRows = $this->localOrderRows($accountId, $range['local_from'], $range['local_to']);
        $localIds = array_keys($localRows);
        $localByRemote = $this->localRowsByExternalIdsAnyAccount($remoteIds);
        $missing = [];
        $shifted = [];
        $classifications = [];
        foreach ($remoteIds as $external) {
            if (isset($localRows[$external])) {
                $classifications[$external] = ['classification' => 'present', 'row' => $localRows[$external], 'message' => 'La orden existe en la cuenta y día auditado.'];
                continue;
            }
            if (isset($localByRemote[$external])) {
                $shifted[] = $external;
                $classifications[$external] = $this->classifyExistingLocal($localByRemote[$external], $accountId, $range);
                continue;
            }
            $missing[] = $external;
            $classifications[$external] = ['classification' => 'missing_remote', 'row' => null, 'message' => 'La orden remota no existe localmente; falta descargar.'];
        }
        $extra = array_values(array_diff($localIds, $remoteIds));
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM sync_sales_audit_missing_orders WHERE sync_sales_audit_day_id=:day')->execute(['day' => $dayId]);
        if ((new SchemaInspectorService())->hasTable('sync_sales_audit_remote_ids')) {
            $pdo->prepare('DELETE FROM sync_sales_audit_remote_ids WHERE sync_sales_audit_day_id=?')->execute([$dayId]);
            $snapshot = $pdo->prepare(
                'INSERT INTO sync_sales_audit_remote_ids
                 (sync_sales_audit_day_id,meli_account_id,external_order_id,remote_date_created,remote_status,found_local_order_id,classification,local_meli_account_id,local_date_created,local_date_created_local,local_date_closed_local,local_date_approved_local,local_status,diagnostic_message,checked_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE remote_date_created=VALUES(remote_date_created),remote_status=VALUES(remote_status),
                    found_local_order_id=VALUES(found_local_order_id),classification=VALUES(classification),
                    local_meli_account_id=VALUES(local_meli_account_id),local_date_created=VALUES(local_date_created),
                    local_date_created_local=VALUES(local_date_created_local),local_date_closed_local=VALUES(local_date_closed_local),
                    local_date_approved_local=VALUES(local_date_approved_local),local_status=VALUES(local_status),
                    diagnostic_message=VALUES(diagnostic_message),checked_at=UTC_TIMESTAMP()'
            );
            foreach ($remoteRows as $external => $remote) {
                $classificationData = $classifications[$external] ?? ['classification' => 'unknown', 'row' => null, 'message' => null];
                $localRow = $classificationData['row'] ?? null;
                $snapshot->execute([
                    $dayId,
                    $accountId,
                    $external,
                    $remote['date_created'] ?? null,
                    $remote['status'] ?? null,
                    $localRow['id'] ?? null,
                    $classificationData['classification'],
                    $localRow['meli_account_id'] ?? null,
                    $localRow['date_created'] ?? null,
                    $localRow['date_created_local'] ?? null,
                    $localRow['date_closed_local'] ?? null,
                    $localRow['date_approved_local'] ?? null,
                    $localRow['status'] ?? null,
                    $classificationData['message'] ?? null,
                ]);
            }
            foreach ($extra as $external) {
                $localRow = $localRows[$external] ?? null;
                $snapshot->execute([
                    $dayId,
                    $accountId,
                    $external,
                    null,
                    null,
                    $localRow['id'] ?? null,
                    'extra_local',
                    $localRow['meli_account_id'] ?? null,
                    $localRow['date_created'] ?? null,
                    $localRow['date_created_local'] ?? null,
                    $localRow['date_closed_local'] ?? null,
                    $localRow['date_approved_local'] ?? null,
                    $localRow['status'] ?? null,
                    'Existe localmente en este día, pero no aparece en la búsqueda remota del día.',
                ]);
            }
        }
        $stmt = $pdo->prepare(
            'INSERT INTO sync_sales_audit_missing_orders (sync_sales_audit_day_id,meli_account_id,external_order_id,status)
             VALUES (?,?,?,"missing")
             ON DUPLICATE KEY UPDATE sync_sales_audit_day_id=VALUES(sync_sales_audit_day_id),status="missing",found_at=UTC_TIMESTAMP()'
        );
        foreach ($missing as $external) {
            $stmt->execute([$dayId, $accountId, $external]);
        }
        $status = $missing === [] && $extra === [] ? 'complete' : 'incomplete';
        $consistency = $this->dayConsistencyStatus(count($missing), count($shifted), count($extra));
        $message = $this->dayDiagnosticMessage(count($missing), count($shifted), count($extra));
        $pdo->prepare(
            'UPDATE sync_sales_audit_days
             SET status=:status,missing_count=:missing,extra_local_count=:extra,shifted_count=:shifted_count,
                 local_shifted_count=:local_shifted_count,remote_ids_total=:remote_ids,remote_ids_checked_at=UTC_TIMESTAMP(),
                 exact_checked_at=UTC_TIMESTAMP(),audit_consistency_status=:consistency,
                 audit_diagnostic_message=:message,difference_count=remote_total-local_total
             WHERE id=:id'
        )->execute([
            'status' => $status,
            'missing' => count($missing),
            'extra' => count($extra),
            'shifted_count' => count($shifted),
            'local_shifted_count' => count($shifted),
            'remote_ids' => count($remoteIds),
            'consistency' => $consistency,
            'message' => $message,
            'id' => $dayId,
        ]);
        $this->updateAuditRollup((int) $day['sync_sales_audit_id']);
        return count($missing);
    }

    public function reclassifyExisting(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        $auditId = $this->ensureAudit($accountId, $year, $month);
        $days = Database::connection()->prepare('SELECT id FROM sync_sales_audit_days WHERE sync_sales_audit_id=:audit ORDER BY audit_date');
        $days->execute(['audit' => $auditId]);
        $count = 0;
        foreach ($days->fetchAll(PDO::FETCH_COLUMN) as $dayId) {
            $count += $this->compareDayIdsSystem((int) $dayId);
        }
        $this->updateAuditRollup($auditId);
        return $count;
    }

    public function enqueueDay(int $dayId): void
    {
        Auth::requireRole('admin', 'operador');
        $day = $this->day($dayId);
        $range = (new MeliDateRangeService())->localDay((string) $day['audit_date']);
        (new SyncCenterService())->enqueueRange((int) $day['meli_account_id'], $range['local_from'], $range['local_to']);
    }

    public function repairMonth(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        $auditId = $this->ensureAudit($accountId, $year, $month);
        $this->compareMonthIdsSystem($accountId, $year, $month);
        return (new SalesRepairService())->createFromAudit($auditId, (int) Auth::id());
    }

    public function createDateRepairJob(int $accountId, int $year, int $month): int
    {
        Auth::requireRole('admin', 'operador');
        return (new OrderDateRepairService())->createJob($accountId, $year, $month, (int) Auth::id());
    }

    public function dateDiagnostics(int $accountId, int $year, int $month): array
    {
        Auth::requireRole('admin', 'operador');
        $range = (new MeliDateRangeService())->localMonth($year, $month);
        $stmt = Database::connection()->prepare(
            'SELECT id,meli_account_id,external_order_id,date_created,date_created_local,raw_json
             FROM meli_orders
             WHERE meli_account_id=:account AND date_created>=:from AND date_created<:to
             ORDER BY ABS(HOUR(date_created)-0), date_created
             LIMIT 20'
        );
        $stmt->execute([
            'account' => $accountId,
            'from' => $range['utc_from']->format('Y-m-d H:i:s'),
            'to' => $range['utc_to']->format('Y-m-d H:i:s'),
        ]);
        $normalizer = new MeliDateTimeNormalizer();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw = (new RawPayloadReader())->decode($row, 'meli_orders') ?? [];
            $normalized = $normalizer->normalize($raw['date_created'] ?? null, 'orders.date_created');
            $rows[] = [
                'order_id' => $row['id'],
                'external_order_id' => $row['external_order_id'],
                'raw_date_created' => $normalized['raw_value'],
                'saved_utc' => $row['date_created'],
                'normalized_utc' => $normalized['utc'],
                'saved_local' => $row['date_created_local'] ?? null,
                'normalized_local' => $normalized['local'],
                'normalized_local_date' => $normalized['local_date'],
                'offset' => $normalized['source_offset'],
                'status' => $normalized['status'],
            ];
        }
        return $rows;
    }

    private function auditDays(int $auditId, int $accountId, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        foreach (new DatePeriod($from, new DateInterval('P1D'), $to) as $day) {
            $this->auditOneDay($auditId, $accountId, (new MeliDateRangeService())->localDay($day->format('Y-m-d')));
        }
    }

    /** @param array<string,mixed> $range */
    private function auditOneDay(int $auditId, int $accountId, array $range): int
    {
        try {
            $remote = $this->remoteTotal($accountId, $range['utc_from'], $range['utc_to']);
            $local = $this->localTotal($accountId, $range['local_from'], $range['local_to']);
            $status = $remote === $local ? 'complete' : 'incomplete';
            $error = null;
        } catch (Throwable $e) {
            $remote = 0;
            $local = $this->localTotal($accountId, $range['local_from'], $range['local_to']);
            $status = str_contains($e->getMessage(), 'paus') ? 'blocked' : 'error';
            $error = mb_substr($e->getMessage(), 0, 500);
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_sales_audit_days
             (sync_sales_audit_id,meli_account_id,audit_date,date_field_used,timezone_used,local_from,local_to,utc_from,utc_to,remote_total,local_total,difference_count,status,error_message,checked_at)
             VALUES (:audit,:account,:date,"date_created",:timezone,:local_from,:local_to,:utc_from,:utc_to,:remote,:local,:diff,:status,:error,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE sync_sales_audit_id=VALUES(sync_sales_audit_id),date_field_used=VALUES(date_field_used),timezone_used=VALUES(timezone_used),local_from=VALUES(local_from),local_to=VALUES(local_to),utc_from=VALUES(utc_from),utc_to=VALUES(utc_to),remote_total=VALUES(remote_total),local_total=VALUES(local_total),difference_count=VALUES(difference_count),status=VALUES(status),error_message=VALUES(error_message),checked_at=UTC_TIMESTAMP(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'audit' => $auditId,
            'account' => $accountId,
            'date' => $range['local_from']->format('Y-m-d'),
            'timezone' => $range['timezone'],
            'local_from' => $range['local_from']->format('Y-m-d H:i:s'),
            'local_to' => $range['local_to']->format('Y-m-d H:i:s'),
            'utc_from' => $range['utc_from']->format('Y-m-d H:i:s'),
            'utc_to' => $range['utc_to']->format('Y-m-d H:i:s'),
            'remote' => $remote,
            'local' => $local,
            'diff' => $remote - $local,
            'status' => $status,
            'error' => $error,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    private function remoteTotal(int $accountId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): int
    {
        $page = (new MeliApiClient($accountId))->get('/orders/search', [
            'seller' => $this->sellerId($accountId),
            'order.date_created.from' => $fromUtc->format(DATE_ATOM),
            'order.date_created.to' => $toUtc->format(DATE_ATOM),
            'offset' => 0,
            'limit' => 1,
        ], ['job_type' => 'sales_audit']);
        return (int) ($page['paging']['total'] ?? 0);
    }

    /** @return array<string,array{date_created:?string,status:?string}> */
    private function remoteOrderRows(int $accountId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $api = new MeliApiClient($accountId);
        $sellerId = $this->sellerId($accountId);
        $settings = new SyncSettingsService();
        $limit = min(50, $settings->pageLimit());
        $max = min(2000, max(1000, $settings->maxOrdersPerRun() * 4));
        $offset = 0;
        $ids = [];
        do {
            $page = $api->get('/orders/search', [
                'seller' => $sellerId,
                'order.date_created.from' => $fromUtc->format(DATE_ATOM),
                'order.date_created.to' => $toUtc->format(DATE_ATOM),
                'sort' => 'date_desc',
                'offset' => $offset,
                'limit' => $limit,
            ], ['job_type' => 'sales_audit', 'bulk' => true]);
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            foreach ($results as $row) {
                if (!empty($row['id'])) {
                    $ids[(string) $row['id']] = [
                        'date_created' => isset($row['date_created']) ? (new MeliDateTimeNormalizer())->utc($row['date_created'], 'orders.date_created') : null,
                        'status' => isset($row['status']) ? (string) $row['status'] : null,
                    ];
                }
            }
            $offset += count($results);
            $total = (int) ($page['paging']['total'] ?? 0);
        } while ($results !== [] && $offset < $total && $offset < $max);
        if ($offset < $total) {
            Logger::write('warning', 'Auditoría exacta limitada por máximo seguro de IDs.', [
                'account_id' => $accountId,
                'from' => $fromUtc->format('Y-m-d H:i:s'),
                'to' => $toUtc->format('Y-m-d H:i:s'),
                'offset' => $offset,
                'total' => $total,
            ]);
        }
        return $ids;
    }

    private function localTotal(int $accountId, DateTimeImmutable $localFrom, DateTimeImmutable $localTo): int
    {
        $schema = new SchemaInspectorService();
        if ($schema->hasColumn('meli_orders', 'date_created_local')) {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=:account AND date_created_local>=:from AND date_created_local<:to');
            $stmt->execute(['account' => $accountId, 'from' => $localFrom->format('Y-m-d H:i:s'), 'to' => $localTo->format('Y-m-d H:i:s')]);
            return (int) $stmt->fetchColumn();
        }
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=:account AND date_created>=:from AND date_created<:to');
        $stmt->execute([
            'account' => $accountId,
            'from' => $localFrom->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'to' => $localTo->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,array{id:int,meli_account_id:int,status:?string,date_created_local:?string,date_created:?string,date_closed_local:?string,date_approved_local:?string}> */
    private function localOrderRows(int $accountId, DateTimeImmutable $localFrom, DateTimeImmutable $localTo): array
    {
        $schema = new SchemaInspectorService();
        $column = $schema->hasColumn('meli_orders', 'date_created_local') ? 'date_created_local' : 'date_created';
        $from = $column === 'date_created_local' ? $localFrom : $localFrom->setTimezone(new \DateTimeZone('UTC'));
        $to = $column === 'date_created_local' ? $localTo : $localTo->setTimezone(new \DateTimeZone('UTC'));
        $dateApproved = $schema->hasColumn('meli_payments', 'date_approved_local')
            ? '(SELECT MAX(date_approved_local) FROM meli_payments p WHERE p.meli_order_id=meli_orders.id)'
            : 'NULL';
        $dateClosed = $schema->hasColumn('meli_orders', 'date_closed_local') ? 'date_closed_local' : 'NULL';
        $stmt = Database::connection()->prepare('SELECT id,meli_account_id,status,external_order_id,date_created,date_created_local,' . $dateClosed . ' date_closed_local,' . $dateApproved . ' date_approved_local FROM meli_orders WHERE meli_account_id=:account AND ' . $column . '>=:from AND ' . $column . '<:to');
        $stmt->execute(['account' => $accountId, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['external_order_id']] = [
                'id' => (int) $row['id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'status' => $row['status'] ?? null,
                'date_created_local' => $row['date_created_local'] ?? null,
                'date_created' => $row['date_created'] ?? null,
                'date_closed_local' => $row['date_closed_local'] ?? null,
                'date_approved_local' => $row['date_approved_local'] ?? null,
            ];
        }
        return $rows;
    }

    private function localRowsByExternalIdsAnyAccount(array $externalIds): array
    {
        $externalIds = array_values(array_unique(array_filter($externalIds, static fn(string $id): bool => $id !== '')));
        if ($externalIds === []) {
            return [];
        }
        $schema = new SchemaInspectorService();
        $dateApproved = $schema->hasColumn('meli_payments', 'date_approved_local')
            ? '(SELECT MAX(date_approved_local) FROM meli_payments p WHERE p.meli_order_id=o.id)'
            : 'NULL';
        $dateClosed = $schema->hasColumn('meli_orders', 'date_closed_local') ? 'o.date_closed_local' : 'NULL';
        $rows = [];
        foreach (array_chunk($externalIds, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare(
                'SELECT o.id,o.meli_account_id,o.status,o.external_order_id,o.date_created,o.date_created_local,' . $dateClosed . ' date_closed_local,' . $dateApproved . ' date_approved_local
                 FROM meli_orders o
                 WHERE CAST(o.external_order_id AS CHAR) IN (' . $placeholders . ')'
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['external_order_id']] = [
                    'id' => (int) $row['id'],
                    'meli_account_id' => (int) $row['meli_account_id'],
                    'status' => $row['status'] ?? null,
                    'date_created' => $row['date_created'] ?? null,
                    'date_created_local' => $row['date_created_local'] ?? null,
                    'date_closed_local' => $row['date_closed_local'] ?? null,
                    'date_approved_local' => $row['date_approved_local'] ?? null,
                ];
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $localRow @param array<string,mixed> $range */
    private function classifyExistingLocal(array $localRow, int $auditAccountId, array $range): array
    {
        $classification = 'local_shifted_date';
        $message = 'Existe localmente, pero no cae en el día auditado por date_created_local.';
        if ((int) ($localRow['meli_account_id'] ?? 0) !== $auditAccountId) {
            $classification = 'existe_otra_cuenta';
            $message = 'Existe localmente, pero pertenece a otra cuenta Mercado Libre.';
        } elseif (empty($localRow['date_created_local'])) {
            $classification = 'existe_sin_fecha_normalizada';
            $message = 'Existe localmente, pero no tiene date_created_local normalizada.';
        } else {
            $localDate = new DateTimeImmutable((string) $localRow['date_created_local']);
            if ($localDate < $range['local_from'] || $localDate >= $range['local_to']) {
                $classification = 'existe_otro_dia';
                $message = 'Existe localmente, pero su date_created_local cae en otro día.';
            }
            $approved = !empty($localRow['date_approved_local']) ? new DateTimeImmutable((string) $localRow['date_approved_local']) : null;
            if ($approved && $approved >= $range['local_from'] && $approved < $range['local_to']) {
                $classification = 'existe_otro_campo_fecha';
                $message = 'Existe localmente y cuenta por date_approved_local, pero no por date_created_local.';
            }
        }
        return ['classification' => $classification, 'row' => $localRow, 'message' => $message];
    }

    /** @param array<string,mixed> $range */
    private function upsertAudit(int $accountId, int $year, int $month, array $range, int $remote, int $local, string $status, ?string $error): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_sales_audits
             (meli_account_id,period_year,period_month,date_from,date_to,date_field_used,timezone_used,local_from,local_to,utc_from,utc_to,normalizer_version,remote_total,local_total,difference_count,status,error_message,checked_at,last_quick_audit_at,created_by)
             VALUES (:account,:year,:month,:from,:to,"date_created",:timezone,:local_from,:local_to,:utc_from,:utc_to,:normalizer,:remote,:local,:diff,:status,:error,UTC_TIMESTAMP(),UTC_TIMESTAMP(),:user)
             ON DUPLICATE KEY UPDATE date_from=VALUES(date_from),date_to=VALUES(date_to),date_field_used=VALUES(date_field_used),timezone_used=VALUES(timezone_used),local_from=VALUES(local_from),local_to=VALUES(local_to),utc_from=VALUES(utc_from),utc_to=VALUES(utc_to),normalizer_version=VALUES(normalizer_version),remote_total=VALUES(remote_total),local_total=VALUES(local_total),difference_count=VALUES(difference_count),status=VALUES(status),error_message=VALUES(error_message),checked_at=UTC_TIMESTAMP(),last_quick_audit_at=UTC_TIMESTAMP(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $accountId,
            'year' => $year,
            'month' => $month,
            'from' => $range['utc_from']->format('Y-m-d H:i:s'),
            'to' => $range['utc_to']->format('Y-m-d H:i:s'),
            'timezone' => $range['timezone'],
            'local_from' => $range['local_from']->format('Y-m-d H:i:s'),
            'local_to' => $range['local_to']->format('Y-m-d H:i:s'),
            'utc_from' => $range['utc_from']->format('Y-m-d H:i:s'),
            'utc_to' => $range['utc_to']->format('Y-m-d H:i:s'),
            'normalizer' => MeliDateTimeNormalizer::VERSION,
            'remote' => $remote,
            'local' => $local,
            'diff' => $remote - $local,
            'status' => $status,
            'error' => $error,
            'user' => Auth::id(),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    private function ensureAudit(int $accountId, int $year, int $month): int
    {
        $audit = $this->latest($accountId, $year, $month);
        if ($audit) {
            return (int) $audit['id'];
        }
        $range = (new MeliDateRangeService())->localMonth($year, $month);
        return $this->upsertAudit($accountId, $year, $month, $range, 0, 0, 'not_audited', null);
    }

    private function flagTimezoneSuspicion(int $auditId): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT SUM(difference_count>0) positive_days, SUM(difference_count<0) negative_days, SUM(status="incomplete") incomplete_days
             FROM sync_sales_audit_days WHERE sync_sales_audit_id=:id'
        );
        $stmt->execute(['id' => $auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $suspect = (int) ($row['positive_days'] ?? 0) > 0 && (int) ($row['negative_days'] ?? 0) > 0;
        $recommendation = $suspect
            ? 'Hay días con faltantes y sobrantes a la vez. Revise normalización horaria y ejecute recalcular fechas normalizadas.'
            : null;
        Database::connection()->prepare(
            'UPDATE sync_sales_audits SET timezone_suspect=:suspect,recommendation=:recommendation WHERE id=:id'
        )->execute(['suspect' => $suspect ? 1 : 0, 'recommendation' => $recommendation, 'id' => $auditId]);
    }

    private function updateAuditRollup(int $auditId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(remote_total),0) daily_remote_sum,
                    COALESCE(SUM(local_total),0) daily_local_sum,
                    COALESCE(SUM(missing_count),0) exact_missing_total,
                    COALESCE(SUM(COALESCE(local_shifted_count,shifted_count)),0) exact_shifted_total,
                    COALESCE(SUM(extra_local_count),0) exact_extra_total,
                    COALESCE(SUM(remote_ids_total),0) remote_ids_total
             FROM sync_sales_audit_days WHERE sync_sales_audit_id=:id'
        );
        $stmt->execute(['id' => $auditId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $audit = $pdo->prepare('SELECT remote_total,local_total FROM sync_sales_audits WHERE id=:id');
        $audit->execute(['id' => $auditId]);
        $header = $audit->fetch(PDO::FETCH_ASSOC) ?: [];
        $dailyRemote = (int) ($row['daily_remote_sum'] ?? 0);
        $dailyLocal = (int) ($row['daily_local_sum'] ?? 0);
        $exactMissing = (int) ($row['exact_missing_total'] ?? 0);
        $exactShifted = (int) ($row['exact_shifted_total'] ?? 0);
        $exactExtra = (int) ($row['exact_extra_total'] ?? 0);
        $remoteIdsTotal = (int) ($row['remote_ids_total'] ?? 0);
        $consistency = 'complete';
        $status = 'complete';
        if ($exactMissing === 0 && $exactExtra === 0 && $exactShifted > 0) {
            $consistency = 'local_shifted_date';
            $status = 'complete';
        } elseif (($dailyRemote !== (int) ($header['remote_total'] ?? 0) || $dailyLocal !== (int) ($header['local_total'] ?? 0)) && $remoteIdsTotal === 0) {
            $consistency = 'stale_audit';
            $status = 'incomplete';
        } elseif ($exactMissing > 0) {
            $consistency = 'incomplete_missing_remote';
            $status = 'incomplete';
        } elseif ($exactShifted > 0) {
            $consistency = 'local_shifted_date';
            $status = 'incomplete';
        } elseif ($exactExtra > 0) {
            $consistency = 'extra_local';
            $status = 'incomplete';
        }
        $pdo->prepare(
            'UPDATE sync_sales_audits
             SET daily_remote_sum=:daily_remote,daily_local_sum=:daily_local,exact_missing_total=:missing,
                 exact_shifted_total=:shifted,exact_extra_total=:extra,audit_consistency_status=:consistency,
                 status=:status,difference_count=remote_total-local_total
             WHERE id=:id'
        )->execute([
            'daily_remote' => $dailyRemote,
            'daily_local' => $dailyLocal,
            'missing' => $exactMissing,
            'shifted' => $exactShifted,
            'extra' => $exactExtra,
            'consistency' => $consistency,
            'status' => $status,
            'id' => $auditId,
        ]);
    }

    private function dayConsistencyStatus(int $missing, int $shifted, int $extra): string
    {
        if ($missing > 0) {
            return 'incomplete_missing_remote';
        }
        if ($shifted > 0) {
            return 'local_shifted_date';
        }
        if ($extra > 0) {
            return 'extra_local';
        }
        return 'complete';
    }

    private function dayDiagnosticMessage(int $missing, int $shifted, int $extra): string
    {
        if ($missing > 0) {
            return 'Faltan ' . $missing . ' órdenes reales por descargar.';
        }
        if ($shifted > 0) {
            return 'Hay ' . $shifted . ' órdenes locales con fecha normalizada en otro día.';
        }
        if ($extra > 0) {
            return 'Hay ' . $extra . ' órdenes locales que no aparecen en la búsqueda remota de este día.';
        }
        return 'Día completo por comparación exacta de IDs.';
    }

    private function exactDifferences(int $auditId): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('sync_sales_audit_remote_ids')) {
            return [];
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT r.*,d.audit_date,o.date_created_local,o.date_created
                 FROM sync_sales_audit_remote_ids r
                 JOIN sync_sales_audit_days d ON d.id=r.sync_sales_audit_day_id
                 LEFT JOIN meli_orders o ON o.id=r.found_local_order_id
                 WHERE d.sync_sales_audit_id=:audit AND r.classification<>"present"
                 ORDER BY d.audit_date ASC,r.classification ASC,r.external_order_id ASC
                 LIMIT 300'
            );
            $stmt->execute(['audit' => $auditId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function day(int $dayId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM sync_sales_audit_days WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $dayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Día de auditoría no encontrado.');
        }
        return $row;
    }

    private function sellerId(int $accountId): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id');
        $stmt->execute(['id' => $accountId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertAccountPeriod(int $accountId, int $year, int $month): void
    {
        if ($accountId <= 0 || $year < 2020 || $month < 1 || $month > 12) {
            throw new \RuntimeException('Seleccione cuenta, año y mes válidos.');
        }
    }
}
