# ERP MELI 2.36.8 — informe del hotfix de recuperación V4

## Causa raíz

Durante el armado de readiness V4, la configuración llegó a habilitar `CRON_V4_ENABLED=true`, pero un fallo posterior compensó los feature flags a `enabled=false,generation=0`. El snapshot siguiente esperaba generación 1 para las tres capacidades frescas y respondió `feature_generation_invalid:fresh_producer`.

## Corrección

La versión 2.36.8 reconoce exclusivamente esa postimagen parcial cuando todas las demás autoridades siguen fail-closed: motor desactivado e idle, generación 0, scheduler ausente, flags desactivados, V3 y Shadow apagados, ML writes apagados, API y automatización detenidas. Cualquier diferencia adicional bloquea la recuperación.

La primera acción administrativa ejecuta solamente la compensación completa y devuelve `recovered_fail_closed`. Una segunda petición independiente es obligatoria para comenzar un armado nuevo. No se crea Cron, no se activa Queue Engine y no se hacen llamadas a Mercado Libre durante la recuperación.

## Write-set

- `config.env`: restaura V3, Shadow, V4 y ML write a false.
- autoridades técnicas de feature flags: desactivadas, generación 0.
- Queue Engine: disabled/idle, sin contexto.
- registro técnico del scheduler: absent.
- freno de API: stopped.

No hay migraciones ni DML de negocio. Las pruebas locales usan MariaDB desechable y no acceden a Hostinger ni a `storage/raw`.
