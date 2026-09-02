# Reauditoria Cron V3 RC3 - ERP Meli 2.29.2

Fecha: 2026-08-03. Base preservada: 2.29.1 RC2 rechazado. Candidato: 2.29.2 RC3.

## Veredicto

RC3 corrige el ultimo P1 de configuracion encontrado por la segunda auditoria.
Permanece deshabilitado y no autoriza ownership ni trafico remoto de produccion.
Puede pasar a instalacion controlada y shadow real despues de una reauditoria
externa del ZIP. El canario de 24 horas sigue siendo obligatorio.

## Correccion RC3

- `CronV3Cli`, `CronV3DoctorService` y `CronV3RateGate` usan `App\Core\Env`.
  La prioridad es variable del proceso y luego `config.env`; Hostinger puede
  configurar shadow en el archivo sin que el CLI lo ignore.
- El Doctor valida el mismo `MELI_CLIENT_ID` que usara el rate gate y conserva
  salida read-only, cero HTTP y exit code 2 cuando esta bloqueado.
- `CronV3ExecutionContext` verifica `acceptUntil` antes de reservar capacidad.
  `CronV3RateGate` vuelve a verificar dentro de la transaccion antes de
  incrementar los cinco buckets.
- La migracion 246 declara `env_resolver` como autoridad, promueve 2.29.2 y no
  activa V3 ni ownership.

## Evidencia heredada y repetida

- MariaDB real: 100 reservas concurrentes con limite 10 producen diez permisos.
- Schema 241 sin 244 falla cerrado y crea cero buckets.
- Work, attempt y efectos rate/circuit confirman en una sola transaccion
  cercada; un exito concurrente anterior no cierra un circuito abierto.
- 60 ciclos shadow por carril: cero HTTP, cero mutaciones y snapshots 60.
- Restauracion completa e idempotente y Doctor remoto aprobados sobre MariaDB
  11.8; motor concurrente probado adicionalmente sobre MariaDB 10.4.
- Suite base, 393 rutas, PHPStan, compatibilidad PHP, lint, manifiesto, ZIP y
  exclusiones de secretos son gates obligatorios del builder.

Las rondas shadow automatizadas son sinteticas. No reemplazan 60 ejecuciones
CLI contra snapshots del servidor ni el canario operacional de 24 horas.

## Instalacion controlada

1. Confirmar MariaDB 10.6+ o MySQL 8.0.4+.
2. Instalar hasta migracion 246 con ambos flags V3 apagados.
3. Ejecutar Doctor local y remoto; exigir exit 0, `ok=true` y cero issues.
4. Activar solo `CRON_V3_SHADOW_ENABLED=true`; mantener
   `CRON_V3_ENABLED=false`, ownership V2 y `ML_WRITE_ENABLED=false`.
5. Completar 60 ciclos CLI reales y reauditar antes de transferir una familia.
6. Mantener V2 durante 14 dias y remoto inicialmente a 10 rpm.

No crear los Cron V3 de Hostinger antes de que el ZIP RC3 pase la reauditoria
externa y la version 2.29.2 este instalada.
