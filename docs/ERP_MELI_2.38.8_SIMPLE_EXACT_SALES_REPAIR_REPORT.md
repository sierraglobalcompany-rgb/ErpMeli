# ERP MELI 2.38.8 — Simple Exact Sales Repair

2.38.7 ya podía crear reparaciones desde una auditoría exacta autorizada, pero el único scheduler Queue V4 no consumía esos trabajos. 2.38.8 agrega una etapa acotada que reutiliza `SalesAuditExactRepairService::processDue(1)` y `OrderSyncService::syncOrderByIdForQueueV4Clean()`.

La etapa consume exclusivamente `source_kind="exact"`, comparte el presupuesto HTTP físico del ciclo, resta su única reclamación del trabajo disponible para el worker y aplaza OAuth o ritmo sin penalizar el item cuando no corresponde. No crea reparaciones automáticamente: el usuario conserva el flujo auditar, revisar diferencias y autorizar.

No se agregan tablas, migraciones, clases runtime, tipos de job ni Cron. El schema permanece en 297 y `ML_WRITE_ENABLED=false` continúa prohibiendo escrituras remotas.
