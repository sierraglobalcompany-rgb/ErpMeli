# ERP Meli 2.19.0 — Meli Growth comercial, seguro y comprensible

## Decisión

La siguiente capacidad que se implementará es **Meli Growth**. No se habilitarán Ads, Posventa ni Logística en esta release.

Growth tiene la mejor relación entre utilidad comercial y riesgo operativo porque puede responder preguntas concretas sin escribir en Mercado Libre:

- ¿Qué promociones ofrece Mercado Libre a cada cuenta?
- ¿Cuántas visitas recibe la cuenta?
- ¿Cómo evoluciona la conversión entre visitas y órdenes locales?
- ¿Qué búsquedas son tendencia?
- ¿Qué productos o categorías aparecen entre los más vendidos?

El módulo permanecerá aislado del núcleo, deshabilitado por defecto y se habilitará primero para una cuenta canaria.

## Auditoría en diez pasadas

### 1. Cobertura funcional

El núcleo ya cubre órdenes, productos, envíos, preguntas, reclamos, facturación, conciliación, stock y Webhook-First. Las carencias más útiles son crecimiento comercial, Ads, mensajería/posventa avanzada y logística avanzada.

### 2. Estado real de los módulos

`meli-insights` es el único módulo que llegó a habilitarse en producción. Growth, Ads, Posventa y Logística todavía son esqueletos. Growth solo consulta una lista básica de promociones y muestra una pantalla genérica.

### 3. Contratos documentales

La documentación oficial vigente confirma promociones del vendedor, visitas, tendencias y destacados. El mapa contractual local debe actualizarse antes de usar las variantes seleccionadas.

### 4. Riesgo de bloqueo

La amenaza principal no es una llamada aislada, sino multiplicar consultas por cada publicación. Se descarta descargar visitas publicación por publicación durante una sincronización normal. Se usará la métrica agregada de la cuenta y una frecuencia semanal para datos generales de mercado.

### 5. Presupuesto API

Todas las consultas pasarán por `MeliReadGateway`, `MeliApiClient`, registro de endpoints, guardas y presupuesto. Growth tendrá su propia clasificación de trabajo y no consumirá las reservas de OAuth, órdenes o Webhook-First.

### 6. Errores esperados

- `403`: permiso o capacidad no disponible para esa cuenta.
- `404` en destacados: ausencia esperada, no alarma.
- `429`: pausa con `Retry-After`, sin reintentos masivos.
- `5xx`: falla temporal remota.
- fallos internos: no se atribuyen a Mercado Libre.

### 7. Modelo de datos

El módulo usará únicamente tablas `ml_growth_*`. No agregará columnas ni claves foráneas a órdenes, productos o cuentas. La conversión combinará visitas remotas agregadas con totales diarios locales obtenidos mediante un gateway de lectura.

### 8. Eventos

Los eventos `public_candidates` y `offers` dejarán de ser información indistinta. El receptor continuará guardándolos rápidamente y el módulo creará un trabajo deduplicado para consultar únicamente el recurso afectado.

### 9. Experiencia de usuario

Las tres rutas actuales no pueden seguir mostrando la misma pantalla. Se crearán experiencias separadas:

- Promociones.
- Rendimiento comercial.
- Tendencias y oportunidades.

Cada pantalla comenzará con una conclusión, antigüedad del dato y acción recomendada. Los estados técnicos quedarán en detalles secundarios.

### 10. Aislamiento y despliegue

Growth se distribuirá dentro de la release, pero seguirá deshabilitado. Una falla de su esquema, job o API no ocultará rutas del núcleo ni detendrá cron, órdenes o notificaciones.

## Plan revisado

### Sincronización por etapas

Cada ciclo hará como máximo una operación remota relevante y conservará checkpoint:

1. promociones del vendedor con `app_version=v2`;
2. visitas agregadas de los últimos 30 días;
3. tendencias semanales del sitio;
4. destacados semanales de una categoría prioritaria;
5. cierre y cálculo local de conversión.

No se consultarán visitas por cada publicación durante esta release.

### Datos y capacidades

Agregar:

- capacidades por cuenta y operación;
- visitas agregadas diarias de cuenta;
- candidatos públicos;
- ofertas;
- fecha de observación semanal para tendencias y destacados.

Los snapshots idénticos no duplicarán historia. Los datos vencidos se actualizarán; los vigentes se reutilizarán.

### Interfaz

Promociones mostrará campañas disponibles, vigencia, estado y candidatos/ofertas recibidos.

Rendimiento mostrará visitas, órdenes locales, conversión y calidad del dato. Si falta una fuente, dirá exactamente cuál.

Tendencias mostrará búsquedas y destacados con contexto de sitio/categoría. Nunca presentará datos generales como si fueran rendimiento propio.

### Seguridad

- Solo lectura hacia Mercado Libre.
- `ML_WRITE_ENABLED=false`.
- Sin endpoints de incorporación a campañas.
- Sin modificaciones de precio, stock o publicaciones.
- Sin tokens, compradores ni payloads completos en vistas.

### Despliegue

1. Instalar primero las releases pendientes 2.17.4, 2.18.0 y 2.18.1.
2. Instalar 2.19.0 con Growth deshabilitado.
3. Aplicar la migración central 094 y la interna 002.
4. Ejecutar diagnóstico.
5. Habilitar una cuenta canaria.
6. Observar 24 horas.
7. Habilitar gradualmente las demás cuentas.

## Roadmap posterior

- 2.20.0: Ads, después de completar evidencia contractual y pruebas de permisos.
- 2.21.0: Posventa avanzada.
- 2.22.0: Logística e inventario por origen.

No se activarán varios módulos nuevos al mismo tiempo.
