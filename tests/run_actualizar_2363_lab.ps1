param(
    [string]$ArtifactDirectory = ""
)

$ErrorActionPreference = 'Stop'
$worktree = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backup = 'C:\codex\meli backup'
$rawRoots = @(
    (Join-Path $backup 'storage\raw'),
    (Join-Path $backup 'shared\storage\raw')
)
$prefix = 'erp-meli-2363-http-lab-'
$lab = Join-Path ([IO.Path]::GetTempPath()) ($prefix + [guid]::NewGuid().ToString('N'))
$container = 'erp-meli-2363-http-' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$server = $null

function Get-FreeTcpPort {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return ([Net.IPEndPoint]$listener.LocalEndpoint).Port } finally { $listener.Stop() }
}

function Assert-SafeLabPath([string]$Path) {
    $resolvedParent = (Resolve-Path ([IO.Path]::GetTempPath())).Path.TrimEnd('\') + '\'
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($resolvedParent, [StringComparison]::OrdinalIgnoreCase) -or
        -not ([IO.Path]::GetFileName($full)).StartsWith($prefix, [StringComparison]::Ordinal)) {
        throw "unsafe_lab_path"
    }
}

try {
    foreach ($raw in $rawRoots) {
        if (-not ([IO.Path]::GetFullPath($raw)).StartsWith([IO.Path]::GetFullPath($backup), [StringComparison]::OrdinalIgnoreCase)) {
            throw 'raw_exclusion_authority_invalid'
        }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $backup 'database\migrations') -PathType Container)) {
        throw 'backup_migration_directory_missing'
    }
    if ($ArtifactDirectory -eq '') {
        $ArtifactDirectory = Join-Path $worktree 'release-artifacts\2.36.3\lab'
    }
    [IO.Directory]::CreateDirectory($ArtifactDirectory) | Out-Null
    Assert-SafeLabPath $lab
    [IO.Directory]::CreateDirectory($lab) | Out-Null
    $webroot = Join-Path $lab 'webroot'
    $release = Join-Path $webroot 'erp-meli'
    $private = Join-Path $lab 'private'
    [IO.Directory]::CreateDirectory($webroot) | Out-Null
    [IO.Directory]::CreateDirectory($private) | Out-Null

    $archive = Join-Path $lab 'release.zip'
    & git -C $worktree archive --format=zip --output=$archive HEAD
    if ($LASTEXITCODE -ne 0) { throw 'git_archive_failed' }
    Expand-Archive -LiteralPath $archive -DestinationPath $release

    $historicalMigrations = Get-ChildItem -LiteralPath (Join-Path $backup 'database\migrations') -File |
        Where-Object { $_.Name -match '^(\d{3})_' -and [int]$Matches[1] -le 279 }
    if ($historicalMigrations.Count -ne 279) {
        throw "historical_migration_count_invalid:$($historicalMigrations.Count)"
    }
    foreach ($migration in $historicalMigrations) {
        Copy-Item -LiteralPath $migration.FullName -Destination (Join-Path $release 'database\migrations')
    }
    $migrationCount = (Get-ChildItem -LiteralPath (Join-Path $release 'database\migrations') -File -Filter '*.sql').Count
    if ($migrationCount -ne 293) { throw "lab_migration_count_invalid:$migrationCount" }

    $dbPort = Get-FreeTcpPort
    $webPort = Get-FreeTcpPort
    $appKey = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant()
    $config = @(
        'APP_ENV=local',
        "APP_URL=http://127.0.0.1:$webPort/erp-meli",
        'APP_TIMEZONE=America/Bogota',
        "APP_KEY=$appKey",
        'SESSION_SECURE=false',
        'DB_HOST=127.0.0.1',
        "DB_PORT=$dbPort",
        'DB_NAME=erp_meli_lab',
        'DB_USER=root',
        'DB_PASS=',
        'DB_CONNECT_TIMEOUT=3',
        'ML_WRITE_ENABLED=false',
        'CRON_V4_ENABLED=false',
        'QUEUE_ENGINE_ENABLED=false',
        ('ERP_PRIVATE_PATH=' + $private.Replace('\','/'))
    ) -join "`n"
    [IO.File]::WriteAllText((Join-Path $release 'config.env'), $config + "`n", [Text.UTF8Encoding]::new($false))
    [IO.Directory]::CreateDirectory((Join-Path $release 'storage\tmp')) | Out-Null
    [IO.File]::WriteAllText((Join-Path $release 'PAUSE_MELI_API'), "local-only`n", [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText((Join-Path $release 'PAUSE_ERP_AUTOMATION'), "local-only`n", [Text.UTF8Encoding]::new($false))

    $containerId = & docker run -d --name $container -p "127.0.0.1:$dbPort`:3306" -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=erp_meli_lab mariadb:11.8
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($containerId)) { throw 'mariadb_start_failed' }
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        & docker exec $container mariadb-admin ping -uroot --silent *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'mariadb_not_ready' }

    $migrationLog = Join-Path $lab 'migrate.log'
    Push-Location $release
    try {
        & php bin\migrate.php *> $migrationLog
        if ($LASTEXITCODE -ne 0) {
            Get-Content -LiteralPath $migrationLog -Tail 40 | Write-Error
            throw 'migration_001_293_failed'
        }
    } finally { Pop-Location }

    $serverOut = Join-Path $lab 'php-server.stdout.log'
    $serverErr = Join-Path $lab 'php-server.stderr.log'
    $server = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-S', "127.0.0.1:$webPort", '-t', $webroot) -PassThru -WindowStyle Hidden -RedirectStandardOutput $serverOut -RedirectStandardError $serverErr
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $response = Invoke-WebRequest -Uri "http://127.0.0.1:$webPort/erp-meli/login.php" -MaximumRedirection 0 -TimeoutSec 2 -SkipHttpErrorCheck
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch {}
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) {
        Get-Content -LiteralPath $serverErr -Tail 30 | Write-Error
        throw 'php_http_server_not_ready'
    }

    $env:ERP_2363_HTTP_BASE = "http://127.0.0.1:$webPort/erp-meli"
    $env:ERP_2363_HTTP_ARTIFACTS = $ArtifactDirectory
    Push-Location $release
    try {
        & php tests\http_actualizar_2363_local.php
        if ($LASTEXITCODE -ne 0) { throw 'http_actualizar_test_failed' }
    } finally { Pop-Location }

    foreach ($name in @('actualizar-before','actualizar-after')) {
        $html = Join-Path $ArtifactDirectory ($name + '.html')
        $png = Join-Path $ArtifactDirectory ($name + '.png')
        & npx --yes playwright screenshot --viewport-size='1280,1100' ("file:///" + $html.Replace('\','/')) $png *> $null
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $png -PathType Leaf)) {
            throw "screenshot_failed:$name"
        }
    }

    $receipt = Get-Content -LiteralPath (Join-Path $ArtifactDirectory 'HTTP_ACTUALIZAR_2363_RECEIPT.json') -Raw | ConvertFrom-Json
    if ($receipt.status -ne 'PASS' -or $receipt.raw_storage_touched -ne $false) { throw 'http_receipt_invalid' }
    "LOCAL_HTTP_LAB=PASS SCHEMA=293 BASE=/erp-meli RAW_STORAGE_TOUCHED=NO"
} finally {
    Remove-Item Env:ERP_2363_HTTP_BASE -ErrorAction SilentlyContinue
    Remove-Item Env:ERP_2363_HTTP_ARTIFACTS -ErrorAction SilentlyContinue
    if ($null -ne $server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
        $server.WaitForExit(5000) | Out-Null
    }
    & docker rm -f $container *> $null
    if (Test-Path -LiteralPath $lab) {
        Assert-SafeLabPath $lab
        Remove-Item -LiteralPath $lab -Recurse -Force
    }
}
