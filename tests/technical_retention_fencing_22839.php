<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$technical = $read('app/Services/TechnicalRetentionCliService.php');
$retention = $read('app/Services/RetentionPolicyService.php');
$archive = $read('app/Services/ColdArchiveService.php');
$availability = $read('app/Services/CronWorkAvailabilityService.php');
$maintenance = $read('app/Services/OperationalMaintenanceService.php');

$check(
    str_contains($availability, "'operational_maintenance' => fn (): array => \$this->operationalMaintenance()")
        && str_contains($availability, 'cron_task_state.next_run_at')
        && str_contains($availability, 'return $this->known(1, 1, null);'),
    'Mantenimiento operativo no tiene una sonda barata gobernada por periodicidad persistida.'
);
$check(
    str_contains($technical, 'lease_owner=:owner')
        && str_contains($technical, 'generation=:generation')
        && str_contains($technical, 'lease_expires_at>=UTC_TIMESTAMP(3)')
        && str_contains($technical, 'renewOrFail')
        && str_contains($technical, 'TechnicalRetentionLeaseLostException'),
    'El lease técnico no está cercado por propietario, generación y vigencia.'
);
$check(
    str_contains($technical, 'runDatasetStep(')
        && str_contains($technical, '$fence')
        && str_contains($technical, "'reason' => 'lease_lost'"),
    'La pérdida del lease no se propaga al pipeline como aborto seguro.'
);
$check(
    str_contains($retention, '?callable $leaseGuard = null')
        && substr_count($retention, '$this->assertLease($leaseGuard);') >= 8
        && str_contains($retention, '$this->deleteEligible($dataset, $batchSize, $leaseGuard)'),
    'Archivo, rollup y eliminación no verifican el lease en cada transición.'
);
$check(
    str_contains($archive, '?callable $leaseGuard = null')
        && substr_count($archive, '$this->assertLease($leaseGuard);') >= 10
        && str_contains($archive, '$index % 100 === 0')
        && str_contains($archive, '$rows % 250 === 0'),
    'Los lotes de archivo y verificación no renuevan el lease durante trabajo prolongado.'
);
$check(
    str_contains($maintenance, "\$result['storage_retention']['errors']")
        && str_contains($maintenance, "\$result['warnings']++"),
    'Mantenimiento operativo oculta una pérdida de lease de retención.'
);

if ($failures !== []) {
    fwrite(STDERR, "FAIL technical_retention_fencing_22839\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS technical_retention_fencing_22839\n";
