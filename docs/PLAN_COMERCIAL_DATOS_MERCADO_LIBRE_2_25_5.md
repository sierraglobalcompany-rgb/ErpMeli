# Plan Comercial De Datos Mercado Libre 2.25.5

Fecha: `2026-07-28`
Base encontrada al iniciar: `2.25.2`
Cambio concurrente respetado: `2.25.4`, migración `129`
Versión integrada: `2.25.5`
Migración: `130_commercial_ml_data_pipeline_2_25_5.sql`

## Propósito

Convertir ventas, finanzas oficiales y publicaciones de Mercado Libre en un
flujo comercial continuo. Una notificación o una importación prepara trabajo
idempotente; el CRON lo continúa por partes y el usuario interviene únicamente
ante diferencias que no admiten una corrección automática segura.

La experiencia principal queda en Ventas, Productos y Productos Mercado Libre.
Los endpoints, cursores, hashes, intentos y evidencia permanecen disponibles en
vistas avanzadas sin dominar el trabajo diario.

## Principios Cerrados

- `SaleFinancialService` es la salida financiera comercial autoritativa.
- Las notificaciones son señales y siempre se confirma el recurso remoto.
- Una respuesta parcial es espera remota, no error final.
- Toda operación se aísla por `company_id + meli_account_id`.
- Los procesos automáticos se ejecutan por CLI.
- El ejecutor web procesa como máximo un paso idempotente.
- `ML_WRITE_ENABLED=false` continúa impidiendo mutaciones remotas.
- Los cierres mensuales y la evidencia existente permanecen inmutables.
- Solo se usan endpoints confirmados en `docs/mercadolibre_api_map.md`.

## Arquitectura Implementada

### Ventas Y Finanzas

1. Una notificación sincroniza orden, enriquecimiento e integridad del paquete.
2. La orden se transforma en una venta comercial mediante su `sale_key`.
3. Se crea o reactiva una conciliación financiera agrupada e idempotente.
4. Importaciones y reparaciones históricas convergen en el mismo servicio.
5. Respuestas `206`, `processing` o con campos incompletos quedan en
   `awaiting_remote`.
6. Los reintentos ocurren a los 2, 5, 15 y 60 minutos, después a las 6 horas y
   cada 24 horas, con horizonte de 30 días.
7. Cumplido el horizonte, la venta pasa a revisión sin perder evidencia.

Prioridad: notificación reciente, venta actual pendiente, reparación reciente,
histórico y mantenimiento. Un `429` conserva `Retry-After`; un `403` se limita
a la cuenta y capacidad afectadas.

### Importación Comercial

`Importar ventas del año` conserva sus rutas y contratos. La vista añade
órdenes, integridad, fechas, finanzas y verificación final. `Importar todo lo
disponible` calcula años y meses con `sales_control.remote_window_months`,
excluye meses futuros y reutiliza los checkpoints existentes.

La disponibilidad histórica remota se considera aproximada. Los meses fuera
de cobertura mantienen historia local, pero no se presentan como comprobación
fiscal remota garantizada.

### Publicaciones

El descubrimiento conserva IDs ya registrados. Cada `scroll_id` guarda
vencimiento y número de reinicios; al expirar o ser rechazado se reinicia el
descubrimiento sin duplicar publicaciones.

Las notificaciones pueden actualizar automáticamente título, precio, estado,
condición, categoría, imagen y cantidades. Cambios de SKU, variaciones,
atributos, vínculo interno, catálogo, costo o margen permanecen en revisión.
Toda consulta y transición de revisión exige empresa y cuenta autorizadas.

### Ritmo Y Capacidad

`ApiPacingService` se ejecuta después de reservar presupuesto y antes del
transporte HTTP. Reserva turnos persistentes globales y por cuenta para que dos
ejecuciones de CRON no pierdan el espaciado.

El administrador configura un techo de 1 a 59 RPM, inicialmente 20. El ritmo
efectivo puede ser menor por presupuesto, latencia, ventana CLI o telemetría.
Solo aumenta con al menos 20 muestras sanas y disminuye ante 429, errores o p95
superior a cinco segundos.

`AutomationCapacityService` informa duración configurada y real, llamadas,
errores, 429, p50/p95, utilización, backlog, antigüedad, estimación de drenaje,
motivo de corte y ritmo recomendado.

## Modelo De Datos Aditivo

La migración `130`:

- amplía conciliaciones con espera remota, horizonte, estado, prioridad y origen;
- crea el estado persistente de pacing;
- agrega vencimiento y reinicios de cursor;
- incorpora `company_id` a revisiones, lo rellena desde la cuenta y crea índices;
- instala feature flags conservadores;
- registra el contrato de componente y la versión.

No elimina tablas, no renombra columnas ni reestructura ventas existentes.
Una instalación que viene de producción `2.25.1` debe aplicar, en orden, las
migraciones `127`, `129` y `130`; el migrador valida el orden real.

## Experiencia Comercial

- Ventas: importación anual o disponible, etapas, filtro financiero y páginas compactas.
- Productos ML: progreso, última sincronización, vínculo y estados localizados.
- Productos internos: listado primero, alta secundaria y filtros de completitud.
- Automatización: resumen ejecutivo de capacidad y diagnóstico avanzado.

## Despliegue

1. Respaldar base de datos y verificar PHP CLI compatible.
2. Aplicar migraciones pendientes en staging.
3. Ejecutar pruebas PHP, lint e integraciones MySQL/MariaDB.
4. Activar CRON CLI cada minuto con límites conservadores.
5. Certificar heartbeat, lock y dos ejecuciones automáticas consecutivas.
6. Drenar primero notificaciones y trabajos vencidos.
7. Observar p95, 429, backlog y antigüedad antes de subir el techo.
8. Liberar comercialmente solo cuando el release gate esté completo.

## Reversibilidad

Las automatizaciones se desactivan mediante settings sin borrar evidencia. El
pacing puede deshabilitarse conservando los presupuestos existentes. Las rutas
y servicios previos continúan disponibles y la migración no contiene `DROP`.
