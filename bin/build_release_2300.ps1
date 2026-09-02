$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$name = 'ERP Meli 2.30.0 Cron V3 Operativo Total'
$version = '2.30.0'
$migration = '257_cron_v3_operational_cutover_2_30_0.sql'
$build = 'erp-meli-2.30.0-cron-v3-operativo-total-20260803'
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-2300-' + [Guid]::NewGuid().ToString('N'))
$subir = Join-Path $staging 'SUBIR'

function Hash-Files([string] $root) {
    $result = @{}
    $prefix = [IO.Path]::GetFullPath($root).TrimEnd('\') + '\'
    foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File -Force) {
        $relative = $file.FullName.Substring($prefix.Length).Replace('\', '/')
        $result[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
    }
    return $result
}

function Should-Skip([string] $relative) {
    $normalized = $relative.Replace('\', '/')
    $patterns = @(
        '(^|/)(\.git|vendor|tests|docs|audits|storage|bin|graphify-out|releases|work|node_modules)(/|$)',
        '(^|/)(config\.env|\.env|PAUSE_MELI_API|PAUSE_ERP_AUTOMATION|MELI_API_CANARY\.json)$',
        '^resources/mercadolibre-api/source(/|$)',
        '^shared(/|$)',
        '\.(erpbackup|log|bak|dump|sql\.gz|zip)$'
    )
    foreach ($pattern in $patterns) {
        if ($normalized -match $pattern) {
            return $true
        }
    }
    return $false
}

if (Test-Path -LiteralPath $final) {
    throw "La entrega ya existe: $final"
}

$actualVersion = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
$manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
if ($actualVersion -ne $version -or [string] $manifest.version -ne $version) {
    throw 'VERSION y manifiesto no corresponden a 2.30.0.'
}
if ([string] $manifest.minimum_migration -ne $migration -or [string] $manifest.build_id -ne $build) {
    throw 'Migración mínima o build incorrecto.'
}
foreach ($component in $manifest.components.PSObject.Properties) {
    $entry = $component.Value
    $path = Join-Path $source ([string] $entry.path).Replace('/', '\')
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Componente faltante: $($component.Name)"
    }
    $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string] $entry.sha256).ToLowerInvariant()) {
        throw "Hash inválido: $($component.Name)"
    }
}

New-Item -ItemType Directory -Path $subir -Force | Out-Null
$allowedRoots = @('app', 'database', 'jobs', 'launcher', 'public', 'resources', 'stop')
$allowedTopFiles = @('.htaccess', 'VERSION', 'index.php', 'login.php', 'actualizar.php', 'asset.php', 'bootstrap.php', 'cron-status.php', 'mantenimiento.php', 'recuperar.php', 'stop.php', 'composer.json', 'composer.lock')
$sourcePrefix = [IO.Path]::GetFullPath($source).TrimEnd('\') + '\'
foreach ($file in Get-ChildItem -LiteralPath $source -Recurse -File -Force) {
    $relative = $file.FullName.Substring($sourcePrefix.Length)
    $normalized = $relative.Replace('\', '/')
    $top = $normalized.Split('/')[0]
    if (($allowedTopFiles -notcontains $normalized) -and ($allowedRoots -notcontains $top)) {
        continue
    }
    if (Should-Skip $normalized) {
        continue
    }
    $target = Join-Path $subir $relative
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    Copy-Item -LiteralPath $file.FullName -Destination $target -Force
}

$expected = Hash-Files $subir
foreach ($required in @(
    'VERSION',
    'actualizar.php',
    'jobs/process_sync_queue.php',
    'jobs/cron_v3_local.php',
    'jobs/cron_v3_remote.php',
    'jobs/cron_v3_setup_check.php',
    'resources/runtime-manifest.json',
    'database/migrations/253_applied_migration_015_drift_recovery_2_29_9.sql',
    'database/migrations/256_cron_v3_certified_cutover_2_29_12.sql',
    'database/migrations/257_cron_v3_operational_cutover_2_30_0.sql',
    'app/Services/CronV3OperationalModeService.php',
    'app/Services/CronV3OperationalCutoverService.php',
    'app/Services/CronV3RuntimeStatusService.php'
)) {
    if (-not $expected.ContainsKey($required)) {
        throw "Falta runtime: $required"
    }
}
foreach ($relative in $expected.Keys) {
    if (Should-Skip $relative) {
        throw "Archivo prohibido: $relative"
    }
}
foreach ($lint in @(
    'jobs/process_sync_queue.php',
    'jobs/cron_v3_local.php',
    'jobs/cron_v3_remote.php',
    'jobs/cron_v3_setup_check.php',
    'app/Services/CronV3Cli.php',
    'app/Services/CronV3OperationalModeService.php',
    'app/Services/CronV3OperationalCutoverService.php',
    'app/Services/CronV3RuntimeStatusService.php',
    'app/Services/Migrator.php',
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

Move-Item -LiteralPath $staging -Destination $final
$finalZip = Join-Path $final ($name + '.zip')
[pscustomobject]@{
    version = $version
    path = $final
    files = $expected.Count
    zip_bytes = (Get-Item -LiteralPath $finalZip).Length
    zip_sha256 = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
} | ConvertTo-Json -Depth 3
