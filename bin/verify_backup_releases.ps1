$ErrorActionPreference = 'Stop'

$releaseRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\releases')).Path
$names = @(
    'ERP Meli 2.25.16 Copias Seguras',
    'ERP Meli 2.25.17 Restauracion Clon Diagnostico'
)

foreach ($name in $names) {
    $delivery = Join-Path $releaseRoot $name
    $children = Get-ChildItem -LiteralPath $delivery -Force
    if ($children.Count -ne 2 -or -not ($children.Name -contains 'SUBIR') -or -not ($children.Name -contains ($name + '.zip'))) {
        throw "La entrega contiene archivos adicionales: $name"
    }
    $subir = Join-Path $delivery 'SUBIR'
    $zipPath = Join-Path $delivery ($name + '.zip')
    foreach ($forbidden in @(
        'vendor', 'tests', 'docs', 'audits', 'storage', 'shared', 'bin',
        'config.env', '.env', 'README.md', 'README_UPDATE.md',
        'PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION', 'graphify-out'
    )) {
        if (Test-Path -LiteralPath (Join-Path $subir $forbidden)) {
            throw "Contenido no permitido en SUBIR: $forbidden"
        }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $subir '.htaccess'))) {
        throw "Falta .htaccess en $name"
    }

    $expected = @{}
    foreach ($file in Get-ChildItem -LiteralPath $subir -Recurse -File -Force) {
        $relative = [IO.Path]::GetRelativePath($subir, $file.FullName).Replace('\', '/')
        if (
            $relative -match '(^|/)(PAUSE_MELI_API|PAUSE_ERP_AUTOMATION|config\.env|\.env)$'
            -or $relative -match '(^|/)(graphify-out|tests|docs|audits|vendor|node_modules)(/|$)'
            -or $relative -match '\.(sql\.gz|erpbackup|log|bak|dump)$'
        ) {
            throw "Contenido no permitido en SUBIR: $relative"
        }
        $expected[$relative] = (Get-FileHash $file.FullName -Algorithm SHA256).Hash
    }

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
                    $observed[$entry.FullName.Replace('\', '/')] = [Convert]::ToHexString(
                        $sha.ComputeHash($stream)
                    )
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
        throw "El ZIP no tiene el mismo número de archivos que SUBIR en $name"
    }
    foreach ($relative in $expected.Keys) {
        if (-not $observed.ContainsKey($relative) -or $expected[$relative] -ne $observed[$relative]) {
            throw "El ZIP difiere de SUBIR en ${name}: $relative"
        }
    }
    Write-Output ('VERIFIED {0} files={1} zip_sha256={2}' -f @(
        $name,
        $expected.Count,
        (Get-FileHash $zipPath -Algorithm SHA256).Hash
    ))
}
