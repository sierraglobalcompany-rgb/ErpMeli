# Changelog Mercado Libre API

## 2026-07-25T06:19:57.009Z

Captura inicial auditable con extractor estricto sobre contenido principal. No existe snapshot anterior en este workspace, por lo que esta versión funciona como línea base.

### Resumen

- Páginas capturadas: 199
- Endpoints/rutas únicos detectados: 1700
- Páginas bloqueadas o inciertas: 0
- Módulos: 15

### Recomendación para próximas revisiones

Guardar el nuevo snapshot con otro nombre y ejecutar `node scripts/compare-snapshots.mjs mercadolibre-api-snapshot.json nuevo-snapshot.json mercadolibre-api-changelog.md`. Revisar manualmente cualquier endpoint agregado, removido o con hash modificado.
