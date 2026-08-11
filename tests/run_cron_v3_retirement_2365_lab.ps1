param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$container = 'erp-meli-2365-retirement-' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$created = $false

function Get-FreeTcpPort {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return ([Net.IPEndPoint]$listener.LocalEndpoint).Port } finally { $listener.Stop() }
}

try {
    $port = Get-FreeTcpPort
    $id = & docker run -d --name $container -p "127.0.0.1:$port`:3306" -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:11.8
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($id)) { throw 'mariadb_start_failed' }
    $created = $true
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        & docker exec $container mariadb-admin ping -uroot --silent *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'mariadb_not_ready' }

    $version = (& docker exec $container mariadb -N -uroot -e 'SELECT VERSION();' 2>&1 | Out-String).Trim()
    if ($version -notmatch '^11\.8\.') { throw "mariadb_version_invalid:$version" }

    $env:ERP_2363_READY_DSN = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4"
    $pdoReady = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        & php (Join-Path $root 'tests\mysql_ready_2363_lab.php') *> $null
        if ($LASTEXITCODE -eq 0) { $pdoReady = $true; break }
        Start-Sleep -Milliseconds 500
    }
    Remove-Item Env:ERP_2363_READY_DSN -ErrorAction SilentlyContinue
    if (-not $pdoReady) { throw 'mariadb_external_pdo_not_ready' }

    $env:ERP_2365_MYSQL_DSN = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4"
    $env:ERP_2365_MYSQL_USER = 'root'
    $env:ERP_2365_MYSQL_PASS = ''
    Push-Location $root
    try {
        & php tests/cron_v3_retirement_2365_mysql.php
        if ($LASTEXITCODE -ne 0) { throw 'retirement_mysql_matrix_failed' }
        & php tests/direct_update_metadata_2365_mysql.php
        if ($LASTEXITCODE -ne 0) { throw 'direct_update_metadata_matrix_failed' }
    } finally {
        Pop-Location
    }
    "CRON_V3_RETIREMENT_2365_LAB=PASS MARIADB=$version PRODUCTION_TOUCHED=NO"
} finally {
    foreach ($name in @('ERP_2363_READY_DSN','ERP_2365_MYSQL_DSN','ERP_2365_MYSQL_USER','ERP_2365_MYSQL_PASS')) {
        Remove-Item ("Env:" + $name) -ErrorAction SilentlyContinue
    }
    if ($created) {
        & docker rm -f $container *> $null
    }
}
