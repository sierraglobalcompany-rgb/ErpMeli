# Auditoría ERP Meli 2.29.9 — drift histórico aplicado 015

## Alcance

Hotfix acumulativo sobre 2.29.8. No modifica Cron V3, ownership, colas, órdenes, pagos, packs, envíos, OAuth ni configuración de ritmo.

## Problema corregido

Producción seguía bloqueando en `015_sync_products_claims_2_1.sql` durante `state_drifted`.

La versión 2.29.8 aceptaba únicamente equivalencia por finales de línea. El servidor conserva una metadata histórica incompatible con ese caso, aunque la migración ya está registrada como aplicada en `schema_migrations`.

## Corrección

El migrador ahora puede reconciliar exclusivamente la migración `015_sync_products_claims_2_1.sql` cuando ya está aplicada y el contrato de esquema confirma que sus efectos existen:

- tabla `meli_sync_offsets`;
- columnas esperadas;
- índice `uq_sync_offset`;
- FK `meli_sync_offsets.meli_account_id -> meli_accounts.id`;
- settings esperados de productos, reclamos y reportes;
- archivo activo firmado en `runtime-manifest.json`.

Si alguna condición falta, la actualización se bloquea con el mensaje seguro:

`No se puede certificar la 015; se bloquea por seguridad.`

## Evidencia esperada

- Estado final de la metadata 015: `adopted`.
- Evento: `applied_015_schema_contract_reconciled`.
- SQL de 015 no ejecutado.
- Drift de cualquier otra migración no autorizada sigue bloqueado.

## Seguridad

- Cero llamadas a Mercado Libre.
- Cero cambios en datos comerciales.
- Cero activación de V3 real u ownership.
- `ML_WRITE_ENABLED=false` permanece intacto.
