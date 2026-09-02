# Auditoría ERP Meli 2.29.4 — Canario V3 real controlado

## Objetivo

`2.29.4` no activa Cron V3 completo. Agrega una fase guiada para pasar de Shadow aprobado a un canario real y reversible:

- V2 sigue como ejecutor principal.
- `ML_WRITE_ENABLED=false` es obligatorio.
- `CRON_V3_RATE_LIMIT=10` queda como límite inicial.
- Ownership V3 se limita a:
  - local: `financial_recalc`;
  - remoto: `pack_exact` y `shipment_exact`.

## Controles agregados

- `GET /settings/cron/v3-canary.json` lee estado, Doctor, shadow, ownership y métricas sin mutar.
- `POST /settings/cron/v3-canary/prepare` prepara `config.env` con V3 real encendido, pero sin ownership.
- `POST /settings/cron/v3-canary/enable-local` transfiere solo `financial_recalc` a V3.
- `POST /settings/cron/v3-canary/enable-remote` transfiere solo `pack_exact` y `shipment_exact`.
- `POST /settings/cron/v3-canary/rollback` apaga `CRON_V3_ENABLED` y devuelve ownership a `disabled`, permitiendo que V2 retome.

Todos los POST exigen administrador permanente, CSRF y mismo origen.

## Guardrails

- No se construye `MeliApiClient` desde el panel.
- No se activa `ML_WRITE_ENABLED`.
- No se modifica información comercial.
- No se habilitan campañas completas, módulos no confirmados ni otras familias.
- Shadow debe tener al menos 60 ciclos limpios con `http_calls=0` y `source_mutations=0`.
- Doctor local y remoto deben aprobar.
- Las variables de proceso que contradicen `config.env` bloquean la preparación.

## Hostinger

Durante canario se conserva V2 y se cambian únicamente las dos tareas V3 cuando el panel lo indique:

```text
/usr/bin/php /home/u390570745/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/cron_v3_local.php --runtime=45 --max-items=50
/usr/bin/php /home/u390570745/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12
```

No se debe crear ninguna tarea adicional.
