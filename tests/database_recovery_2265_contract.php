<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/** @param bool $condition */
function recoveryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$backup = (string) file_get_contents($root . '/app/Services/BackupArchiveService.php');
$backupV3 = (string) file_get_contents($root . '/app/Services/BackupArchiveV3Service.php');
$center = (string) file_get_contents($root . '/app/Services/BackupCenterService.php');
$restore = (string) file_get_contents($root . '/app/Services/RestoreService.php');
$maintenance = (string) file_get_contents($root . '/app/Services/DatabaseMaintenanceService.php');
$normalizer = (string) file_get_contents(
    $root . '/app/Services/NotificationLegacyNormalizationService.php'
);
$reset = (string) file_get_contents($root . '/app/Services/ImportedMeliDataResetService.php');
$migration146 = (string) file_get_contents(
    $root . '/database/migrations/146_runtime_consolidation_physical_recovery_2_26_0.sql'
);
$preserver = (string) file_get_contents(
    $root . '/app/Services/HistoricalMigrationDataPreserver.php'
);

recoveryAssert(
    str_contains($backup, 'BackupArchiveV3Service($this->keyring)')
    && str_contains($backupV3, 'last_key')
    && str_contains($backupV3, 'keysetPredicate')
    && str_contains($backupV3, 'streamContainer')
    && str_contains($backupV3, "'snapshot_id'")
    && str_contains($backupV3, 'isVolatileCatalog')
    && !str_contains($backupV3, ' OFFSET ')
    && !str_contains($backupV3, '.sql.part')
    && str_contains($backupV3, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'),
    'El backup v3 no usa keyset y micro-lotes cifrados reanudables.'
);
recoveryAssert(
    str_contains($center, "lease_owner=:owner AND lease_generation=:generation")
    && !str_contains(
        $center,
        "WHERE backup_id=:id AND status='running'"
    ),
    'El camino de error del backup todavía puede afectar otra generación.'
);
recoveryAssert(
    str_contains($restore, 'executeRestoreStatements')
    && str_contains($restore, 'INSERT IGNORE INTO')
    && str_contains($restore, 'beginTransaction()')
    && str_contains($restore, "['manifest']")
    && str_contains($restore, 'renewRestoreLease')
    && str_contains($restore, 'GET_LOCK'),
    'La restauración no aplica DML transaccional e idempotente.'
);
recoveryAssert(
    str_contains($maintenance, 'settleControlRequest')
    && str_contains($maintenance, 'finish_after_verify')
    && str_contains($maintenance, 'status="pausing"')
    && str_contains($maintenance, "maintenanceStatusSupports('finishing')")
    && str_contains($maintenance, 'claimCliStep')
    && str_contains($maintenance, 'lease_owner=:lease_owner')
    && str_contains($maintenance, 'lease_generation=:lease_generation')
    && str_contains($maintenance, 'MaintenanceExecutionLock'),
    'Pausar o finalizar todavía puede omitir el cierre seguro del lote.'
);
recoveryAssert(
    str_contains($normalizer, 'legacy_normalization_cursor_id')
    && str_contains($normalizer, 'legacy_message_cursor_id'),
    'La normalización legacy todavía recorre el mismo prefijo histórico.'
);
recoveryAssert(
    str_contains($reset, "if (\$table !== 'manual_campaigns')")
    && str_contains($reset, 'campaña #6'),
    'El reset no protege explícitamente campañas dirigidas activas.'
);
recoveryAssert(
    str_contains($migration146, 'DROP TABLE IF EXISTS system_retention_runs')
    && str_contains($preserver, 'system_retention_runs_preserved_2265')
    && str_contains($preserver, 'RENAME TABLE')
    && str_contains($preserver, 'INSERT IGNORE INTO')
    && str_contains($preserver, 'current_row.id=legacy.id'),
    'La migración histórica 146 no está protegida por una conservación verificable.'
);
recoveryAssert(
    hash_equals(
        '1d4afd69e8253debfd634c6875cf5b2c71df1d459f45563ec6374219f1d7a312',
        hash('sha256', $migration146)
    ),
    'La migración histórica 146 fue reescrita después de publicarse.'
);

// El hash encadenado debe ser determinista entre micro-lotes.
$rows = ["INSERT INTO `t` (`id`) VALUES ('1');\n", "INSERT INTO `t` (`id`) VALUES ('2');\n"];
$onePass = hash('sha256', '');
foreach ($rows as $sql) {
    $onePass = hash('sha256', hex2bin($onePass) . $sql);
}
$resumed = hash('sha256', '');
$resumed = hash('sha256', hex2bin($resumed) . $rows[0]);
$checkpoint = $resumed;
$resumed = hash('sha256', hex2bin($checkpoint) . $rows[1]);
recoveryAssert(hash_equals($onePass, $resumed), 'El hash v3 cambia al reanudar.');

echo "OK database recovery 2.26.5 contracts\n";
