# Auditoría 2.5 - Facturación y ventas

## Regla principal

Un borrador puede existir aunque el rango esté incompleto, pero no debe aprobarse ni marcarse facturado si la cobertura o auditoría no es confiable.

## Validaciones

- Cobertura de sincronización por rango.
- Última auditoría mensual.
- Estado `complete/incomplete/error/blocked`.
- Bandera `timezone_suspect`.
- Snapshot guardado en `monthly_reports` y `date_report_runs`.

## Reparación

1. Auditar mes.
2. Comparar IDs del mes si hay diferencias.
3. Recalcular fechas normalizadas si hay sospecha de timezone.
4. Corregir mes auditado para traer solo órdenes faltantes.
5. Reauditar.
