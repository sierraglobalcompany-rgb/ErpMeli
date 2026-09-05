<?php
declare(strict_types=1);
// Generates only local handoff artifacts; never connects to production.
$out=str_replace('\\','/',$argv[1]??'');
$qa=str_replace('\\','/',$argv[2]??'');
if(!is_file($out.'/target.json') || !is_dir($qa))throw new RuntimeException('verified_artifact_and_qa_required');
$target=json_decode(file_get_contents($out.'/target.json'),true,64,JSON_THROW_ON_ERROR);
$operator= <<<'PS'
param([Parameter(Mandatory=$true)][ValidateRange(1,65535)][int]$Port,
 [string]$HostName='45.89.205.28',[string]$UserName='u390570745')
$ErrorActionPreference='Stop'
$root='/home/u390570745/domains/bodegadigitalmedellin.com/public_html/erp-meli'
$run=Join-Path $PSScriptRoot ('check-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $run -ErrorAction Stop | Out-Null
$exitCode=-1; $verdict='FAIL'; $matchedCount=0; $count=0
try {
 $target=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'target.json') -Raw | ConvertFrom-Json
 $paths=@($target.files.PSObject.Properties)
 $count=$paths.Count
 if($count -lt 1){throw 'Empty manifest'}
 foreach($p in $paths){
  if($p.Name -notmatch '^(app|jobs|public|resources)/[A-Za-z0-9_./-]+$' -or $p.Name.Contains('..') -or $p.Value -notmatch '^[a-f0-9]{64}$'){throw 'Unsafe manifest'}
 }
 $quoted=@($paths | ForEach-Object { "'"+$_.Name+"'" })
 $remote="cd '$root' && sha256sum -- "+($quoted -join ' ')
 ('ssh -T -p <PORT> <SSH_TARGET> '+$remote) | Set-Content -LiteralPath (Join-Path $run 'command.txt') -Encoding UTF8
 # Keep native stderr non-terminating so all output and the real exit code survive.
 $ErrorActionPreference='Continue'
 & ssh.exe -T -p $Port "${UserName}@${HostName}" $remote 2>&1 | ForEach-Object {
  $line=[string]$_
  if($line -match '^([a-fA-F0-9]{64})\s+\*?((app|jobs|public|resources)/[A-Za-z0-9_./-]+)$'){$line}
  else{'SSH_DIAGNOSTIC_OMITTED=non-hash output (privacy protection)'}
 } | Tee-Object -FilePath (Join-Path $run 'output.log')
 $exitCode=$LASTEXITCODE
 $ErrorActionPreference='Stop'
 if($exitCode -ne 0){throw 'SSH failed; inspect output.log'}
 $received=@{}
 foreach($line in (Get-Content -LiteralPath (Join-Path $run 'output.log'))){
  if($line -match '^([a-fA-F0-9]{64})\s+\*?(.+)$'){
   if($received.ContainsKey($Matches[2])){throw 'Duplicate remote path'}
   $received[$Matches[2]]=$Matches[1].ToLowerInvariant()
  }
 }
 foreach($p in $paths){if($received[$p.Name] -eq $p.Value){$matchedCount++}}
 if($matchedCount -ne $count -or $received.Count -ne $count){throw 'Remote hash mismatch'}
 $verdict='PASS'
} catch { $_.Exception.Message | Set-Content -LiteralPath (Join-Path $run 'error.txt') -Encoding UTF8 }
finally {
 @("STATUS=$verdict","SSH_EXIT_CODE=$exitCode","MATCHES=$matchedCount","EXPECTED=$count",'MODE=HASH_READ_ONLY','PRODUCTION_CHANGED=NO','CRON_CHANGED=NO','MIGRATIONS_RUN=0',"OUTPUT_PATH=$run") | Set-Content -LiteralPath (Join-Path $run 'CONTROL.txt') -Encoding UTF8
 Write-Output ('CONTROL_FILE='+ (Join-Path $run 'CONTROL.txt'))
}
if($verdict -ne 'PASS'){exit 1}
PS;
file_put_contents($out.'/verificar.ps1',$operator."\r\n");
$guide="# Capacidad automática y manual\n\nBase: {$target['base']}\nCommit local: {$target['head']}\nVersión 2.40.1; esquema 301 sin cambios.\n\n## Instalar posteriormente\n\nNo se instaló nada en producción. Antes de subir, respaldar los archivos actuales y confirmar que corresponden a la base indicada. Si producción difiere, detenerse y reconciliar; no sobrescribir cambios ajenos. El ZIP deploy contiene sólo el parche root-ready. Extraer localmente y subir su contenido por FileZilla en modo binario a la raíz ERP. No contiene tests ni ajustes privados. No ejecutar migraciones, cambiar Cron ni usar Procesar ahora para instalar.\n\nLos máximos ausentes pasan a 55; el presupuesto efectivo anterior se conserva. Automático y manual son independientes. Cambiar sólo el máximo no aumenta llamadas. Para cambiar valores, usar los formularios y Cancelar/Confirmar. 55/100 no certifican sostenibilidad real.\n\n## Verificar después (sólo lectura)\n\nExtraer evidencia en carpeta corta. En PowerShell, ejecutar ./verificar.ps1 -Port PUERTO_SSH_REAL. Usa los datos de conexión del servidor ya conocido; no solicita guardarlos. Comparará únicamente los hashes de target.json. Cada ejecución deja command.txt redactado, output.log, código de salida y CONTROL.txt local, incluso ante fallo SSH. Nunca escribe un archivo remoto ni ejecuta PHP/Cron. Compartir CONTROL.txt y output.log; nunca contraseñas.\n\n## Reversión\n\nrollback.zip contiene blobs RAW de los archivos base reemplazados, no un respaldo vivo. Preferir el respaldo previo de producción verificado. Restaurar explícitamente esas mismas rutas en binario. Archivos nuevos del parche:\n";
foreach($target['new_files'] as $p)$guide.='- '.$p."\n";
$guide.="\nNo se incluye borrado automático. Sólo retirar esos archivos nuevos después de restaurar los consumidores y comprobar que ninguna otra release los usa. No restaurar tablas ni ejecutar migraciones. Si se aumentaron presupuestos tras instalar, reducirlos desde la UI a la capacidad anterior antes de revertir: este rollback de código no modifica app_settings. Cron no se modifica.\n\n## Alcance QA\n\nMariaDB local desechable y transportes simulados. Navegador Chrome local con plantillas/controlador/persistencia reales y salud/autenticación de fixture; no certifica sesión ni salud de producción. La integración del navegador interno no funcionó; no se usó para estas pruebas.\n";
file_put_contents($out.'/README.md',$guide);
$rollback=new ZipArchive();$rollbackPath=$out.'/rollback.zip';
if($rollback->open($rollbackPath,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('rollback_zip');
foreach($target['base_files'] as $p=>$hash){$bytes=file_get_contents($out.'/rollback/'.$p);if(hash('sha256',$bytes)!==$hash)throw new RuntimeException('rollback_hash');$rollback->addFromString($p,$bytes);}
$rollback->close();
if($rollback->open($rollbackPath)!==true || $rollback->numFiles!==count($target['base_files']))throw new RuntimeException('rollback_reopen_count');
foreach($target['base_files'] as $p=>$hash){if(hash('sha256',$rollback->getFromName($p))!==$hash)throw new RuntimeException('rollback_member_hash');}
$rollback->close();
file_put_contents($out.'/CONTROL.txt',"ROLLBACK_SHA256=".hash_file('sha256',$rollbackPath)."\nBASE_PLUS_PATCH_FULL_TREE=PASS\n",FILE_APPEND);
echo "HANDOFF_GENERATED=YES\n";
