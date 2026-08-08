$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$runtimeRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'C:\xampphp\php\php.exe'
$definitions = @(
    @{ Version='2.28.29'; Name='ERP Meli 2.28.29 Cron Incremental Campana Recuperada'; Source='C:\codex\ERP Meli Gestion Pro\work\.release-source-22829'; Migration='209_incremental_cron_planner_campaign_recovery_2_28_29.sql'; Build='erp-meli-2.28.29-incremental-cron-campaign-recovery-20260801' },
    @{ Version='2.28.30'; Name='ERP Meli 2.28.30 Cron Accionable Salud Scoped'; Source=$runtimeRoot; Migration='210_cron_action_center_scoped_health_2_28_30.sql'; Build='erp-meli-2.28.30-actionable-cron-scoped-health-20260801' }
)

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

function Build-Release([hashtable] $definition) {
    $source = (Resolve-Path -LiteralPath $definition.Source).Path
    $final = Join-Path $releaseRoot $definition.Name
    $staging = Join-Path $releaseRoot ('.staging-' + $definition.Version.Replace('.', '') + '-' + [Guid]::NewGuid().ToString('N'))
    $subir = Join-Path $staging 'SUBIR'
    Assert-Under $staging $releaseRoot
    Assert-Under $final $releaseRoot
    if (Test-Path -LiteralPath $final) { throw "La entrega ya existe: $final" }

    $version = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
    $manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
    if ($version -ne $definition.Version -or [string] $manifest.version -ne $definition.Version) {
        throw "VERSION/manifiesto inconsistente para $($definition.Version)."
    }
    if ([string] $manifest.minimum_migration -ne $definition.Migration -or [string] $manifest.build_id -ne $definition.Build) {
        throw "Migración mínima o build incorrecto para $($definition.Version)."
    }
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
    foreach ($generated in @('app\graphify-out','public\graphify-out','resources\graphify-out')) {
        $generatedPath = Join-Path $subir $generated
        if (Test-Path -LiteralPath $generatedPath) {
            Assert-Under $generatedPath $subir
            Remove-Item -LiteralPath $generatedPath -Recurse -Force
        }
    }

    $forbidden = @(
        '(^|/)(config\.env|\.env|PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$',
        '(^|/)(tests|docs|vendor|storage|bin|graphify-out|releases)(/|$)',
        '^shared(/|$)',
        '\.(erpbackup|log|bak|dump|sql\.gz)$'
    )
    foreach ($relative in (Hash-Files $subir).Keys) {
        foreach ($pattern in $forbidden) {
            if ($relative -match $pattern) { throw "Archivo prohibido: $relative" }
        }
    }
    foreach ($required in @('VERSION','actualizar.php','cron-status.php','jobs/process_sync_queue.php','resources/runtime-manifest.json',('database/migrations/' + $definition.Migration))) {
        if (-not (Test-Path -LiteralPath (Join-Path $subir $required.Replace('/', '\')))) { throw "Falta runtime: $required" }
    }
    foreach ($lint in @('cron-status.php','jobs/process_sync_queue.php','app/Controllers/SettingsController.php')) {
        & $php -l (Join-Path $subir $lint.Replace('/', '\')) | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP lint falló: $lint" }
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = Join-Path $staging ($definition.Name + '.zip')
    [IO.Compression.ZipFile]::CreateFromDirectory($subir, $zip, [IO.Compression.CompressionLevel]::Optimal, $false)
    $expected = Hash-Files $subir
    $observed = @{}
    $archive = [IO.Compression.ZipFile]::OpenRead($zip)
    try {
        foreach ($entry in $archive.Entries) {
            if ($entry.FullName.EndsWith('/')) { continue }
            $stream = $entry.Open()
            try {
                $sha = [Security.Cryptography.SHA256]::Create()
                try { $observed[$entry.FullName.Replace('\', '/')] = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '') }
                finally { $sha.Dispose() }
            } finally { $stream.Dispose() }
        }
    } finally { $archive.Dispose() }
    if ($observed.Count -ne $expected.Count) { throw "ZIP y SUBIR difieren en cantidad." }
    foreach ($relative in $expected.Keys) {
        if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) { throw "ZIP difiere: $relative" }
    }

    $extract = Join-Path $releaseRoot ('.extract-' + [Guid]::NewGuid().ToString('N'))
    try {
        [IO.Compression.ZipFile]::ExtractToDirectory($zip, $extract)
        if ((Get-Content -LiteralPath (Join-Path $extract 'VERSION') -Raw).Trim() -ne $definition.Version) { throw 'Versión extraída incorrecta.' }
        & $php -l (Join-Path $extract 'jobs\process_sync_queue.php') | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'El runtime extraído no pasó lint.' }
    } finally {
        if (Test-Path -LiteralPath $extract) {
            Assert-Under $extract $releaseRoot
            Remove-Item -LiteralPath $extract -Recurse -Force
        }
    }

    Move-Item -LiteralPath $staging -Destination $final
    $finalZip = Join-Path $final ($definition.Name + '.zip')
    [pscustomobject]@{
        version = $definition.Version
        path = $final
        files = $expected.Count
        zip_bytes = (Get-Item -LiteralPath $finalZip).Length
        zip_sha256 = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
    }
}

$results = foreach ($definition in $definitions) {
    $existing = Join-Path $releaseRoot $definition.Name
    if (Test-Path -LiteralPath $existing) {
        $existingZip = Join-Path $existing ($definition.Name + '.zip')
        [pscustomobject]@{
            version = $definition.Version
            path = $existing
            files = (Get-ChildItem -LiteralPath (Join-Path $existing 'SUBIR') -Recurse -File).Count
            zip_bytes = (Get-Item -LiteralPath $existingZip).Length
            zip_sha256 = (Get-FileHash -LiteralPath $existingZip -Algorithm SHA256).Hash
        }
        continue
    }
    Build-Release $definition
}
$results | ConvertTo-Json -Depth 4
