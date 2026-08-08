$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = (Resolve-Path (Join-Path $root 'releases')).Path
$specs = @(
    @{
        Version = '2.25.16'
        Name = 'ERP Meli 2.25.16 Copias Seguras'
        Migration = '141_backup_recovery_center_2_25_16.sql'
        Restore = $false
    },
    @{
        Version = '2.25.17'
        Name = 'ERP Meli 2.25.17 Restauracion Clon Diagnostico'
        Migration = '142_safe_restore_diagnostic_clone_2_25_17.sql'
        Restore = $true
    }
)

function Assert-Under {
    param([string] $Path, [string] $Parent)
    $full = [IO.Path]::GetFullPath($Path)
    $base = [IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    if (-not $full.StartsWith($base, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Ruta fuera del workspace: $full"
    }
}

function Write-Utf8 {
    param([string] $Path, [string] $Content)
    [IO.File]::WriteAllText($Path, $Content, [Text.UTF8Encoding]::new($false))
}

foreach ($spec in $specs) {
    $delivery = Join-Path $releaseRoot $spec.Name
    Assert-Under $delivery $releaseRoot
    if (Test-Path -LiteralPath $delivery) {
        throw "La entrega ya existe y no se sobrescribirá: $delivery"
    }
    $subir = Join-Path $delivery 'SUBIR'
    New-Item -ItemType Directory -Path $subir -Force | Out-Null

    foreach ($file in @(
        '.htaccess', 'actualizar.php', 'bootstrap.php', 'composer.json',
        'composer.lock', 'config.env.example', 'index.php', 'login.php',
        'stop.php', 'VERSION'
    )) {
        Copy-Item -LiteralPath (Join-Path $root $file) -Destination (Join-Path $subir $file) -Force
    }
    if ($spec.Restore) {
        Copy-Item -LiteralPath (Join-Path $root 'recuperar.php') -Destination (Join-Path $subir 'recuperar.php') -Force
    }
    foreach ($directory in @('app', 'database', 'jobs', 'launcher', 'public', 'stop')) {
        Copy-Item -LiteralPath (Join-Path $root $directory) -Destination (Join-Path $subir $directory) -Recurse -Force
    }
    foreach ($developmentPath in @(
        'app\graphify-out', 'graphify-out', 'tests', 'docs', 'audits',
        'storage', 'shared', 'releases', 'vendor', 'node_modules'
    )) {
        $candidate = Join-Path $subir $developmentPath
        if (Test-Path -LiteralPath $candidate) {
            Remove-Item -LiteralPath $candidate -Recurse -Force
        }
    }

    New-Item -ItemType Directory -Path (Join-Path $subir 'resources') -Force | Out-Null
    foreach ($file in @('migration-replacements.json', 'runtime-manifest.json')) {
        Copy-Item -LiteralPath (Join-Path $root "resources\$file") -Destination (Join-Path $subir "resources\$file") -Force
    }
    Copy-Item -LiteralPath (Join-Path $root 'resources\modules') -Destination (Join-Path $subir 'resources\modules') -Recurse -Force
    New-Item -ItemType Directory -Path (Join-Path $subir 'resources\mercadolibre-api') -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $root 'resources\mercadolibre-api\generated') -Destination (Join-Path $subir 'resources\mercadolibre-api\generated') -Recurse -Force

    if (-not $spec.Restore) {
        foreach ($relative in @(
            'database\migrations\142_safe_restore_diagnostic_clone_2_25_17.sql',
            'app\Recovery\RestoreRecoveryKernel.php',
            'app\Services\RestoreConfigSwitchService.php',
            'app\Services\RestoreMaintenanceRequestService.php',
            'app\Services\RestoreSecretStoreService.php',
            'app\Services\RestoreService.php',
            'app\Views\settings\backup_restore.php'
        )) {
            $target = Join-Path $subir $relative
            Assert-Under $target $subir
            if (Test-Path -LiteralPath $target) {
                Remove-Item -LiteralPath $target -Force
            }
        }

        $controllerPath = Join-Path $subir 'app\Controllers\BackupController.php'
        $controller = [IO.File]::ReadAllText($controllerPath)
        $controller = [regex]::Replace(
            $controller,
            '(?m)^use App\\Services\\Restore(?:ConfigSwitch|MaintenanceRequest|Service)[^;]*;\r?\n',
            ''
        )
        $start = $controller.IndexOf('    public function restore(): void')
        $end = $controller.IndexOf('    private function verifyPassword', $start)
        if ($start -lt 0 -or $end -lt 0) {
            throw 'No se pudo aislar BackupController 2.25.16.'
        }
        Write-Utf8 $controllerPath ($controller.Substring(0, $start) + $controller.Substring($end))

        $publicPath = Join-Path $subir 'public\index.php'
        $publicLines = [IO.File]::ReadAllLines($publicPath) |
            Where-Object { $_ -notmatch "\`$router->(?:get|post)\('/settings/backups/restore" }
        $public = $publicLines -join "`n"
        $public = [regex]::Replace(
            $public,
            '(?m)^\$restoreMarker = [^\r\n]+;\r?\n^\$restoreContinuation = [^\r\n]+;\r?\n',
            ''
        )
        $public = $public.Replace(
            '(is_file($snapshotMarker) || (is_file($restoreMarker) && !$restoreContinuation))',
            'is_file($snapshotMarker)'
        )
        Write-Utf8 $publicPath ($public + "`n")

        $viewPath = Join-Path $subir 'app\Views\settings\backups.php'
        $view = [IO.File]::ReadAllText($viewPath)
        $view = $view.Replace(
            '<a class="btn primary" href="<?= View::e($base) ?>/settings/backups/restore">Restaurar una copia</a>',
            ''
        )
        Write-Utf8 $viewPath $view

        $jobPath = Join-Path $subir 'jobs\process_sync_queue.php'
        $job = [IO.File]::ReadAllText($jobPath)
        $job = [regex]::Replace(
            $job,
            '(?m)^\$localRestoreRequest = is_file\([^\r\n]+\)\r?\n\s*\|\| is_file\([^\r\n]+\);\r?\n',
            ''
        )
        $job = $job.Replace(' && !$localRestoreRequest', '')
        $job = $job.Replace('($localBackupRequest || $localRestoreRequest)', '$localBackupRequest')
        $job = [regex]::Replace(
            $job,
            '\$result = \$localRestoreRequest\s*\? \(new \\App\\Services\\RestoreService\(\)\)->processRequested\(\)\s*:\s*\(new \\App\\Services\\BackupCenterService\(\)\)->processRequested\(\);',
            '$result = (new \App\Services\BackupCenterService())->processRequested();'
        )
        Write-Utf8 $jobPath $job
    }

    Write-Utf8 (Join-Path $subir 'VERSION') ($spec.Version + "`n")
    $manifestPath = Join-Path $subir 'resources\runtime-manifest.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $manifest.version = $spec.Version
    $manifest.build_id = 'erp-meli-' + $spec.Version + '-20260729'
    $manifest.built_at = '2026-07-29T05:00:00Z'
    $manifest.minimum_migration = $spec.Migration
    $manifest.components.process_sync_queue.sha256 = (
        Get-FileHash (Join-Path $subir 'jobs\process_sync_queue.php') -Algorithm SHA256
    ).Hash.ToLower()
    Write-Utf8 $manifestPath (($manifest | ConvertTo-Json -Depth 8) + "`n")

    foreach ($file in Get-ChildItem -LiteralPath $subir -Recurse -File -Force) {
        $relative = [IO.Path]::GetRelativePath($subir, $file.FullName).Replace('\', '/')
        if (
            $relative -match '(^|/)(PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$'
            -or $relative -match '(^|/)(config\.env|\.env)$'
            -or $relative -match '(^|/)(graphify-out|tests|docs|audits|vendor|node_modules)(/|$)'
            -or $relative -match '\.(sql\.gz|erpbackup|log|bak|dump)$'
        ) {
            throw "El paquete contiene un archivo prohibido: $relative"
        }
    }

    $zip = Join-Path $delivery ($spec.Name + '.zip')
    [IO.Compression.ZipFile]::CreateFromDirectory(
        $subir,
        $zip,
        [IO.Compression.CompressionLevel]::Optimal,
        $false
    )
    Write-Output ('BUILT {0} files={1} zip={2}' -f @(
        $spec.Name,
        (Get-ChildItem $subir -Recurse -File -Force).Count,
        (Get-Item $zip).Length
    ))
}
