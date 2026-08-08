$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$name = 'ERP Meli 2.28.37 Cron Rapido Retencion Tecnica'
$version = '2.28.37'
$migration = '217_progressive_read_models_retention_2_28_37.sql'
$build = 'erp-meli-2.28.37-cron-capacity-intervention-retention-20260802'
$php = (Get-Command php -ErrorAction Stop).Source
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-22837-' + [Guid]::NewGuid().ToString('N'))
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
if (Test-Path -LiteralPath $final) { throw "La entrega ya existe: $final" }

$actualVersion = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
$manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
if ($actualVersion -ne $version -or [string] $manifest.version -ne $version) { throw 'VERSION y manifiesto no corresponden a 2.28.37.' }
if ([string] $manifest.minimum_migration -ne $migration -or [string] $manifest.build_id -ne $build) { throw 'Migración mínima o build incorrecto.' }
foreach ($component in @('cron_probe', 'process_sync_queue')) {
    $entry = $manifest.components.$component
    $path = Join-Path $source ([string] $entry.path).Replace('/', '\')
    $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string] $entry.sha256).ToLowerInvariant()) { throw "Hash inválido: $component" }
}

New-Item -ItemType Directory -Path $subir -Force | Out-Null
foreach ($file in @('.htaccess','VERSION','index.php','login.php','actualizar.php','asset.php','bootstrap.php','cron-status.php','mantenimiento.php','recuperar.php','stop.php','composer.json','composer.lock')) {
    $sourceFile = Join-Path $source $file
    if (Test-Path -LiteralPath $sourceFile) { Copy-Item -LiteralPath $sourceFile -Destination (Join-Path $subir $file) -Force }
}
foreach ($directory in @('app','database','jobs','launcher','public','resources','stop')) {
    Copy-Item -LiteralPath (Join-Path $source $directory) -Destination (Join-Path $subir $directory) -Recurse -Force
}
foreach ($generated in @('app\graphify-out','public\graphify-out','resources\graphify-out','resources\mercadolibre-api\source')) {
    $generatedPath = Join-Path $subir $generated
    if (Test-Path -LiteralPath $generatedPath) { Assert-Under $generatedPath $subir; Remove-Item -LiteralPath $generatedPath -Recurse -Force }
}

$forbidden = @(
    '(^|/)(config\.env|\.env|PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$',
    '(^|/)(tests|docs|vendor|storage|bin|graphify-out|releases)(/|$)',
    '^resources/mercadolibre-api/source(/|$)',
    '^shared(/|$)',
    '\.(erpbackup|log|bak|dump|sql\.gz)$'
)
$expected = Hash-Files $subir
foreach ($relative in $expected.Keys) { foreach ($pattern in $forbidden) { if ($relative -match $pattern) { throw "Archivo prohibido: $relative" } } }
foreach ($required in @(
    'VERSION','actualizar.php','cron-status.php','jobs/process_sync_queue.php','resources/runtime-manifest.json',
    'database/migrations/215_cron_capacity_producer_contract_2_28_35.sql',
    'database/migrations/216_historical_intervention_scoped_ack_2_28_36.sql',
    'database/migrations/217_progressive_read_models_retention_2_28_37.sql'
)) { if (-not (Test-Path -LiteralPath (Join-Path $subir $required.Replace('/', '\')))) { throw "Falta runtime: $required" } }
foreach ($lint in @('actualizar.php','app/Controllers/SettingsController.php','app/Services/CronCapacityPlan.php','app/Services/HistoricalWorkReconciliationService.php','app/Services/ReadModelCacheService.php','jobs/process_sync_queue.php')) {
    & $php -l (Join-Path $subir $lint.Replace('/', '\')) | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP lint falló: $lint" }
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
        try { $sha = [Security.Cryptography.SHA256]::Create(); try { $observed[$entry.FullName.Replace('\', '/')] = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '') } finally { $sha.Dispose() } } finally { $stream.Dispose() }
    }
} finally { $archive.Dispose() }
if ($observed.Count -ne $expected.Count) { throw 'ZIP y SUBIR difieren en cantidad.' }
foreach ($relative in $expected.Keys) { if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) { throw "ZIP difiere: $relative" } }

$extract = Join-Path $releaseRoot ('.extract-22837-' + [Guid]::NewGuid().ToString('N'))
try {
    [IO.Compression.ZipFile]::ExtractToDirectory($zip, $extract)
    if ((Get-Content -LiteralPath (Join-Path $extract 'VERSION') -Raw).Trim() -ne $version) { throw 'Versión extraída incorrecta.' }
    $extractedManifest = Get-Content -LiteralPath (Join-Path $extract 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
    if ([string] $extractedManifest.version -ne $version -or [string] $extractedManifest.minimum_migration -ne $migration) { throw 'El manifiesto extraído no corresponde a 2.28.37.' }
    foreach ($lint in @('actualizar.php','app/Controllers/SettingsController.php','app/Services/ReadModelCacheService.php','jobs/process_sync_queue.php')) {
        & $php -l (Join-Path $extract $lint.Replace('/', '\')) | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "El runtime extraído no pasó lint: $lint" }
    }
} finally { if (Test-Path -LiteralPath $extract) { Assert-Under $extract $releaseRoot; Remove-Item -LiteralPath $extract -Recurse -Force } }

Move-Item -LiteralPath $staging -Destination $final
$finalZip = Join-Path $final ($name + '.zip')
[pscustomobject]@{ version=$version; path=$final; files=$expected.Count; zip_bytes=(Get-Item -LiteralPath $finalZip).Length; zip_sha256=(Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash } | ConvertTo-Json -Depth 3
