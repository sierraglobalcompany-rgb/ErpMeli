$ErrorActionPreference = 'Stop'

$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$runtimeRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = 'C:\xampphp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    $php = (Get-Command php -ErrorAction Stop).Source
}

$releases = @(
    @{
        Version = '2.28.23'
        Name = 'ERP Meli 2.28.23 Hotfix Ritmo Campaña'
        Source = 'C:\codex\ERP Meli Gestion Pro\work\.release-source-22823'
        Migration = '203_rhythm_generation_campaign_window_2_28_23.sql'
        Build = 'erp-meli-2.28.23-rhythm-campaign-certified-20260801'
        Required = @(
            'app/Services/CampaignExecutionWindowPolicyService.php',
            'database/migrations/203_rhythm_generation_campaign_window_2_28_23.sql'
        )
    },
    @{
        Version = '2.28.24'
        Name = 'ERP Meli 2.28.24 Salud API Confiable'
        Source = 'C:\codex\ERP Meli Gestion Pro\work\.release-source-22824'
        Migration = '204_api_health_incident_truth_2_28_24.sql'
        Build = 'erp-meli-2.28.24-api-health-truth-certified-20260801'
        Required = @(
            'app/Services/ApiHealthAccessScope.php',
            'app/Services/ApiHealthSafeMessageService.php',
            'database/migrations/203_rhythm_generation_campaign_window_2_28_23.sql',
            'database/migrations/204_api_health_incident_truth_2_28_24.sql'
        )
    },
    @{
        Version = '2.28.25'
        Name = 'ERP Meli 2.28.25 Cron Medido Humano'
        Source = $runtimeRoot
        Migration = '205_cron_observed_human_monitor_2_28_25.sql'
        Build = 'erp-meli-2.28.25-rhythm-health-cron-certified-20260801'
        Required = @(
            'app/Services/ApiHealthAccessScope.php',
            'app/Services/CampaignExecutionWindowPolicyService.php',
            'app/Services/CronBatchPolicyService.php',
            'database/migrations/203_rhythm_generation_campaign_window_2_28_23.sql',
            'database/migrations/204_api_health_incident_truth_2_28_24.sql',
            'database/migrations/205_cron_observed_human_monitor_2_28_25.sql'
        )
    }
)

function Assert-Under([string] $child, [string] $parent) {
    $childFull = [IO.Path]::GetFullPath($child).TrimEnd('\')
    $parentFull = [IO.Path]::GetFullPath($parent).TrimEnd('\') + '\'
    if (-not (($childFull + '\').StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase))) {
        throw "Ruta fuera del directorio permitido: $child"
    }
}

function Build-Release([hashtable] $release) {
    $source = (Resolve-Path -LiteralPath $release.Source).Path
    $final = Join-Path $releaseRoot $release.Name
    $staging = Join-Path $releaseRoot ('.staging-' + $release.Version.Replace('.', '') + '-' + [Guid]::NewGuid().ToString('N'))
    $subir = Join-Path $staging 'SUBIR'
    $zipPath = Join-Path $staging ($release.Name + '.zip')

    Assert-Under $staging $releaseRoot
    Assert-Under $final $releaseRoot
    if (Test-Path -LiteralPath $final) {
        throw "La entrega ya existe y no se sobrescribirá: $final"
    }

    $sourceVersion = (Get-Content -LiteralPath (Join-Path $source 'VERSION') -Raw).Trim()
    $manifest = Get-Content -LiteralPath (Join-Path $source 'resources\runtime-manifest.json') -Raw | ConvertFrom-Json
    if ($sourceVersion -ne $release.Version -or [string] $manifest.version -ne $release.Version) {
        throw "VERSION/manifiesto no corresponde a $($release.Version)."
    }
    if ([string] $manifest.minimum_migration -ne $release.Migration) {
        throw "Migración mínima incorrecta para $($release.Version)."
    }
    if ([string] $manifest.build_id -ne $release.Build) {
        throw "Build ID incorrecto para $($release.Version)."
    }
    foreach ($component in @('cron_probe', 'process_sync_queue')) {
        $entry = $manifest.components.$component
        $componentPath = Join-Path $source ([string] $entry.path).Replace('/', '\')
        $hash = (Get-FileHash -LiteralPath $componentPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($hash -ne ([string] $entry.sha256).ToLowerInvariant()) {
            throw "Hash de $component incorrecto para $($release.Version)."
        }
    }

    New-Item -ItemType Directory -Path $subir -Force | Out-Null
    foreach ($file in @(
        '.htaccess', 'VERSION', 'index.php', 'login.php', 'actualizar.php',
        'asset.php', 'bootstrap.php', 'cron-status.php', 'mantenimiento.php',
        'recuperar.php', 'stop.php', 'composer.json', 'composer.lock'
    )) {
        $sourceFile = Join-Path $source $file
        if (Test-Path -LiteralPath $sourceFile) {
            Copy-Item -LiteralPath $sourceFile -Destination (Join-Path $subir $file) -Force
        }
    }
    foreach ($directory in @('app', 'database', 'jobs', 'launcher', 'public', 'resources', 'stop')) {
        Copy-Item -LiteralPath (Join-Path $source $directory) -Destination (Join-Path $subir $directory) -Recurse -Force
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

    $alwaysRequired = @(
        '.htaccess', 'VERSION', 'actualizar.php', 'cron-status.php', 'stop.php',
        'launcher/entrypoint.php', 'public/index.php', 'jobs/process_sync_queue.php',
        'resources/runtime-manifest.json'
    )
    foreach ($required in @($alwaysRequired + $release.Required)) {
        if (-not $expected.ContainsKey($required)) {
            throw "Falta runtime obligatorio: $required"
        }
    }

    foreach ($critical in @(
        'cron-status.php', 'jobs/process_sync_queue.php',
        'app/Services/MeliApiClient.php', 'app/Services/CronOperationalReadService.php'
    )) {
        $criticalPath = Join-Path $subir $critical.Replace('/', '\')
        if (Test-Path -LiteralPath $criticalPath) {
            & $php -l $criticalPath | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw "PHP lint falló: $critical"
            }
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
                try {
                    $observed[$nameInZip] = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '')
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
        throw "ZIP y SUBIR difieren: $($observed.Count) / $($expected.Count)"
    }
    foreach ($relative in $expected.Keys) {
        if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) {
            throw "ZIP difiere de SUBIR: $relative"
        }
    }

    $extract = Join-Path $releaseRoot ('.extract-' + $release.Version.Replace('.', '') + '-' + [Guid]::NewGuid().ToString('N'))
    try {
        [IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $extract)
        $extractedVersion = (Get-Content -LiteralPath (Join-Path $extract 'VERSION') -Raw).Trim()
        if ($extractedVersion -ne $release.Version) {
            throw "Runtime extraído no corresponde a $($release.Version)."
        }
        & $php -l (Join-Path $extract 'cron-status.php') | Out-Null
        & $php -l (Join-Path $extract 'jobs\process_sync_queue.php') | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "Runtime extraído no superó PHP lint."
        }
    } finally {
        if (Test-Path -LiteralPath $extract) {
            Assert-Under $extract $releaseRoot
            Remove-Item -LiteralPath $extract -Recurse -Force
        }
    }

    Move-Item -LiteralPath $staging -Destination $final
    $finalZip = Join-Path $final ($release.Name + '.zip')
    [pscustomobject]@{
        version = $release.Version
        path = $final
        files = $expected.Count
        zip_bytes = (Get-Item -LiteralPath $finalZip).Length
        zip_sha256 = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
    }
}

$results = foreach ($release in $releases) {
    Build-Release $release
}
$results | ConvertTo-Json -Depth 4
