# PR16 R0/H3 — operación futura de recuperación acotada

Este documento prepara una operación posterior. No autoriza SSH, binding H3,
worker productivo, Cron, cambios de settings, llamadas reales a Mercado Libre,
merge ni deploy.

## Precondiciones y previsualización

1. Antes de instalar, comprobar por SHA-256 RAW que las ocho rutas del
   baseline previsto (`01aa479...`) coinciden con los bytes que se van a
   reemplazar. Después de instalar, comprobar por SHA-256 RAW las mismas
   rutas contra el candidato final (`90405c2...`) y verificar que
   `PackDiscoveryOccupancyPolicy.php` sea la única incorporación. No exigir
   que el servidor ya tenga el candidato antes de instalarlo; un drift en el
   baseline detiene la instalación.
2. Usar el scope certificado completo de R0/H3 y volver a comprobar cada una
   de las 77 unidades por empresa, cuenta, fuente, puntero, generación,
   estado, fechas, intentos y transporte. Un hash abreviado sólo localiza;
   no autoriza una unidad.
3. Separar las tres referencias H3 originales y la unidad incierta. No usar
   fixtures, IDs sintéticos ni el contenido de la captura como identidad
   operativa.
4. Si la correspondencia es ambigua, detener sólo esa unidad y conservar la
   evidencia. No sustituirla por otra fila.

## Instalación futura

El orden exacto debe ser aprobado junto con el preflight: instalar el runtime
certificado y sus metadatos; comprobar autoridad H3 válida; después admitir
una sola unidad. El binding H3 es una escritura independiente y debe usar
únicamente las tres identidades originales revalidadas y el scope completo.
Mientras falte autoridad o la ocupación heredada supere el límite global dos,
no se admite trabajo nuevo.

## Primer paso y continuación

Resolver un único puntero existente mediante sus identidades privadas
empresa/cuenta/job y ejecutar, sólo con una autorización futura explícita,
`recoverPackSourcePendingAfterDomainSourceError()` fuera de la transacción
HTTP. La entrada preparada es
`storage/operations/recover_pack_source_pending_once.php`; exige
`--company`, `--account`, `--job` y `--apply`, no contiene IDs sintéticos y
no descubre ni procesa una lista. Conservar intentos, generaciones y recibos;
dejar que el worker normal procese la unidad cuando exista una autorización
de operación. Verificar el cierre de fuente, puntero, integridad del pack y
elegibilidad financiera antes de considerar la siguiente.

La reentrada `waiting_rhythm` es válida únicamente para el tipo de pack
admitido por el servicio: `available_at` y `next_run_at` deben estar vencidos,
la evidencia debe ser `NOT_DISPATCHED` con cero llamadas físicas y no puede
haber transporte contradictorio, lease o reserva activa. Nunca se adelantan
fechas ni se reinician intentos. Un replay completo no se recupera otra vez.

## Conservación y contención

La unidad incierta, H3, los recibos históricos y cierres mensuales son
inmutables. Ante `domain_source_error`, identidad distinta, transporte
incierto o error conocido, detener nuevas recuperaciones y conservar el
estado. No escribir éxitos manuales, no reencolar, no borrar ni restaurar la
base completa.

Los respaldos y temporales de una futura operación deben permanecer bajo
`erp-meli/storage` con acceso web bloqueado. La contención sólo revierte los
archivos del runtime instalados en esa operación y nunca journals o datos de
negocio ya avanzados.

## Medición y cierre

Registrar por unidad: revisión, promoción a `ready`, cierre de fuente y
puntero, integridad del pack, liberación financiera y causa de detención.
Cuando exista autorización de transporte, contar únicamente intentos HTTP
físicos; el laboratorio local reporta `REAL_MELI_HTTP=0` y no se traslada a
producción.

Este procedimiento queda pendiente de autorización expresa y de un preflight
remoto separado. No se ejecuta como parte del lote local.
