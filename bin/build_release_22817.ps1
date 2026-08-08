$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$name = 'ERP Meli 2.28.17 Recuperacion Exacta Cron'
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-22817-' + [Guid]::NewGuid().ToString('N'))
$subir = Join-Path $staging 'SUBIR'
$zipPath = Join-Path $staging ($name + '.zip')

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
    'app/Services/CronDeadlineDeferredException.php',
    'app/Services/WorkResolutionPolicyRegistry.php',
    'app/Services/ExactWorkRemediationService.php',
    'database/migrations/197_cron_exact_recovery_human_intervention_2_28_17.sql',
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
