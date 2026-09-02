# Auditoría ERP Meli 2.29.10 — Canario V3 Truth

## Objetivo

Corregir la contradicción operacional del panel Cron V3: una instalación con
canario local/remoto activo y evidencia HTTP real no debe volver a aparecer
como bloqueada por `Shadow 0/60`.

## Alcance

- No cambia ownership adicional.
- No activa V3 completo.
- No modifica Hostinger.
- No modifica datos comerciales.
- No consulta Mercado Libre desde páginas web.
- Mantiene `ML_WRITE_ENABLED=false` como requisito de canario.

## Corrección

- `CronV3SetupAssistantService` trata `CRON_V3_ENABLED=true` como fase posterior
  al shadow, y muestra el shadow como cerrado por canario activo.
- `CronV3CanaryControlService` acepta evidencia real del canario activo
  —ciclos activos o HTTP/recursos reales— como sustituto del requisito de
  shadow vivo para el snapshot.
- El canario queda sano solo si no hay errores, 429, leases perdidos ni
  duplicados.
- La UI muestra `Canario remoto activo · sano` cuando hay HTTP reales y
  recursos finalizados.

## Gates

- Se conserva la prueba de drift 015 ya aplicada.
- La migración 254 es metadata/versionado.
- El manifest firma los componentes modificados.
- El paquete se rechaza si `VERSION`, manifest, build o migración mínima no
  coinciden.
