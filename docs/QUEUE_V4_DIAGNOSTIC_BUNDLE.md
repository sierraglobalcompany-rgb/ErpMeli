# Queue V4 Diagnostic Bundle V1

Este diagnóstico reemplaza la auditoría normal por SSH para Queue V4 de ERP MELI 2.40.1.

Flujo normal:

1. Entrar como administrador.
2. Ir a `Configuración > Cron`.
3. Abrir `Diagnóstico de Queue`.
4. Opcionalmente activar debug extendido por 15, 30 o 60 minutos.
5. Generar un paquete diagnóstico.
6. Descargar el ZIP sanitizado desde el enlace temporal de 30 minutos.
7. Enviar el ZIP a ChatGPT/Codex para análisis local.

Garantías KISS:

- No crea Cron nuevo.
- No crea Queue nueva.
- No cambia schema.
- No ejecuta Cron manual.
- No ejecuta OAuth, Billing, recovery ni HTTP de Mercado Libre.
- El enlace firmado sirve sólo para un ZIP generado y expira.
- Los datos exportados usan hashes estables; no exportan tokens, secretos, cookies, emails, teléfonos, direcciones, órdenes crudas, packs crudos ni payloads de facturación.

Retención:

- Receipts base: aproximadamente 14 días.
- Debug extendido: autoexpira y se conserva como máximo 48 horas.
- ZIP generados: aproximadamente 24 horas.

Si la generación del diagnóstico falla, el proceso de negocio debe seguir funcionando; el error debe quedar como advertencia o fallo del diagnóstico, no como bloqueo de Queue.
