$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$name = 'ERP Meli 2.29.9 Applied Migration 015 Drift Recovery'
$version = '2.29.9'
$migration = '253_applied_migration_015_drift_recovery_2_29_9.sql'
$build = 'erp-meli-2.29.9-applied-015-drift-recovery-20260803'
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-2299-' + [Guid]::NewGuid().ToString('N'))
$subir = Join-Path $staging 'SUBIR'

function Assert-Under([string] $child, [string] $parent) {
    $childFull = [IO.Path]::GetFullPath($child).TrimEnd('\') + '\'
    $parentFull = [IO.Path]::GetFullPath($parent).TrimEnd('\') + '\'
    if (-not $childFull.StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Ruta fuera del directorio permitido: $child"
    }
}

function Hash-Files([string] $root) {
    $result = @{}
    $prefix = [IO.Path]::GetFullPath($root).TrimEnd('\') + '\'
    foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File -Force) {
        $relative = $file.FullName.Substring($prefix.Length).Replace('\', '/')
        $result[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
    }
    return $result
}

Assert-Under $staging $releaseRoot
Assert-Under $final $releaseRoot
if (Test-Path -LiteralPath $final) {
    throw "La entrega ya existe: $final"
}

$actualVersion = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
$manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
if ($actualVersion -ne $version -or [string] $manifest.version -ne $version) {
    throw 'VERSION y manifiesto no corresponden a 2.29.9.'
}
if ([string] $manifest.minimum_migration -ne $migration -or [string] $manifest.build_id -ne $build) {
    throw 'Migración mínima o build incorrecto.'
}
foreach ($component in $manifest.components.PSObject.Properties) {
    $entry = $component.Value
    $path = Join-Path $source ([string] $entry.path).Replace('/', '\')
    $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string] $entry.sha256).ToLowerInvariant()) {
        throw "Hash inválido: $($component.Name)"
    }
}

if ([string]::IsNullOrWhiteSpace($env:ERP_MIGRATOR_TEST_DSN)) {
    throw 'ERP_MIGRATOR_TEST_DSN es obligatorio para construir 2.29.9.'
}
foreach ($gate in @(
    @{ command = 'composer'; arguments = @('validate','--no-check-publish') },
    @{ command = 'composer'; arguments = @('test') },
    @{ command = 'composer'; arguments = @('analyse') },
    @{ command = 'composer'; arguments = @('compat') },
    @{ command = 'composer'; arguments = @('lint') },
    @{ command = 'php'; arguments = @('tests/release_22834_migrations_mysql_integration.php') }
)) {
    $gateCommand = [string] $gate.command
    $gateArguments = [string[]] $gate.arguments
    & $gateCommand @gateArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Gate de release fallido: $gateCommand $($gateArguments -join ' ')"
    }
}

New-Item -ItemType Directory -Path $subir -Force | Out-Null
foreach ($file in @('.htaccess','VERSION','index.php','login.php','actualizar.php','asset.php','bootstrap.php','cron-status.php','mantenimiento.php','recuperar.php','stop.php','composer.json','composer.lock')) {
    $sourceFile = Join-Path $source $file
    if (Test-Path -LiteralPath $sourceFile) {
        Copy-Item -LiteralPath $sourceFile -Destination (Join-Path $subir $file) -Force
    }
}
foreach ($directory in @('app','database','jobs','launcher','public','resources','stop')) {
    Copy-Item -LiteralPath (Join-Path $source $directory) -Destination (Join-Path $subir $directory) -Recurse -Force
}
foreach ($generated in @('app\graphify-out','public\graphify-out','resources\graphify-out','resources\mercadolibre-api\source')) {
    $generatedPath = Join-Path $subir $generated
    if (Test-Path -LiteralPath $generatedPath) {
        Assert-Under $generatedPath $subir
        Remove-Item -LiteralPath $generatedPath -Recurse -Force
    }
}

$forbidden = @(
    '(^|/)(config\.env|\.env|PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$',
    '(^|/)(tests|docs|audits|vendor|storage|bin|graphify-out|releases)(/|$)',
    '^resources/mercadolibre-api/source(/|$)',
    '^shared(/|$)',
    '\.(erpbackup|log|bak|dump|sql\.gz)$'
)
$expected = Hash-Files $subir
foreach ($relative in $expected.Keys) {
    foreach ($pattern in $forbidden) {
        if ($relative -match $pattern) {
            throw "Archivo prohibido: $relative"
        }
    }
}
foreach ($required in @(
    'VERSION',
    'actualizar.php',
    'cron-status.php',
    'jobs/process_sync_queue.php',
    'jobs/cron_v3_local.php',
    'jobs/cron_v3_remote.php',
    'jobs/cron_v3_setup_check.php',
    'resources/runtime-manifest.json',
    'database/migrations/240_cron_v3_security_containment_2_29_0.sql',
    'database/migrations/241_cron_v3_engine_2_29_0.sql',
    'database/migrations/241_financial_job_fk_support_2_29_1.sql',
    'database/migrations/242_financial_state_v3_2_29_0.sql',
    'database/migrations/243_cron_v3_scope_containment_2_29_0.sql',
    'database/migrations/244_cron_v3_rate_scope_authority_2_29_1.sql',
    'database/migrations/245_cron_v3_rc2_release_2_29_1.sql',
    'database/migrations/246_cron_v3_config_authority_2_29_2.sql',
    'database/migrations/247_cron_v3_activation_assistant_2_29_3.sql',
    'database/migrations/248_cron_v3_canary_control_2_29_4.sql',
    'database/migrations/249_cron_v3_canary_actionable_ui_2_29_5.sql',
    'database/migrations/250_updater_safe_transition_2_29_6.sql',
    'database/migrations/251_asset_hash_stable_line_endings_2_29_7.sql',
    'database/migrations/252_migration_line_ending_drift_recovery_2_29_8.sql',
    'database/migrations/253_applied_migration_015_drift_recovery_2_29_9.sql'
)) {
    if (-not (Test-Path -LiteralPath (Join-Path $subir $required.Replace('/', '\')))) {
        throw "Falta runtime: $required"
    }
}
foreach ($lint in @(
    'jobs/process_sync_queue.php',
    'jobs/cron_v3_local.php',
    'jobs/cron_v3_remote.php',
    'jobs/cron_v3_setup_check.php',
    'app/Services/CronV3.php',
    'app/Services/CronV3Runner.php',
    'app/Services/CronV3WorkRepository.php',
    'app/Services/CronV3SetupAssistantService.php',
    'app/Services/CronV3CanaryControlService.php',
    'app/Services/Migrator.php',
    'app/Services/ReleaseIntegrityService.php',
    'app/Recovery/RecoveryKernel.php'
)) {
    php -l (Join-Path $subir $lint.Replace('/', '\')) | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint falló: $lint"
    }
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = Join-Path $staging ($name + '.zip')
[IO.Compression.ZipFile]::CreateFromDirectory($subir, $zip, [IO.Compression.CompressionLevel]::Optimal, $false)
$observed = @{}
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
    foreach ($entry in $archive.Entries) {
        if ($entry.FullName.EndsWith('/')) { continue }
        $stream = $entry.Open()
        try {
            $sha = [Security.Cryptography.SHA256]::Create()
            try {
                $observed[$entry.FullName.Replace('\', '/')] = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '')
            } finally {
                $sha.Dispose()
            }
        } finally {
            $stream.Dispose()
        }
    }
} finally {
    $archive.Dispose()
}
if ($observed.Count -ne $expected.Count) {
    throw "ZIP y SUBIR difieren en cantidad: $($observed.Count) / $($expected.Count)"
}
foreach ($relative in $expected.Keys) {
    if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) {
        throw "ZIP difiere de SUBIR: $relative"
    }
}

$extract = Join-Path $releaseRoot ('.extract-2299-' + [Guid]::NewGuid().ToString('N'))
try {
    [IO.Compression.ZipFile]::ExtractToDirectory($zip, $extract)
    if ((Get-Content -LiteralPath (Join-Path $extract 'VERSION') -Raw).Trim() -ne $version) {
        throw 'Versión extraída incorrecta.'
    }
    foreach ($lint in @('jobs\process_sync_queue.php','jobs\cron_v3_local.php','jobs\cron_v3_remote.php','jobs\cron_v3_setup_check.php')) {
        php -l (Join-Path $extract $lint) | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "El runtime extraído no pasó lint: $lint"
        }
    }
} finally {
    if (Test-Path -LiteralPath $extract) {
        Assert-Under $extract $releaseRoot
        Remove-Item -LiteralPath $extract -Recurse -Force
    }
}

Move-Item -LiteralPath $staging -Destination $final
Copy-Item -LiteralPath (Join-Path $source 'docs\cron_v3_architecture.md') -Destination (Join-Path $final 'ARQUITECTURA_CRON_V3.md') -Force
Copy-Item -LiteralPath (Join-Path $source 'docs\cron_v3_audit_2_29_9.md') -Destination (Join-Path $final 'AUDITORIA_CRON_V3_2_29_9.md') -Force
Copy-Item -LiteralPath (Join-Path $source 'docs\mercadolibre_api_map.md') -Destination (Join-Path $final 'MAPA_API_MERCADOLIBRE.md') -Force
$finalZip = Join-Path $final ($name + '.zip')
[pscustomobject]@{
    version = $version
    path = $final
    files = $expected.Count
    zip_bytes = (Get-Item -LiteralPath $finalZip).Length
    zip_sha256 = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
} | ConvertTo-Json -Depth 3
