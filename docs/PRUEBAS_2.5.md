# Pruebas recomendadas 2.5

- Aplicar migración 029 en copia de base.
- Sincronizar un día pequeño y validar `date_created_utc/local/local_date`.
- Auditar un mes con diferencias conocidas.
- Ejecutar comparación de IDs del mes.
- Ejecutar recalculo de fechas normalizadas.
- Ejecutar corrección de mes auditado y validar que cron procese trabajos.
- Crear borrador de facturación incompleto y confirmar que no deja aprobar/facturar.
- Crear borrador con auditoría completa y confirmar flujo normal.
- Revisar `ML_WRITE_ENABLED=false`.
- Revisar logs para confirmar que no se exponen tokens.
