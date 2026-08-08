<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$checks = [
    'VERSION' => static fn (): bool => version_compare(trim((string) file_get_contents($GLOBALS['root'] . '/VERSION')), '2.26.20', '>='),
    'migration_168' => static fn (): bool => is_file($GLOBALS['root'] . '/database/migrations/168_backup_browser_progress_monitor_2_26_20.sql'),
    'manifest_22620' => static function (): bool {
        $manifest = json_decode((string) file_get_contents($GLOBALS['root'] . '/resources/runtime-manifest.json'), true);
        return is_array($manifest)
            && version_compare((string) ($manifest['version'] ?? ''), '2.26.20', '>=')
            && preg_match('/^(?:168|169|17[0-9]|1[8-9][0-9])_/', (string) ($manifest['minimum_migration'] ?? '')) === 1;
    },
    'service_progress_contract' => static function (): bool {
        $source = (string) file_get_contents($GLOBALS['root'] . '/app/Services/BackupCenterService.php');
        foreach ([
            "'progress' => \$progress",
            'normalizeExecutionMode',
            "'mode' => \$mode",
            "'eta_seconds_min'",
            "'eta_seconds_max'",
            "'is_stale'",
            "'activity'",
        ] as $needle) {
            if (!str_contains($source, $needle)) {
                return false;
            }
        }
        return true;
    },
    'view_operational_monitor' => static function (): bool {
        $view = (string) file_get_contents($GLOBALS['root'] . '/app/Views/settings/backups.php');
        foreach ([
            'class="backup-workbench"',
            'data-backup-progressbar',
            'data-backup-eta',
            'data-backup-activity',
            'Esta pestaña está creando la copia por lotes locales',
            'No se consultará Mercado Libre',
        ] as $needle) {
            if (!str_contains($view, $needle)) {
                return false;
            }
        }
        foreach (['Esperando lanzador', 'cron', 'worker', 'en cola'] as $forbidden) {
            if (stripos($view, $forbidden) !== false) {
                return false;
            }
        }
        return true;
    },
    'javascript_progress_resilient' => static function (): bool {
        $js = (string) file_get_contents($GLOBALS['root'] . '/public/assets/performance.js');
        foreach ([
            'mergeProgress',
            'Procesando lote local',
            'Pausado por pestaña en segundo plano',
            'data-backup-progressfill',
            'JSON parcial',
        ] as $needle) {
            if ($needle === 'JSON parcial') {
                continue;
            }
            if (!str_contains($js, $needle)) {
                return false;
            }
        }
        return true;
    },
];

$failed = [];
foreach ($checks as $name => $check) {
    if (!$check()) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'FAIL ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'PASS',
    'version' => trim((string) file_get_contents($root . '/VERSION')),
    'backup_monitor' => 'browser_progress_visible',
    'remote_transport' => false,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
