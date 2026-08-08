$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$name = 'ERP Meli 2.28.22 Experiencia Humana Rapida'
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-22822-' + [Guid]::NewGuid().ToString('N'))
$subir = Join-Path $staging 'SUBIR'
$zipPath = Join-Path $staging ($name + '.zip')

$expectedVersion = '2.28.22'
$expectedMigration = '202_progressive_human_modules_2_28_22.sql'
$expectedBuild = 'erp-meli-2.28.22-security-cron-progressive-certified-20260801'
$sourceVersion = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
$sourceManifest = Get-Content -LiteralPath (Join-Path $root 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
if ($sourceVersion -ne $expectedVersion -or [string]$sourceManifest.version -ne $expectedVersion) {
    throw "El runtime fuente no corresponde a $expectedVersion (VERSION=$sourceVersion, manifest=$($sourceManifest.version))."
}
if ([string]$sourceManifest.minimum_migration -ne $expectedMigration) {
    throw "La migracion minima del manifiesto no corresponde a $expectedMigration."
}
if ([string]$sourceManifest.build_id -ne $expectedBuild) {
    throw "El build del manifiesto no corresponde a $expectedBuild."
}

function Assert-Under([string] $child, [string] $parent) {
    $childFull = [IO.Path]::GetFullPath($child).TrimEnd('\')
    $parentFull = [IO.Path]::GetFullPath($parent).TrimEnd('\') + '\'
    if (-not (($childFull + '\').StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase))) {
        throw "Ruta fuera del directorio permitido: $child"
    }
}

Assert-Under $staging $releaseRoot
Assert-Under $final $releaseRoot
if (Test-Path -LiteralPath $final) {
    throw "La entrega ya existe y no se sobrescribira: $final"
}
New-Item -ItemType Directory -Path $subir -Force | Out-Null

foreach ($file in @(
    '.htaccess', 'VERSION', 'index.php', 'login.php', 'actualizar.php',
    'asset.php', 'bootstrap.php', 'mantenimiento.php', 'recuperar.php',
    'stop.php', 'composer.json', 'composer.lock'
)) {
    $source = Join-Path $root $file
    if (Test-Path -LiteralPath $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $subir $file) -Force
    }
}

foreach ($directory in @('app', 'database', 'jobs', 'launcher', 'public', 'resources', 'stop')) {
    Copy-Item -LiteralPath (Join-Path $root $directory) -Destination (Join-Path $subir $directory) -Recurse -Force
}

foreach ($candidate in @(
    (Join-Path $subir 'app\graphify-out'),
    (Join-Path $subir 'resources\graphify-out'),
    (Join-Path $subir 'public\graphify-out')
)) {
    if (Test-Path -LiteralPath $candidate) {
        Assert-Under $candidate $subir
        Remove-Item -LiteralPath $candidate -Recurse -Force
    }
}

$forbiddenPatterns = @(
    '(^|/)(PAUSE_MELI_API|PAUSE_ERP_AUTOMATION|config\.env|\.env)$',
    '(^|/)(graphify-out|tests|docs|audits|vendor|node_modules|storage|bin|releases)(/|$)',
    '^(shared)(/|$)',
    '\.(sql\.gz|erpbackup|log|bak|dump)$'
)
$expected = @{}
$basePath = [IO.Path]::GetFullPath($subir).TrimEnd('\') + '\'
foreach ($file in Get-ChildItem -LiteralPath $subir -Recurse -File -Force) {
    $fullPath = [IO.Path]::GetFullPath($file.FullName)
    if (-not $fullPath.StartsWith($basePath, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Archivo fuera de SUBIR: $fullPath"
    }
    $relative = $fullPath.Substring($basePath.Length).Replace('\', '/')
    foreach ($pattern in $forbiddenPatterns) {
        if ($relative -match $pattern) {
            throw "Archivo prohibido en runtime: $relative"
        }
    }
    $expected[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
}

foreach ($required in @(
    '.htaccess', 'VERSION', 'actualizar.php', 'stop.php', 'launcher/entrypoint.php',
    'public/index.php', 'jobs/process_sync_queue.php',
    'app/Services/AuthorizedBusinessScope.php',
    'app/Services/CronBacklogSnapshotService.php',
    'app/Services/CronWorkOutcome.php',
    'app/Services/BusinessScopeContext.php',
    'database/migrations/200_critical_tenant_scope_safe_errors_2_28_20.sql',
    'database/migrations/201_cron_measured_backlog_fair_scheduler_2_28_21.sql',
    'database/migrations/202_progressive_human_modules_2_28_22.sql',
    'resources/runtime-manifest.json'
)) {
    if (-not $expected.ContainsKey($required)) {
        throw "Falta runtime obligatorio: $required"
    }
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($subir, $zipPath, [IO.Compression.CompressionLevel]::Optimal, $false)
$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $observed = @{}
    foreach ($entry in $archive.Entries) {
        $nameInZip = $entry.FullName.Replace('\', '/')
        if ($nameInZip.EndsWith('/')) { continue }
        $stream = $entry.Open()
        try {
            $sha = [Security.Cryptography.SHA256]::Create()
            try { $observed[$nameInZip] = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '') }
            finally { $sha.Dispose() }
        } finally { $stream.Dispose() }
    }
} finally { $archive.Dispose() }

if ($observed.Count -ne $expected.Count) {
    throw "ZIP y SUBIR difieren en numero de archivos: $($observed.Count) / $($expected.Count)"
}
foreach ($relative in $expected.Keys) {
    if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) {
        throw "ZIP difiere de SUBIR: $relative"
    }
}

Move-Item -LiteralPath $staging -Destination $final
$finalZip = Join-Path $final ($name + '.zip')
Write-Output ('RELEASE_PASS path="{0}" files={1} zip_bytes={2} zip_sha256={3}' -f @(
    $final,
    $expected.Count,
    (Get-Item -LiteralPath $finalZip).Length,
    (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
))
