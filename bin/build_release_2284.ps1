$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$name = 'ERP Meli 2.28.4 Freno Mano Humano'
$final = Join-Path $releaseRoot $name
$staging = Join-Path $releaseRoot ('.staging-' + $name + '-' + [Guid]::NewGuid().ToString('N'))
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

foreach ($unwanted in @(
    'app/graphify-out',
    'resources/graphify-out',
    'public/graphify-out'
)) {
    $candidate = Join-Path $subir $unwanted
    if (Test-Path -LiteralPath $candidate) {
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
            throw "El paquete contiene archivo prohibido: $relative"
        }
    }
    $expected[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
}

foreach ($required in @(
    '.htaccess',
    'VERSION',
    'index.php',
    'login.php',
    'actualizar.php',
    'mantenimiento.php',
    'recuperar.php',
    'stop.php',
    'launcher/entrypoint.php',
    'public/index.php',
    'app/Recovery/EmergencyControlKernel.php',
    'app/Services/EmergencyControlService.php',
    'jobs/process_sync_queue.php',
    'database/migrations/183_cron_fast_canary_visibility_2_28_3.sql',
    'database/migrations/184_human_emergency_control_2_28_4.sql',
    'resources/runtime-manifest.json'
)) {
    if (-not $expected.ContainsKey($required)) {
        throw "Falta runtime obligatorio: $required"
    }
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory(
    $subir,
    $zipPath,
    [IO.Compression.CompressionLevel]::Optimal,
    $false
)

$archive = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $observed = @{}
    foreach ($entry in $archive.Entries) {
        $entryName = $entry.FullName.Replace('\', '/')
        if ($entryName.EndsWith('/')) {
            continue
        }
        $stream = $entry.Open()
        try {
            $sha = [Security.Cryptography.SHA256]::Create()
            try {
                $hash = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '')
            } finally {
                $sha.Dispose()
            }
        } finally {
            $stream.Dispose()
        }
        $observed[$entryName] = $hash
    }
} finally {
    $archive.Dispose()
}

if ($observed.Count -ne $expected.Count) {
    throw "ZIP no contiene exactamente SUBIR. subir=$($expected.Count) zip=$($observed.Count)"
}

foreach ($key in $expected.Keys) {
    if (-not $observed.ContainsKey($key)) {
        throw "Falta en ZIP: $key"
    }
    if ($observed[$key] -ne $expected[$key]) {
        throw "ZIP difiere de SUBIR: $key"
    }
}

if (Test-Path -LiteralPath $final) {
    Assert-Under $final $releaseRoot
    Remove-Item -LiteralPath $final -Recurse -Force
}

Move-Item -LiteralPath $staging -Destination $final

$finalZip = Join-Path $final ($name + '.zip')
$hash = (Get-FileHash -LiteralPath $finalZip -Algorithm SHA256).Hash
$files = (Get-ChildItem -LiteralPath (Join-Path $final 'SUBIR') -Recurse -File -Force).Count
Write-Output ('RELEASE_PASS path="{0}" files={1} zip_bytes={2} zip_sha256={3}' -f @(
    $final,
    $files,
    (Get-Item -LiteralPath $finalZip).Length,
    $hash
))
