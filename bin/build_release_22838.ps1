param(
    [string] $ReleaseName = 'ERP Meli 2.28.38 Cron Util Campana Recuperada',
    [string] $ReleaseVersion = '2.28.38',
    [string] $MinimumMigration = '218_cron_useful_execution_campaign_source_isolation_2_28_38.sql',
    [string] $BuildId = 'erp-meli-2.28.38-cron-useful-campaign-recovered-20260802'
)
$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$name = $ReleaseName
$version = $ReleaseVersion
$migration = $MinimumMigration
$build = $BuildId
$php = (Get-Command php -ErrorAction Stop).Source
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-' + $version.Replace('.', '') + '-' + [Guid]::NewGuid().ToString('N'))
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

if (Test-Path -LiteralPath $final) { throw "La entrega ya existe: $final" }
$manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
if ((Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim() -ne $version -or [string] $manifest.version -ne $version) { throw 'Versión incoherente.' }
if ([string] $manifest.minimum_migration -ne $migration -or [string] $manifest.build_id -ne $build) { throw 'Manifiesto incoherente.' }
foreach ($component in @('cron_probe', 'process_sync_queue')) {
    $entry = $manifest.components.$component
    $hash = (Get-FileHash -LiteralPath (Join-Path $source ([string] $entry.path).Replace('/', '\')) -Algorithm SHA256).Hash.ToLowerInvariant()
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
    $path = Join-Path $subir $generated
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Recurse -Force }
}
$expected = Hash-Files $subir
$forbidden = @('(^|/)(config\.env|\.env|PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$','(^|/)(tests|docs|vendor|storage|bin|graphify-out|releases)(/|$)','^resources/mercadolibre-api/source(/|$)','^shared(/|$)','\.(erpbackup|log|bak|dump|sql\.gz)$')
foreach ($relative in $expected.Keys) { foreach ($pattern in $forbidden) { if ($relative -match $pattern) { throw "Archivo prohibido: $relative" } } }
foreach ($required in @('VERSION','actualizar.php','jobs/process_sync_queue.php','resources/runtime-manifest.json',('database/migrations/' + $migration))) {
    if (-not (Test-Path -LiteralPath (Join-Path $subir $required.Replace('/', '\')))) { throw "Falta runtime: $required" }
}
foreach ($lint in @('actualizar.php','app/Services/ApiHealthAccessScope.php','app/Services/ManualCampaignService.php','app/Services/ResumableCampaignWorkerService.php','app/Services/OperationalSnapshotService.php','app/Services/TechnicalRetentionCliService.php','app/Services/ApiIncidentMaterializerService.php','app/Services/ApiIncidentReadModelService.php','jobs/process_sync_queue.php')) {
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
if ($observed.Count -ne $expected.Count) { throw 'ZIP y SUBIR difieren.' }
foreach ($relative in $expected.Keys) { if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) { throw "ZIP difiere: $relative" } }
Move-Item -LiteralPath $staging -Destination $final
$finalZip = Join-Path $final ($name + '.zip')
[pscustomobject]@{version=$version;path=$final;files=$expected.Count;zip_bytes=(Get-Item $finalZip).Length;zip_sha256=(Get-FileHash $finalZip -Algorithm SHA256).Hash} | ConvertTo-Json
