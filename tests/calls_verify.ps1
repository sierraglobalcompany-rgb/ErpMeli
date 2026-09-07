param(
    [ValidateSet('static','database','manual','transport','readiness','entrypoints','package','selftest')][string]$Group = 'static'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'C:/xampphp/php/php.exe'
$qa = 'D:/Codex/tmp/erp-meli/calls-20260906'
$out = Join-Path $qa ('verify-' + $Group + '-' + (Get-Date -Format 'HHmmss'))
New-Item -ItemType Directory -Path $out -Force | Out-Null
$env:APP_ENV = 'test'
$env:ML_WRITE_ENABLED = 'false'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33079'
$env:DB_USER = 'root'
$env:DB_PASS = ''
$env:TEMP = $out
$env:TMP = $out
$env:CAP2_MANUAL_QA_ROOT = Join-Path $qa 'manual-fixtures'
$env:CALLS_QA_STORAGE_ROOT = Join-Path $out 'runtime'
function Get-Sha256Hex {
    param([Parameter(Mandatory=$true)][string]$LiteralPath)
    $stream = [System.IO.File]::Open($LiteralPath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
    try {
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            return ([System.BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
        } finally {
            $sha.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}
$cases = @{
    selftest = @('calls_verify_fixture fail','calls_verify_fixture pass')
    static = @(
        'calls_verify_inputs','calls_migration_blobs','calls_readiness_transport','calls_readiness_ui.js',
        'calls_ui','calls_unknown_presentation','calls_history_ui','calls_increase_certainty',
        'calls_technical_budget','calls_technical_launchers','calls_technical_oauth',
        'calls_manual_contract','calls_manual_preview_projection','calls_manual_receipt_certainty','calls_transport_budget','calls_transport_sources','calls_billing_identity',
        'calls_controller valid','calls_controller legacy','calls_controller invalid','calls_controller missing',
        'calls_retired','capacity_manual_budget','cap2_manual_budget','cap2_manual_item_contract',
        'cap2_manual_presentation','capacity_manual_controller','cap2_domains_policy',
        'cap2_transport_deadline','cap2_transport_scheduler','capacity_controller','capacity_anonymous',
        'capacity_ui','k1d_static_contract','f5_manual_kiss_contract'
    )
    database = @(
        'calls_settings','calls_history','calls_billing_history_mysql','cap2_health_mysql','cap2_health_controller',
        'capacity_policy_mysql','capacity_concurrency_mysql','cap2_writers_mysql','cap2_writers_controller',
        'cap2_automatic_budget_mysql','cap2_automatic_scheduler_mysql','cap2_automatic_worker_context_mysql'
    )
    manual = @(
        'calls_manual_preview_mysql','calls_manual_description_preview_mysql','calls_manual_exact_snapshot_mysql','calls_manual_queue_selection_mysql',
        'calls_manual_financial_selection_mysql','calls_manual_continuation_perimeter_mysql',
        'calls_manual_available_source_snapshot_mysql','cap2_manual_admission','cap2_manual_safety',
        'cap2_manual_outcomes','cap2_manual_busy','cap2_manual_items','cap2_manual_orphan',
        'cap2_manual_launcher','capacity_manual_runtime','capacity_manual_selection'
    )
    transport = @('calls_transport_mysql','cap2_transport_mysql','cap2_domains_mysql','cap2_uncertain_recovery','calls_oauth_persistence_mysql','calls_domains_regression_mysql')
    readiness = @('calls_readiness_contract','calls_readiness_safety','calls_readiness_uncertain','calls_readiness_transport --mysql')
    entrypoints = @('calls_entrypoints_http_mysql --readiness-browser')
    package = @('calls_package_contract','cap2_package_handoff','calls_package_evidence','cap2_package_evidence')
}
$httpCases = @('prepare','check','cancel','activate','stop','legacy','invalid-step','invalid-run','invalid-token','injected-scope',
    'run-zero','run-negative','run-overflow','run-array','step-zero','step-four','step-array','token-empty','token-short','token-nonhex',
    'action-array','action-unknown','password-array','expired-confirmation','prepare-no-password','activate-no-password','stop-no-password',
    'wrong-password','check-refresh','cancel-orphan')
$cases.static += @($httpCases | ForEach-Object { 'calls_readiness_http_contract ' + $_ })
Push-Location $root
try {
    $head = (& git rev-parse HEAD).Trim()
    $dirty = @(& git status --short)
    # Seal product and test/fixture/schema inputs, not private files or uploads.
    # A concurrent edit invalidates certification even if every test exits zero.
    function Get-RuntimeInputs {
        $paths = @(& git ls-files --cached --others --exclude-standard -- app jobs public resources tests database bootstrap.php composer.json composer.lock) |
            Where-Object { $_ -match '\.(php|js|css|json|sql|ps1|lock)$' } | Sort-Object -Unique
        $inputs = foreach ($path in $paths) {
            if (Test-Path -LiteralPath $path -PathType Leaf) {
                [pscustomobject]@{path=$path; sha256=(Get-Sha256Hex -LiteralPath $path)}
            }
        }
        return ($inputs | ConvertTo-Json -Depth 3 -Compress)
    }
    $inputsBefore = Get-RuntimeInputs
    $inputsBefore | Set-Content -LiteralPath (Join-Path $out 'runtime-before.json') -Encoding utf8
    $results = @()
    foreach ($case in $cases[$Group]) {
        $parts = $case.Split(' ')
        $isNode = $parts[0].EndsWith('.js')
        $file = 'tests/' + $parts[0] + $(if ($isNode) { '' } else { '.php' })
        $executable = if ($isNode) { (Get-Command node.exe -ErrorAction Stop).Source } else { $php }
        $arguments = @($file) + @($parts | Select-Object -Skip 1)
        $name = $case.Replace(' ','-')
        $timer = [Diagnostics.Stopwatch]::StartNew()
        # Native stderr is evidence, not a PowerShell terminating error. Capture
        # both streams and continue so even a failed suite gets a final receipt.
        $process = Start-Process -FilePath $executable -ArgumentList $arguments -WorkingDirectory $root `
            -WindowStyle Hidden -Wait -PassThru `
            -RedirectStandardOutput (Join-Path $out ($name + '.out.log')) `
            -RedirectStandardError (Join-Path $out ($name + '.err.log'))
        $code = $process.ExitCode
        $timer.Stop()
        $results += [pscustomobject]@{test=$case; exit=$code; ms=$timer.ElapsedMilliseconds; sha256=(Get-Sha256Hex -LiteralPath $file)}
        Write-Output ($case + ' EXIT=' + $code)
    }
    $failed = @($results | Where-Object { $_.exit -ne 0 })
    $inputsAfter = Get-RuntimeInputs
    $inputsAfter | Set-Content -LiteralPath (Join-Path $out 'runtime-after.json') -Encoding utf8
    $unchanged = $inputsBefore -ceq $inputsAfter
    [pscustomobject]@{head=$head; dirty=$dirty; group=$Group; results=$results; failed=$failed.Count; runtime_unchanged=$unchanged; production_changed=$false} |
        ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $out 'results.json') -Encoding utf8
    Write-Output ('RESULTS=' + (Join-Path $out 'results.json'))
    if ($failed.Count -gt 0) { exit 1 }
    if (-not $unchanged) { Write-Output 'RUNTIME_CHANGED_DURING_TESTS'; exit 2 }
} finally { Pop-Location }
