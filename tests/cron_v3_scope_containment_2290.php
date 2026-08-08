<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$backfill = (string) file_get_contents($root . '/app/Services/NotificationBackfillService.php');
$dateRepair = (string) file_get_contents($root . '/app/Services/OrderDateRepairService.php');
$salesRepair = (string) file_get_contents($root . '/app/Services/SalesRepairService.php');
$migration = (string) file_get_contents(
    $root . '/database/migrations/243_cron_v3_scope_containment_2_29_0.sql'
);

$check(
    str_contains($backfill, '(company_id,meli_account_id,mode,status')
        && str_contains($backfill, 'r.company_id=:company AND r.meli_account_id=:account'),
    'Cada backfill debe crearse y localizarse con empresa y cuenta exactas.'
);
$check(
    str_contains($backfill, 'JOIN meli_accounts a')
        && str_contains($backfill, 'WHERE e.meli_account_id=:account AND e.id>:checkpoint'),
    'El barrido de eventos debe comprobar cuenta y empresa antes de leer.'
);
$check(
    str_contains($backfill, 'WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=?')
        && str_contains($backfill, 'mr.company_id=meli_notification_backfill_runs.company_id')
        && str_contains($backfill, 'private function ownsRunLease('),
    'Claim y finalizacion de backfill deben conservar el mismo tenant y propietario.'
);
$check(
    str_contains($backfill, 'private function importLegacy(int $companyId, int $accountId, int $limit)')
        && str_contains($backfill, 'n.payload_hash=l.event_hash AND n.meli_account_id=l.meli_account_id'),
    'La importacion legacy debe ser por tenant y no deduplicar entre cuentas.'
);
$check(
    !str_contains($backfill, "query('SELECT COUNT(*) FROM meli_notification_events')")
        && !str_contains($backfill, 'SELECT * FROM meli_notification_backfill_runs ORDER BY'),
    'No deben sobrevivir lecturas globales de eventos o corridas.'
);

$check(
    str_contains($dateRepair, '(company_id,meli_account_id,period_year,period_month,status,created_by)')
        && str_contains($dateRepair, 'o.meli_account_id=:account'),
    'Reparacion de fechas debe persistir y leer el scope completo.'
);
$check(
    str_contains($dateRepair, 'owner_token=:owner')
        && str_contains($dateRepair, 'lease_generation=:generation')
        && str_contains($dateRepair, 'lease_until>=UTC_TIMESTAMP()'),
    'Reparacion de fechas debe cercar claims y escrituras tardias.'
);
$check(
    str_contains($dateRepair, 'UPDATE meli_orders o')
        && str_contains($dateRepair, 'a.company_id=:company')
        && str_contains($dateRepair, 'o.meli_account_id=:account'),
    'La mutacion de una orden debe verificar empresa y cuenta en SQL.'
);
$check(
    str_contains($dateRepair, 'createExactMonth($accountId, $year, $month, null, $companyId)'),
    'La rea auditoria debe propagar la empresa, no solo la cuenta.'
);

$check(
    str_contains($salesRepair, '(sync_sales_audit_id,company_id,meli_account_id')
        && str_contains($salesRepair, 'a.company_id=:company'),
    'La reparacion de ventas debe materializar el tenant derivado de la auditoria.'
);
$check(
    str_contains($salesRepair, 'private function claimLegacy(string $owner)')
        && str_contains($salesRepair, 'j.company_id=:company AND j.meli_account_id=:account')
        && str_contains($salesRepair, 'lease_generation=lease_generation+1'),
    'El claim legacy debe ser transaccional, scoped y generacional.'
);
$check(
    str_contains($salesRepair, 'private function nextScopedExactJobId()')
        && str_contains($salesRepair, '$exact->processExact($exactJobId, $limit)')
        && !str_contains($salesRepair, '$exact->processDue($limit)'),
    'La compatibilidad exacta debe seleccionar un job ya atribuido, no reclamar globalmente.'
);
$check(
    str_contains($salesRepair, 'private function ownsLegacyLease(')
        && str_contains($salesRepair, 'source_kind="legacy" AND lock_owner=:owner'),
    'Un worker legacy vencido no debe poder completar items o jobs.'
);
$check(
    str_contains($salesRepair, 'private function localOrderId(int $companyId, int $accountId')
        && str_contains($salesRepair, 'o.meli_account_id=:account AND o.external_order_id=:external'),
    'La busqueda de la orden reparada debe permanecer dentro del tenant.'
);

foreach ([
    'ALTER TABLE meli_notification_backfill_runs',
    'ALTER TABLE meli_notification_backfill_unique_resources',
    'ALTER TABLE order_datetime_repair_jobs',
    'UPDATE sync_sales_repair_jobs j',
    'idx_notification_backfill_scope_due',
    'idx_order_date_repair_scope_due',
    'idx_sales_repair_scope_due',
] as $requiredSql) {
    $check(str_contains($migration, $requiredSql), 'Migracion 243 incompleta: falta ' . $requiredSql . '.');
}
$check(
    str_contains($migration, "status='error'")
        && str_contains($migration, 'Trabajo sin empresa o cuenta verificable'),
    'La migracion debe cerrar trabajos activos cuyo tenant no pueda atribuirse.'
);

foreach ([$backfill, $dateRepair, $salesRepair] as $service) {
    $check(!str_contains($service, 'new MeliApiClient'), 'La contencion no debe crear transporte HTTP nuevo.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS cron_v3_scope_containment_2290\n";
