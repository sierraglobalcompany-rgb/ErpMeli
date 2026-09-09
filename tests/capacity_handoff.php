<?php
declare(strict_types=1);

// Generates local handoff material only. It never connects to production.
require_once __DIR__ . '/capacity_artifact.php';

function cap2GenerateHandoff(string $out): void
{
    $out = rtrim(str_replace('\\', '/', $out), '/');
    $targetPath = $out . '/target.json';
    if (!is_file($targetPath)) {
        throw new RuntimeException('target_required');
    }
    $target = json_decode((string) file_get_contents($targetPath), true, 64, JSON_THROW_ON_ERROR);
    $files = $target['deploy_files'] ?? null;
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('deploy_files_required');
    }
    foreach ($files as $path => $hash) {
        cap2AssertSafePackagePath((string) $path);
        if (preg_match('/^[a-f0-9]{64}$/D', (string) $hash) !== 1) {
            throw new RuntimeException('invalid_deploy_hash:' . $path);
        }
    }
    $added = $target['new_files'] ?? [];
    if (!is_array($added)) {
        throw new RuntimeException('new_files_malformed');
    }
    foreach ($added as $path) {
        cap2AssertSafePackagePath((string) $path);
    }

    $operator = <<<'POWERSHELL'
param(
 [string]$Port='',
 [string]$HostName='',
 [string]$UserName='',
 [string]$RemoteRoot='',
 [string]$SshExecutable='ssh.exe',
 [string]$FixtureOutput='',
 [int]$FixtureExitCode=0
)
$ErrorActionPreference='Stop'
$run=Join-Path $PSScriptRoot ('check-'+(Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $run -ErrorAction Stop | Out-Null
$outputPath=Join-Path $run 'output.log'
New-Item -ItemType File -Path $outputPath -ErrorAction Stop | Out-Null
'COMMAND_NOT_RUN=local validation pending' | Set-Content -LiteralPath (Join-Path $run 'command.txt') -Encoding UTF8
$exitCode=-1
$status='FAIL'
$matchedCount=0
$count=0
$fixtureMode=($FixtureOutput -ne '')
try {
 if($Port -notmatch '^[0-9]{1,5}$' -or [int]$Port -lt 1 -or [int]$Port -gt 65535){throw 'INVALID_PORT'}
 $Port=[int]$Port
 if($HostName -notmatch '^[A-Za-z0-9.-]+$'){throw 'INVALID_HOST'}
 if($UserName -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*$'){throw 'INVALID_USER'}
 if($RemoteRoot -notmatch '^/[A-Za-z0-9._/-]+$' -or $RemoteRoot.Contains('..')){throw 'INVALID_REMOTE_ROOT'}
 $target=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'target.json') -Raw | ConvertFrom-Json
 $paths=@($target.deploy_files.PSObject.Properties)
 $count=$paths.Count
 if($count -lt 1){throw 'EMPTY_TARGET'}
 foreach($p in $paths){
  if($p.Name -notmatch '^[A-Za-z0-9._/-]+$' -or $p.Name.StartsWith('/') -or $p.Name.Contains('..') -or $p.Value -notmatch '^[a-f0-9]{64}$'){throw 'UNSAFE_TARGET'}
 }
 if($fixtureMode){
  'FIXTURE_MODE=YES; SSH_NOT_EXECUTED=YES' | Set-Content -LiteralPath (Join-Path $run 'command.txt') -Encoding UTF8
  $raw=@(Get-Content -LiteralPath $FixtureOutput)
  $exitCode=$FixtureExitCode
 } else {
  if($SshExecutable -ne 'ssh.exe'){throw 'CUSTOM_EXECUTABLE_REQUIRES_FIXTURE_MODE'}
  ('ssh -T -p <PORT> <USER>@<HOST> "cd -- <REMOTE_ROOT> && sha256sum -- <'+$count+' validated paths>"') | Set-Content -LiteralPath (Join-Path $run 'command.txt') -Encoding UTF8
  $quoted=@($paths | ForEach-Object { "'"+$_.Name+"'" })
  $remote="cd -- '$RemoteRoot' && sha256sum -- "+($quoted -join ' ')
  $oldPreference=$ErrorActionPreference
  $ErrorActionPreference='Continue'
  $raw=@(& $SshExecutable -T -p $Port "${UserName}@${HostName}" $remote 2>&1)
  $exitCode=$LASTEXITCODE
  $ErrorActionPreference=$oldPreference
 }
 $safeLines=@()
 foreach($entry in $raw){
  $line=[string]$entry
  if($line -match '^([a-fA-F0-9]{64})\s+\*?([A-Za-z0-9._/-]+)$'){$safeLines+=$line}
  else{$safeLines+='SSH_DIAGNOSTIC_OMITTED=non-hash output (privacy protection)'}
 }
 $safeLines | Set-Content -LiteralPath $outputPath -Encoding UTF8
 if($exitCode -ne 0){throw 'SSH_NONZERO'}
 $received=@{}
 foreach($line in $safeLines){
  if($line -match '^([a-fA-F0-9]{64})\s+\*?(.+)$'){
   if($received.ContainsKey($Matches[2])){throw 'DUPLICATE_REMOTE_PATH'}
   $received[$Matches[2]]=$Matches[1].ToLowerInvariant()
  }
 }
 foreach($p in $paths){if($received[$p.Name] -eq $p.Value){$matchedCount++}}
 if($matchedCount -ne $count -or $received.Count -ne $count){throw 'REMOTE_HASH_MISMATCH'}
 $status=if($fixtureMode){'FIXTURE_PASS'}else{'PASS'}
} catch {
 'VERIFICATION_FAILED_REDACTED' | Set-Content -LiteralPath (Join-Path $run 'error.txt') -Encoding UTF8
} finally {
 @(
  "STATUS=$status",
  "SSH_EXIT_CODE=$exitCode",
  "MATCHES=$matchedCount",
  "EXPECTED=$count",
  ('FIXTURE_MODE='+$(if($fixtureMode){'YES'}else{'NO'})),
  'MODE=HASH_READ_ONLY',
  'DEPLOY_APPROVED=NO',
  'PRODUCTION_CHANGED=NO',
  'CRON_CHANGED=NO',
  'MIGRATIONS_RUN=0',
  "OUTPUT_PATH=$run"
 ) | Set-Content -LiteralPath (Join-Path $run 'CONTROL.txt') -Encoding UTF8
 Write-Output ('CONTROL_FILE='+(Join-Path $run 'CONTROL.txt'))
}
if($status -eq 'FAIL'){exit 1}
POWERSHELL;
    cap2WriteFile($out . '/verificar.ps1', str_replace("\n", "\r\n", $operator) . "\r\n");

    $remove = $added === [] ? '- Ninguno.' : implode("\n", array_map(static fn($path): string => '- `' . $path . '`', $added));
    $guide = <<<MARKDOWN
# Entrega CAP2

El estado vigente del paquete está exclusivamente en CONTROL.txt.

`DEPLOY_APPROVED=NO`

Base: `{$target['base']}`

Commit local: `{$target['head']}`

Versión 2.40.1; esquema 301 sin cambios.

Este material no autoriza una instalación. La aprobación funcional, las revisiones independientes y la recertificación integrada se documentan fuera de la prueba estructural del ZIP. Nunca interpretar `STRUCTURAL_INTEGRITY=PASS` como aprobación de despliegue.

## Instalación posterior

Antes de subir, respaldar y comparar los archivos vivos con la base indicada. Si difieren, detenerse. El ZIP de deploy es root-ready y contiene sólo blobs Git del delta runtime. No contiene tests, configuración privada ni secretos. No ejecutar migraciones, cambiar Cron ni usar **Procesar ahora** como instalador.

Los máximos ausentes pasan a 55; el presupuesto efectivo previo se conserva. Cambiar sólo el máximo no aumenta llamadas. 55/100 no certifican sostenibilidad en producción.

## Verificación remota de sólo lectura

Ejecutar `./verificar.ps1 -Port PUERTO -HostName HOST -UserName USUARIO -RemoteRoot /ruta/erp-meli`. El operador sólo ejecuta `sha256sum`; no escribe remotamente ni ejecuta PHP/Cron. Cada intento conserva `command.txt` redactado, `output.log`, `SSH_EXIT_CODE` y `CONTROL.txt`, también ante fallo. No compartir contraseñas ni tokens.

El modo `-FixtureOutput` existe únicamente para la prueba local; produce `STATUS=FIXTURE_PASS`, nunca `PASS`, y conserva `DEPLOY_APPROVED=NO`.

## Reversión

`rollback.zip` contiene los blobs base de archivos reemplazados y `REMOVE_ADDED_FILES.txt`. Preferir siempre un respaldo vivo verificado. Los archivos añadidos que requerirían retiro explícito son:

$remove

No hay borrado automático. Restaurar consumidores antes de retirar un archivo nuevo. El rollback de código no modifica `app_settings`; reducir previamente cualquier presupuesto aumentado. No restaurar tablas ni ejecutar migraciones.
MARKDOWN;
    cap2WriteFile($out . '/README.md', $guide . "\n");
}

function cap2HandoffMain(array $argv): int
{
    $out = str_replace('\\', '/', $argv[1] ?? '');
    if (preg_match('#^[A-Za-z]:/#D', $out) !== 1) {
        throw new RuntimeException('usage: capacity_handoff.php <absolute-artifact-dir>');
    }
    cap2GenerateHandoff($out);
    echo "HANDOFF_GENERATED=YES\nDEPLOY_APPROVED=NO\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(cap2HandoffMain($argv));
}
