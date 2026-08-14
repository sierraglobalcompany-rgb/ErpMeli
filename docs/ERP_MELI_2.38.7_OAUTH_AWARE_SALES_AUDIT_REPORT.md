# ERP MELI 2.38.7 — OAuth-aware Sales Audit

## Causa raíz

En 2.38.6, `OAuthRefreshRequiredException` atravesaba el límite de Sales Audit y
caía en el manejo genérico de errores. El acceso remoto todavía no había
comenzado, pero el trabajo consumía un intento, incrementaba fallos y convertía
la ejecución mensual en error.

## Corrección

- admisión automática por token comercial utilizable con el mismo skew de
  `MeliApiClient`;
- selección del trabajo elegible más antiguo dentro del dominio auxiliar;
- captura defensiva posterior al claim con cercas de tenant, lease, generación
  y `NOT_DISPATCHED`;
- aplazamiento no-failure hasta la autoridad exacta de la operación OAuth;
- reparación local, estrecha e idempotente de la firma falsa generada por
  2.38.6;
- abort del ciclo antes de Producer/Worker sólo ante contradicciones de
  autoridad.

Queue V4 comercial conserva su FIFO sin filtros OAuth previos. Producer y
recovery continúan sin transporte remoto. La release no agrega migraciones y
mantiene schema 297, `ML_WRITE_ENABLED=false` y la autoridad privada 2.38.6.
