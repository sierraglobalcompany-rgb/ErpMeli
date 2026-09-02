# Auditoría ERP Meli 2.29.8 — recuperación de drift de migraciones

## Alcance

Hotfix acumulativo sobre 2.29.7. No modifica Cron V3, ownership, colas, órdenes, pagos, packs, envíos, OAuth ni configuración de ritmo.

## Problema corregido

Producción podía quedar bloqueada antes de aplicar migraciones nuevas cuando una migración antigua ya registrada en `schema_migrations` tenía un hash diferente en `system_update_migrations`.

El caso observado fue:

- migración: `015_sync_products_claims_2_1.sql`;
- etapa: `state_drifted`;
- causa visible: migración aplicada modificada;
- Mercado Libre: no consultado.

La causa compatible es una diferencia de finales de línea entre el archivo aplicado históricamente y el archivo entregado por FTP/Hostinger.

## Corrección

`Migrator` ahora acepta como equivalente una migración ya aplicada únicamente cuando el checksum registrado coincide con una variante determinista del mismo archivo usando:

- contenido actual;
- finales `LF`;
- finales `CRLF`.

Si la diferencia no corresponde a esas variantes, el actualizador sigue bloqueando. Además, los drift reales ya no sobrescriben el checksum registrado antes de detenerse.

## Evidencia esperada

- La migración antigua se registra como `adopted`.
- No se ejecuta SQL de la migración ya aplicada.
- Se registra evento `checksum_line_ending_equivalent` o `checksum_previous_drift_reconciled`.
- La actualización puede continuar hasta `252_migration_line_ending_drift_recovery_2_29_8.sql`.

## Seguridad

- Cero llamadas a Mercado Libre.
- Cero cambios en datos comerciales.
- Cero activación de V3 real u ownership.
- `ML_WRITE_ENABLED=false` permanece obligatorio para pruebas operativas.
