# Auditoría Cron V3 RC2

Documento fuente requerido por el contrato `release_2291_contract`.

## Alcance RC2

- Autoridad de ritmo multidimensional: global, aplicación, cuenta, endpoint y operación.
- Rate gate transaccional con `FOR UPDATE`.
- Falla cerrada si falta la autoridad de esquema.
- `Retry-After` se conserva y se propaga por scopes.
- Circuit breaker con probe half-open único.
- MariaDB mínimo declarado para el conjunto completo de migraciones.

## Reauditoría acumulada 2.35.1

- La migración 244 conserva compatibilidad mediante `information_schema` y mantiene contrato equivalente a `ADD COLUMN IF NOT EXISTS`.
- No contiene tokens, secretos ni credenciales.
- La corrección 2.35.1 no aumenta límites de Mercado Libre ni cambia `ML_WRITE_ENABLED=false`.
- La autoridad de ritmo sigue siendo un techo seguro; el drenaje real depende de trabajos `ready`, presupuesto por endpoint/cuenta y cierre correcto de fuente legacy.

## Riesgos vigilados

- HTTP 429 debe mantenerse como señal de riesgo aunque esté recuperado.
- Un trabajo con transporte conocido no debe volverse incierto por fallos de limpieza local.
- Un worker vencido no debe confirmar trabajo, ritmo ni circuito.
