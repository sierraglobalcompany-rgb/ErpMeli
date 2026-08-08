$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$releaseRoot = 'C:\codex\ERP Meli Gestion Pro\releases'
$name = 'ERP Meli 2.26.11 Fix Actualizador Respaldo'
$finalDelivery = Join-Path $releaseRoot $name
$delivery = Join-Path $releaseRoot ('.staging-' + $name + '-' + [Guid]::NewGuid().ToString('N'))
$subir = Join-Path $delivery 'SUBIR'
$zipPath = Join-Path $delivery ($name + '.zip')

function Assert-Under {
    param([string] $Path, [string] $Parent)
    $full = [IO.Path]::GetFullPath($Path)
    $base = [IO.Path]::GetFullPath($Parent).TrimEnd('\') + '\'
    if (-not $full.StartsWith($base, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Ruta fuera del destino permitido: $full"
    }
}

Assert-Under $delivery $releaseRoot
Assert-Under $finalDelivery $releaseRoot
if (Test-Path -LiteralPath $finalDelivery) {
    throw "La entrega ya existe y no se sobrescribirá: $finalDelivery"
}
if (Test-Path -LiteralPath $delivery) {
    throw "El staging ya existe y no se sobrescribirá: $delivery"
}
New-Item -ItemType Directory -Path $subir -Force | Out-Null

foreach ($file in @(
    '.htaccess', 'asset.php', 'actualizar.php', 'bootstrap.php', 'composer.json',
    'composer.lock', 'config.env.example', 'index.php', 'login.php',
    'mantenimiento.php', 'recuperar.php', 'stop.php', 'VERSION'
)) {
    Copy-Item -LiteralPath (Join-Path $root $file) -Destination (Join-Path $subir $file)
}
New-Item -ItemType Directory -Path (Join-Path $subir 'app') -Force | Out-Null
foreach ($directory in @(
    'Contracts', 'Controllers', 'Core', 'Modules', 'Recovery',
    'Repositories', 'Services', 'ValueObjects', 'Views'
)) {
    Copy-Item -LiteralPath (Join-Path $root "app\$directory") -Destination (Join-Path $subir "app\$directory") -Recurse
}
foreach ($directory in @('database', 'jobs', 'launcher', 'public', 'stop')) {
    Copy-Item -LiteralPath (Join-Path $root $directory) -Destination (Join-Path $subir $directory) -Recurse
}

New-Item -ItemType Directory -Path (Join-Path $subir 'resources') -Force | Out-Null
foreach ($file in @('migration-replacements.json', 'runtime-manifest.json')) {
    Copy-Item -LiteralPath (Join-Path $root "resources\$file") -Destination (Join-Path $subir "resources\$file")
}
Copy-Item -LiteralPath (Join-Path $root 'resources\modules') -Destination (Join-Path $subir 'resources\modules') -Recurse
New-Item -ItemType Directory -Path (Join-Path $subir 'resources\mercadolibre-api') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $root 'resources\mercadolibre-api\generated') -Destination (Join-Path $subir 'resources\mercadolibre-api\generated') -Recurse

$forbiddenPatterns = @(
    '(^|/)(PAUSE_MELI_API|PAUSE_ERP_AUTOMATION|config\.env|\.env)$',
    '(^|/)(graphify-out|tests|docs|audits|vendor|node_modules|storage|bin)(/|$)',
    '^(shared)(/|$)',
    '\.(sql\.gz|erpbackup|log|bak|dump)$'
)
$expected = @{}
foreach ($file in Get-ChildItem -LiteralPath $subir -Recurse -File -Force) {
    $basePath = [IO.Path]::GetFullPath($subir).TrimEnd('\') + '\'
    $fullPath = [IO.Path]::GetFullPath($file.FullName)
    if (-not $fullPath.StartsWith($basePath, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Archivo fuera de SUBIR: $fullPath"
    }
    $relative = $fullPath.Substring($basePath.Length).Replace('\', '/')
    foreach ($pattern in $forbiddenPatterns) {
        if ($relative -match $pattern) {
            throw "El paquete contiene un archivo prohibido: $relative"
        }
    }
    $expected[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
}
foreach ($required in @(
    '.htaccess', 'VERSION', 'index.php', 'login.php', 'actualizar.php',
    'mantenimiento.php', 'recuperar.php', 'stop.php', 'launcher/entrypoint.php',
    'public/index.php', 'jobs/process_sync_queue.php',
    'database/migrations/159_recovery_updater_backup_gate_2_26_11.sql',
    'resources/runtime-manifest.json'
)) {
    if (-not $expected.ContainsKey($required)) {
        throw "Falta runtime obligatorio: $required"
    }
}

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
        if ($entry.FullName.EndsWith('/')) {
            continue
        }
        $stream = $entry.Open()
        try {
            $sha = [Security.Cryptography.SHA256]::Create()
            try {
                $observed[$entry.FullName.Replace('\', '/')] = [BitConverter]::ToString(
                    $sha.ComputeHash($stream)
                ).Replace('-', '')
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
if ($expected.Count -ne $observed.Count) {
    throw 'El ZIP no contiene exactamente los archivos de SUBIR.'
}
foreach ($relative in $expected.Keys) {
    if (-not $observed.ContainsKey($relative) -or $observed[$relative] -ne $expected[$relative]) {
        throw "El ZIP difiere de SUBIR: $relative"
    }
}

$children = Get-ChildItem -LiteralPath $delivery -Force
if (
    ($children.Count -ne 2) -or
    (-not ($children.Name -contains 'SUBIR')) -or
    (-not ($children.Name -contains ($name + '.zip')))
) {
    throw 'La entrega final contiene archivos adicionales.'
}

$stagingZipPath = $zipPath
Move-Item -LiteralPath $delivery -Destination $finalDelivery
$zipPath = Join-Path $finalDelivery ($name + '.zip')
if (
    (-not (Test-Path -LiteralPath $zipPath)) -or
    (Test-Path -LiteralPath $stagingZipPath)
) {
    throw 'La activación final de la entrega no fue atómica.'
}

Write-Output ('RELEASE_PASS files={0} zip_bytes={1} zip_sha256={2}' -f @(
    $expected.Count,
    (Get-Item -LiteralPath $zipPath).Length,
    (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash
))
