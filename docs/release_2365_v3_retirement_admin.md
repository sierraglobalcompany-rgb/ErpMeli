# ERP MELI 2.36.5 — retiro administrativo V3

2.36.5 añade en `/settings/cron` la acción autenticada **Retirar autoridad V3 para preparar V4**. No crea Cron V4, no activa Queue Engine, no llama Mercado Libre y no elimina trabajos ni intentos históricos.

## Operación

La acción reutiliza el POST administrativo existente, exige administrador permanente, same-origin, CSRF, contraseña actual y la frase exacta `RETIRAR_AUTORIDAD_V3_PARA_PREPARAR_V4`.

Antes de mutar exige: versión 2.36.5, schema 293 exacto, Queue Engine `disabled/idle`, los cuatro flags efectivos apagados, cero overrides contradictorios, cero ownership habilitado fuera de V3 y al menos un ownership V3 activo.

El write-set queda limitado a `cron_v3_queue_ownership` y `app_settings`. La transacción usa PDO fresco no persistente, advisory lock, READ COMMITTED, `FOR UPDATE`, preimagen, postcondiciones y receipt durable. Una segunda ejecución bloquea sin DML.

## Autoridad histórica

`docs/release-authorities/V3_RETIREMENT_FOR_V4_ORACLE.sql` conserva bytes y SHA-256 `0C17BB3487B223BCC69A2D3470A1D8E88F065DC2AC6A388B0939A611BE451861`. Es sólo oracle de pruebas y recovery manual; el flujo normal no lo ejecuta.

El oracle exige `app.version=2.36.3`; el servicio de esta release exige 2.36.5. Las nueve settings operativas, ownership, engine y contadores históricos son equivalentes. El servicio añade únicamente un receipt técnico en `app_settings`.

## Prohibiciones

- No ejecutar el SQL durante la instalación.
- No crear el cron de Hostinger.
- No activar Queue Engine ni Cron V4.
- No sobrescribir `config.env`, `storage`, `shared`, pausas, secretos o datos externos por FTP.
- No acceder a `storage/raw` durante construcción o QA.

## Instalación

Revisar primero los artefactos 2.36.5. Para reparación manual se usa el overlay FTP conservando estado externo. Después se completa la metadata por el actualizador autenticado y, sólo tras verificar el preflight de `/settings/cron`, un administrador puede ejecutar una vez la acción de retiro.
