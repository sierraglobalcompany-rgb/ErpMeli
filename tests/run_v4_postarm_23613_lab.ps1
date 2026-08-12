param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('classic', 'managed')]
    [string]$Mode,
    [string]$ArtifactDirectory = ''
)

$ErrorActionPreference = 'Stop'
$worktree = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$sourceLab = Join-Path $worktree 'tests\run_actualizar_23611_lab.ps1'
$migrationSourceRoot = 'C:\codex\ERP Meli Gestion Pro\git-prep\ErpMeli-2.35.1-pre-HF1-20260808-final'
$prefix = 'erp-meli-23613-postarm-runner-'
$temporary = Join-Path ([IO.Path]::GetTempPath()) ($prefix + [guid]::NewGuid().ToString('N') + '.ps1')

if (-not (Test-Path -LiteralPath $sourceLab -PathType Leaf)) { throw 'source_lab_missing' }
if ((Get-ChildItem -LiteralPath (Join-Path $migrationSourceRoot 'database\migrations') -File -Filter '*.sql').Count -ne 279) {
    throw 'migration_source_authority_invalid'
}
if ((& git -C $worktree status --porcelain).Count -ne 0) { throw 'worktree_not_clean' }
if ($ArtifactDirectory -eq '') {
    $ArtifactDirectory = Join-Path ([IO.Path]::GetTempPath()) ('erp-meli-23613-postarm-' + $Mode)
}
if (Test-Path -LiteralPath $ArtifactDirectory) {
    Remove-Item -LiteralPath $ArtifactDirectory -Recurse -Force
}
[IO.Directory]::CreateDirectory($ArtifactDirectory) | Out-Null

$body = [IO.File]::ReadAllText($sourceLab)
$body = $body.Replace(
    '$worktree = (Resolve-Path (Join-Path $PSScriptRoot ''..'')).Path',
    '$worktree = ''' + $worktree.Replace("'", "''") + ''''
)
$body = $body.Replace(
    '$backup = ''C:\codex\meli backup''',
    '$backup = ''' + $migrationSourceRoot.Replace("'", "''") + ''''
)
$body = $body.Replace(
    "        'ML_WRITE_ENABLED=false',",
    "        'ML_WRITE_ENABLED=false',`r`n        'CRON_V3_ENABLED=false',`r`n        'CRON_V3_SHADOW_ENABLED=false',`r`n        'MELI_CLIENT_ID=postarm-local',`r`n        'MELI_CLIENT_SECRET=postarm-local-secret',`r`n        'MELI_REDIRECT_URI=http://127.0.0.1/local-oauth-callback',"
)

$matrixPattern = '(?s)    \$env:ERP_23611_MYSQL_DSN = .*?    \} finally \{ Pop-Location \}\r?\n\r?\n'
$matrixReplacement = @'
    $env:ERP_23611_MYSQL_DSN = "mysql:host=127.0.0.1;port=$dbPort;charset=utf8mb4"
    $env:ERP_23611_MYSQL_USER = 'root'
    $env:ERP_23611_MYSQL_PASS = ''
    Push-Location $release
    try {
        & php tests\v4_readiness_generation_rollback_23611_mysql.php
        if ($LASTEXITCODE -ne 0) { throw 'v4_generation_rollback_matrix_failed' }
    } finally { Pop-Location }

'@
$body = [regex]::Replace($body, $matrixPattern, $matrixReplacement, 1)
if ($body -match 'direct_update_metadata_23611_mysql') { throw 'matrix_removal_failed' }

$managedSetup = @'
    $router = $null
    if ('__MODE__' -eq 'managed') {
        $shared = Join-Path $release 'shared'
        [IO.Directory]::CreateDirectory((Join-Path $shared 'storage')) | Out-Null
        Move-Item -LiteralPath (Join-Path $release 'config.env') -Destination (Join-Path $shared 'config.env')
        $managedStage = Join-Path $lab 'managed-release-source'
        [IO.Directory]::CreateDirectory($managedStage) | Out-Null
        Get-ChildItem -LiteralPath $release -Force | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination $managedStage -Recurse -Force
        }
        $managedRelease = Join-Path $release 'releases\release-23613'
        [IO.Directory]::CreateDirectory((Split-Path -Parent $managedRelease)) | Out-Null
        Move-Item -LiteralPath $managedStage -Destination $managedRelease
        [IO.File]::WriteAllText(
            (Join-Path $shared 'current-release.json'),
            "{`"release_id`":`"release-23613`",`"path`":`"releases/release-23613`"}`n",
            [Text.UTF8Encoding]::new($false)
        )
        $router = Join-Path $lab 'managed-router.php'
        $launcher = (Join-Path $release 'launcher\web.php').Replace('\', '/')
        [IO.File]::WriteAllText(
            $router,
            "<?php`ndeclare(strict_types=1);`n`$path=(string)(parse_url((string)(`$_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');`nif(preg_match('#/(?:index|login|actualizar|stop|asset|cron-status)\\.php$#',`$path)===1){return false;}`nrequire '" + $launcher.Replace("'", "\\'") + "';`n",
            [Text.UTF8Encoding]::new($false)
        )
    }

'@
$managedSetup = $managedSetup.Replace('__MODE__', $Mode)
$body = $body.Replace('    $serverOut = Join-Path $lab ''php-server.stdout.log''', $managedSetup + '    $serverOut = Join-Path $lab ''php-server.stdout.log''')
$serverNeedle = "    `$server = Start-Process -FilePath (Get-Command php).Source -ArgumentList @('-S', `"127.0.0.1:`$webPort`", '-t', `$webroot) -PassThru -WindowStyle Hidden -RedirectStandardOutput `$serverOut -RedirectStandardError `$serverErr"
$serverReplacement = @'
    $serverArgs = @('-S', "127.0.0.1:$webPort", '-t', $webroot)
    if ($null -ne $router) { $serverArgs += $router }
    $server = Start-Process -FilePath (Get-Command php).Source -ArgumentList $serverArgs -PassThru -WindowStyle Hidden -RedirectStandardOutput $serverOut -RedirectStandardError $serverErr
'@
$body = $body.Replace($serverNeedle, $serverReplacement.TrimEnd("`r", "`n"))
if ($body -match [regex]::Escape($serverNeedle)) { throw 'server_router_replacement_failed' }

$httpPattern = '(?s)    \$env:ERP_23611_HTTP_BASE = .*?    "LOCAL_HTTP_LAB=PASS SCHEMA=293 BASE=/erp-meli RAW_STORAGE_TOUCHED=NO"\r?\n'
$replacement = @'
    $env:ERP_23613_FOCAL_BASE = "http://127.0.0.1:$webPort/erp-meli"
    $env:ERP_23613_FOCAL_ARTIFACTS = '__EVIDENCE__'
    $env:ERP_23613_AUTHORITY_HEAD = '__HEAD__'
    $env:ERP_23613_AUTHORITY_TREE = '__TREE__'
    $env:ERP_23613_SERVICE_SHA256 = '__SERVICE_SHA__'
    $env:ERP_23613_CLASSIFIER_SHA256 = '__CLASSIFIER_SHA__'
    $env:ERP_23613_CONFIG_MODE = '__MODE__'
    Push-Location $release
    try {
        & php tests\v4_readiness_postarm_23613_http.php
        if ($LASTEXITCODE -ne 0) { throw 'v4_readiness_postarm_23613_http_failed' }
    } finally { Pop-Location }
'@
$head = ((& git -c core.autocrlf=false -C $worktree rev-parse HEAD) | Out-String).Trim().ToLowerInvariant()
$tree = ((& git -C $worktree rev-parse 'HEAD^{tree}') | Out-String).Trim().ToLowerInvariant()
$serviceSha = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $worktree 'app\Services\V4ReadinessBootstrapService.php')).Hash.ToLowerInvariant()
$testSha = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $worktree 'tests\v4_readiness_postarm_authority_23613.php')).Hash.ToLowerInvariant()
$replacement = $replacement.Replace('__MODE__', $Mode)
$replacement = $replacement.Replace('__EVIDENCE__', $ArtifactDirectory.Replace("'", "''"))
$replacement = $replacement.Replace('__HEAD__', $head)
$replacement = $replacement.Replace('__TREE__', $tree)
$replacement = $replacement.Replace('__SERVICE_SHA__', $serviceSha)
$replacement = $replacement.Replace('__CLASSIFIER_SHA__', $testSha)
$body = [regex]::Replace($body, $httpPattern, $replacement + "`r`n", 1)
if ($body -match 'http_actualizar_23611_local') { throw 'http_matrix_removal_failed' }

[IO.File]::WriteAllText($temporary, $body, [Text.UTF8Encoding]::new($false))
try {
    $env:MELI_CLIENT_ID = 'postarm-local'
    $env:MELI_CLIENT_SECRET = 'postarm-local-secret'
    $env:MELI_REDIRECT_URI = 'http://127.0.0.1/local-oauth-callback'
    & powershell -NoProfile -ExecutionPolicy Bypass -File $temporary -ArtifactDirectory $ArtifactDirectory
    if ($LASTEXITCODE -ne 0) { throw 'postarm_lab_failed' }
    $receiptPath = Join-Path $ArtifactDirectory 'ERP_MELI_2.36.13_POSTARM_RECEIPT.json'
    $receipt = Get-Content -LiteralPath $receiptPath -Raw | ConvertFrom-Json
    if ($receipt.verdict -ne 'PASS' -or $receipt.fixture.config_mode -ne $Mode -or $receipt.get2.state -ne 'ready_for_context') {
        throw 'postarm_receipt_invalid'
    }
    "V4_POSTARM_LAB=PASS MODE=$Mode GET1=$($receipt.get.state) POST=$($receipt.post.state) GET2=$($receipt.get2.state) RECEIPT=$receiptPath"
} finally {
    foreach ($name in @(
        'MELI_CLIENT_ID', 'MELI_CLIENT_SECRET', 'MELI_REDIRECT_URI',
        'ERP_23613_FOCAL_BASE', 'ERP_23613_FOCAL_ARTIFACTS',
        'ERP_23613_AUTHORITY_HEAD', 'ERP_23613_AUTHORITY_TREE',
        'ERP_23613_SERVICE_SHA256', 'ERP_23613_CLASSIFIER_SHA256',
        'ERP_23613_CONFIG_MODE'
    )) {
        Remove-Item ("Env:" + $name) -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
}
