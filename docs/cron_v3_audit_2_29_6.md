# Auditoría ERP Meli 2.29.6 — Actualizador transición segura

Hotfix acumulativo sobre 2.29.5. Corrige el bloqueo del actualizador cuando los archivos nuevos están completos, el marcador firmado aún pertenece a la versión anterior y existe una migración pendiente. También agrega detalle seguro de componentes cuando la subida sí está incompleta o mezclada.

- No modifica Cron V3 operativo, ownership, colas ni datos comerciales.
- No activa HTTP remoto ni cambia `ML_WRITE_ENABLED`.
- Migración 250 solo actualiza metadata de versión y contrato de transición.

# Auditoría ERP Meli 2.29.5 — Botones accionables del canario V3

## Hallazgo

En producción `2.29.4`, el estado real del canario avanzó a:

```text
Shadow aprobado
V3 real encendido de forma controlada
Canario V3 preparado
Listo para local
```

Sin embargo, todos los botones seguían activos visualmente. Eso hacía pensar que el panel no había cambiado de estado.

## Corrección

`2.29.5` no cambia motor Cron, ownership ni datos. Solo sincroniza la interfaz:

- `Preparar canario V3` queda deshabilitado cuando ya está preparado.
- `Habilitar canario local` queda activo únicamente en `ready_for_local`.
- `Habilitar canario remoto` queda activo únicamente en `ready_for_remote`.
- `Volver a V2` queda activo solo cuando hay V3 real u ownership canario.
- El asistente Shadow deja de decir “V3 real sigue apagado” cuando el canario ya está preparado.
- La acción actual se resalta visualmente y expone `aria-disabled`.

## Seguridad

- La migración `249` solo actualiza metadata/versionado.
- No activa ownership.
- No consulta Mercado Libre.
- No modifica órdenes, ventas, pagos, packs, envíos, campañas ni tokens.
