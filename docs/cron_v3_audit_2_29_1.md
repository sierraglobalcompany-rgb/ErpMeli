# Reauditoria independiente Cron V3 RC2 - ERP Meli 2.29.1

Fecha: 2026-08-03. Base preservada: 2.29.0. Candidato corregido: 2.29.1 RC2.

## Veredicto

El candidato corrige los cinco bloqueantes P1 hallados en 2.29.0 y permanece
deshabilitado. Puede instalarse para pruebas shadow controladas despues de una
segunda auditoria. No autoriza ownership remoto ni produccion: aun exige el
canario operacional de 24 horas sobre el servidor real.

## Correcciones P1

1. MariaDB real: las pruebas usan MariaDB 10.4.32 y una base temporal aislada.
   Dos workers, muerte/reinicio, lease vencido y 100 reservas concurrentes se
   prueban sin tocar la base del ERP. El limite 10 permite exactamente 10.
2. Deadline: `CronV3Cli` inicia `CronDeadlineContext` antes de lock, delay,
   bootstrap, importacion y runner; reserva tiempo de cierre y siempre limpia
   el contexto en `finally`. El runner consulta `canAcceptWork()` en cada vuelta
   y tanto `assertCanStartRemote()` como `curlTimeouts()` usan `acceptUntil`.
3. Rate authority: migracion 244 agrega buckets transaccionales global,
   aplicacion, cuenta, endpoint y operacion. Un perfil remoto desconocido o un
   identificador de aplicacion ausente falla cerrado. El esquema 241 sin 244
   tambien falla cerrado y no crea una reserva global degradada.
4. Fencing: finalizar work/attempt y persistir rate/circuit ocurre en una sola
   transaccion despues de ganar empresa, cuenta, token y generacion. Un worker
   vencido no puede alterar efectos; un exito concurrente anterior tampoco
   puede cerrar un circuito abierto por otro intento. Solo el probe half-open
   con el mismo owner puede cerrarlo.
5. Doctor V3: ambos launchers aceptan `--doctor --json`; es read-only, ejecuta
   cero HTTP y revisa migraciones, tablas, ownership, leases, snapshots,
   dimensiones de rate, circuitos, handlers, `MELI_CLIENT_ID` y divergencia
   ENV/DB. Un estado bloqueado devuelve exit code 2.
6. Migracion financiera: una migracion puente anterior a 242 conserva el
   indice requerido por la FK de cuenta antes de reemplazar la unicidad legacy.
   La migracion 242 publicada no se modifica ni cambia de checksum.
7. Builder: exige DSN MariaDB real y ejecuta validate, suite, PHPStan,
   compatibilidad, lint, concurrencia, 60 ciclos shadow y migracion integral
   antes de crear `SUBIR` o el ZIP.

## Evidencia ejecutada

- `cron_v3_engine_2290`: `mysql=passed`, con 100 reservas concurrentes y diez
  permisos exactos.
- `cron_v3_shadow_60_mysql_2291`: 60 ciclos por carril, cero HTTP, cero
  mutaciones de fuentes y snapshots con generacion 60.
- Contratos 2.29.1: deadline, doctor, scopes de rate, concurrencia y fencing de
  resultados aprobados.
- Restauracion completa e idempotente: aprobada sobre MariaDB 11.8.8, incluido
  doctor remoto `ok=true` y el puente financiero 241/242.
- Suite: 154 pruebas base, 393 rutas, todos los contratos V3, PHPStan,
  compatibilidad PHP 8.3-8.5 y lint aprobados.
- El motor Cron V3 se probo tambien sobre MariaDB 10.4: el snapshot de fairness
  serializa el carril y el candidato se bloquea con `FOR UPDATE`; no depende de
  `SKIP LOCKED`, disponible solo en ramas posteriores.

La instalacion completa requiere MariaDB 10.6 o MySQL 8.0.4 porque la migracion
historica 122 usa `JSON_TABLE`. El doctor bloquea versiones inferiores. La
prueba local 10.4 certifica concurrencia del motor V3, no una restauracion
completa del ERP desde cero.

Las 60 rondas son una prueba sintetica representativa sobre MariaDB real; no
reemplazan 60 ejecuciones con snapshots productivos ni el canario de 24 horas.

## Activacion y rollback

La autoridad de activacion es exclusivamente el entorno. La base conserva
flags diagnosticos y el doctor marca cualquier divergencia. El orden seguro es:

1. Instalar migraciones 240-245, incluido el puente 241 de 2.29.1, con `CRON_V3_ENABLED=false` y
   `CRON_V3_SHADOW_ENABLED=false`.
2. Ejecutar doctor local/remoto y exigir exit code 0, `ok=true` y cero issues.
3. Habilitar solo shadow y completar evidencia del servidor real.
4. Transferir ownership una familia certificada por vez; remoto inicia a 10
   rpm y solo aumenta cada 24 horas sin 429, lease_lost, duplicados ni aumento
   de antiguedad.
5. Conservar V2 durante 14 dias. Rollback: desactivar V3 y devolver ownership a
   V2 sin borrar work, attempts ni evidencia.

No se deben crear aun los Cron V3 de Hostinger hasta que la segunda auditoria
apruebe este paquete y la migracion 245 este instalada.
