# Changelog

## 2.24.1 - Procesar ahora con destinos y explicaciones

- Convierte los contadores de trabajos excluidos en enlaces a listas exactas y paginadas.
- Explica por qué un trabajo necesita intervención, espera, otro proceso o Automatización.
- Enlaza cada recurso con su trabajo exacto y con el módulo que permite resolverlo.
- Sustituye la clasificación por palabras del mensaje por estados estructurados.
- Conserva los cálculos vencidos como evidencia de solo lectura sin permitir iniciar campañas obsoletas.

## 2.24.0 - Finanzas agrupadas por venta

- Captura billing oficial para todas las órdenes hijas de una venta.
- Separa total oficial y distribución analítica por producto.
- Evita duplicar envío, impuestos y cargos compartidos.
- Conserva revisiones metodológicas sin sobrescribir conciliaciones anteriores.
- Trata HTTP 206, documentos en procesamiento y conceptos desconocidos como no aprobados.

## 2.23.2 - Reconstrucción histórica de ventas por pack

- Usa `pack_id` como número visible de venta sin eliminar las órdenes API hijas.
- Reconstruye localmente `meli_pack_orders` antes de consumir API.
- Verifica cada pack y recupera únicamente las órdenes faltantes mediante CLI.
- Permite buscar una venta por pack, orden hija, envío, pago, producto o SKU.
- Corrige el fallo histórico que intentaba escribir `meli_orders.meli_pack_id`.

## 2.23.1 - Lanzador único, diario atómico y ventas verificables

- Consolida notificaciones, auditorías y campañas en `process_sync_queue.php`.
- Aprueba resultado, checkpoint y diario dentro de una sola transacción local.
- Unifica la elegibilidad mostrada por Cron y Próxima ejecución.
- Valida huecos, páginas repetidas, HTTP parcial, totales cambiantes e IDs de capturas de ventas.
- Exige dos capturas históricas válidas e independientes antes del cierre.
- Retira disparadores HTTP de trabajos y corrige el mapa de API.
- Añade una compuerta de release que exige MariaDB/MySQL real.

## 2.22.1 - Estabilidad, ejecución recuperable y ventas verificables

- Sustituye el bloqueo global de migraciones por contratos estructurales por componente.
- Cierra como ausencia esperada las preguntas eliminadas que responden 404.
- Introduce alcance explícito por usuario, empresa y cuenta.
- Retira la ejecución API desde navegador y unifica campañas en el orquestador CLI.
- Registra salidas, respuestas y el último resultado aprobado ante cortes del hosting.
- Coordina la comprobación anual un mes a la vez y exige doble captura histórica.
- Separa periodo solicitado, cobertura comprobada, fuentes y limitaciones conocidas.
- Corrige el contexto mensual y evita mostrar valores desconocidos como cero.

## 2.21.1 - Control de ventas compatible con el esquema real

- Corrige la lectura de cuentas que consultaba una columna inexistente en `meli_accounts`.
- Centraliza el aislamiento por empresa y cuenta sin ocultar el historial de cuentas desconectadas.
- Impide crear nuevas comprobaciones remotas hasta reautorizar una cuenta desconectada.
- Corrige la fecha de inicio de la cola de auditorías en el Centro de Automatización.
- Verifica tablas y columnas antes de cargar Control de ventas y degrada a una explicación segura.
- Agrega una prueba MariaDB conductual del resumen anual, enero y la proyección de auditorías.

## 2.20.5 - Ritmo confiable y monitor compacto

- Corrige el contador de bloques para contabilizar globalmente todas las consultas remotas de la campaña.
- Aplica pausas persistentes entre bloques, límite de bloques y duración máxima durante la ejecución.
- Comprueba la cola fuente antes de cada paso y evita reintentos infinitos sobre trabajos ya resueltos.
- Reserva descripciones por publicación individual y retira el backfill no aislado del modo interactivo.
- Unifica reloj, estado, heartbeat y peticiones en un único controlador sin solicitudes concurrentes.
- Rediseña el monitor con progreso de campaña, trabajo actual, ritmo, bitácora y errores en el primer vistazo.
- Agrega listados paginados propios de trabajos y eventos de cada campaña.

## 2.20.4 - Procesamiento manual interactivo

- Elimina la dependencia del cron y de los workers manuales para `Procesar ahora`.
- Ejecuta desde la pestaña un único paso autenticado, exacto e idempotente por petición.
- Respeta bloques, intervalos, pausas, presupuesto API y límites preventivos con hora del servidor.
- Mantiene progreso por elementos reales, control de pestaña y actividad vinculada a resultados guardados.
- Libera las reservas manuales al cerrar la pestaña o tras 45 segundos sin señal para que el cron normal pueda retomarlas.
- Impide que cron y la campaña reclamen simultáneamente órdenes, productos, descripciones o backfill reservados.
- Conserva una acción manual de un solo paso cuando JavaScript está desactivado.
- Retira los workers manuales heredados sin afectar la automatización normal del ERP.

## 2.20.3 - Monitor asistido unificado

- Recupera la lectura rápida del antiguo modo asistido dentro de un único Centro `Procesar ahora`.
- Calcula progreso y estados desde los recursos reales de la campaña; nunca muestra 100 % con pendientes.
- Distingue motor procesando, listo, esperando, pausado y no disponible mediante heartbeat de la campaña.
- Reemplaza el contador circular por un reloj rectangular, progreso por bloques y actividad confirmada.
- Conserva cuenta, periodo y origen al entrar desde ventas, auditorías, finanzas, productos o descripciones.
- Convierte las acciones heredadas de reanudación y reintento en revisiones previas sin mutar colas al redirigir.
- Mantiene la ejecución exclusivamente en PHP CLI y no expone tokens ni disparadores HTTP.

## 2.20.2 - Activación directa y diagnóstico humano del motor

- Prioriza la actualización pendiente sobre certificación, heartbeat y errores heredados.
- Permite crear campañas inmediatamente en modo conservador con esquema y señal CLI saludables.
- Mueve la certificación de ventanas largas a opciones avanzadas y la mantiene como optimización opcional.
- Separa el heartbeat del worker manual heredado para que no confirme ni ensucie el motor de campañas.
- Los workers pendientes de migración terminan como `SKIP` sin consultar Mercado Libre ni registrar errores repetitivos.
- Explica que Hostinger solo ejecuta el lanzador PHP; bloques, pausas e intervalos pertenecen a cada campaña.

## 2.20.1 - Ritmo exacto y contadores verificables

- Limita Productos y páginas de Órdenes a una salida remota por pulso de campaña.
- Evita el enriquecimiento remoto en línea durante una campaña; el trabajo derivado conserva su cola.
- Cuenta únicamente solicitudes registradas como enviadas y separa renovaciones OAuth derivadas.
- No presenta pausas de presupuesto como consultas realizadas ni como errores remotos.
- Reinicia el bloque al cambiar de operación y aplica sus límites a llamadas, no a filas.
- Bloquea rutas, trabajos y eventos de módulos que todavía tengan migraciones propias pendientes.
- Aclara en Módulos cuándo la instalación está incompleta y cuándo existen eventos locales por reconciliar.
- Agrega la migración aditiva e idempotente 105.

## 2.20.0 - Campañas visuales configurables

- Congela trabajos exactos y rechaza colas que aún no tengan un adaptador certificado.
- Permite configurar bloque, intervalo entre consultas y pausa entre bloques.
- Mantiene el reloj en UTC para continuar después de reinicios o cierres del navegador.
- Agrega una cabina viva con progreso, cuenta regresiva, actividad incremental y controles seguros.
- Mantiene una sola consulta remota simultánea y nunca conserva transacciones durante esperas o HTTP.
- Agrega la migración aditiva e idempotente 104.

## 2.19.9 - Certificación del motor optimizado

- Agrega pruebas CLI escalonadas de 10, 30 y 55 segundos sin consultar Mercado Libre.
- Mide precisión de espera, MySQL, storage, locks y checkpoints.
- Introduce el contrato de adaptadores exactos y bloquea los procesadores genéricos en campañas.
- Agrega la migración aditiva e idempotente 103.

## 2.19.8 - Procesar ahora visible e inmediato

- Agrega acceso directo desde Administración, Configuración y Automatización.
- Permite comenzar de inmediato con perfiles conservadores aunque la observación de carga siga aprendiendo.
- Separa el diagnóstico del motor CLI del cron general y registra su heartbeat aun con la cola vacía.
- Simplifica el flujo a Elegir, Revisar, Procesar y Resultado, con confirmación reforzada para descripciones.

## 2.19.7 - Telemetría por cuenta y arranque conservador

- Impide que las mediciones saludables de una cuenta aumenten los lotes de otra.
- Exige una muestra p95 suficiente antes de abandonar el micro-lote mínimo.
- Mantiene el ritmo conservador cuando existen fallos internos recientes.
- Hace que cron respete también las reservas manuales limitadas a una cuenta.
- Permite arrancar un canario conservador después del periodo de observación aunque aún falten muestras.
- Explica cuántas horas faltan, qué operaciones carecen de evidencia y qué trabajos no informan tamaño.
- Separa visualmente elementos conocidos de trabajos pendientes.
- Agrega la migración aditiva e idempotente 101.

## 2.19.6 - Ciclo seguro del procesamiento manual

- Impide que el worker reclame otro trabajo después de pausar o solicitar la finalización.
- Finaliza primero el micro-lote en curso y devuelve después el trabajo restante al cron.
- Mantiene reservadas las colas durante la transición para evitar procesamiento simultáneo.
- Evita crear otra sesión mientras la anterior todavía está terminando.
- Filtra la simulación por las colas elegidas antes de aplicar el límite de seguridad.
- Excluye fallos internos de las muestras que habilitan ritmos adaptativos.
- Oculta tokens de sesión y propietarios de leases en respuestas administrativas.
- Limita y explica los trabajos visibles sin alterar el progreso total.
- Agrega la migración aditiva e idempotente 100.

## 2.19.5 - Integridad del procesamiento manual

- Evita que el procesador manual declare completado un trabajo distinto al que reservó.
- Recupera reservas vencidas, cierra sesiones terminadas y devuelve trabajo al cron sin falsos estados verdes.
- Impide sesiones manuales simultáneas mediante un lock MySQL y mantiene visibles las sesiones pausadas.
- Detiene la sesión ante errores para que el administrador pueda revisar antes de continuar.
- Sustituye proyecciones obsoletas sin convertir una falla de lectura en una falsa cola vacía.
- Clasifica el recálculo financiero por su fase remota de facturación y conserva la reserva urgente de ventas.
- Aplica realmente los ritmos conservador, equilibrado y automático.
- Añade muestras numéricas sanitizadas y percentiles p95 para adaptar lotes según duración y carga observadas.
- Amplía la simulación hasta 5.000 trabajos y exige evidencia para todas las operaciones API seleccionadas.
- Mejora la pantalla con estado independiente del worker, resultados humanos y actualización en vivo.
- Agrega la migración aditiva e idempotente 099.

## 2.19.4 - Procesamiento manual seguro

- Crea el Centro de procesamiento manual y su worker CLI independiente.
- Reserva trabajos concretos para evitar que cron y el modo manual los ejecuten simultáneamente.
- Mantiene webhooks y ventas urgentes disponibles mientras una sesión procesa trabajo de menor prioridad.
- Permite simular, pausar, continuar y devolver trabajos al cron sin perder checkpoints.
- Agrega la migración aditiva e idempotente 098.

## 2.19.3 - Inteligencia de carga API

- Registra perfiles humanos y límites conservadores por operación de Mercado Libre.
- Separa presupuesto de llamadas API y carga interna del ERP.
- Instrumenta duración, transferencia, elementos y fan-out sin guardar cuerpos de respuesta.
- Protege descripciones, cursores de productos y facturación con políticas específicas.
- Agrega la migración aditiva e idempotente 097.

## 2.19.2 - Auditoría guiada y salud sin falsas alarmas

- Separa definitivamente las auditorías heredadas de las comprobaciones exactas.
- Crea auditorías y reparaciones como trabajos CLI sin consultar Mercado Libre desde la petición web.
- Agrega previsualización, deduplicación, lease, progreso por orden y verificación posterior automática.
- Diferencia la disponibilidad de Mercado Libre de los problemas internos del ERP.
- Convierte incidentes recuperados en explicaciones y acciones contextuales seguras.
- Corrige los adaptadores financiero, auditoría y descripciones, y registra su salud sin fingir colas vacías.
- Agrega la migración aditiva e idempotente 096.

## 2.19.1 - Automatización explicable

- Diferencia errores accionables, reintentos, pausas, presupuesto y límites normales del ciclo.
- Explica en lenguaje humano por qué un trabajo espera y qué debe hacer el administrador.
- Agrega detalle seguro por trabajo y enlaces hacia el módulo relacionado.
- Incorpora ayuda accesible después de dos segundos sin depender del hover.

## 2.19.0 - Meli Growth comercial, seguro y aislado

- Completa el módulo Growth con promociones, visitas agregadas, conversión local, tendencias y destacados.
- Procesa la sincronización por etapas reanudables y evita consultas masivas por publicación.
- Reconoce candidatos y ofertas promocionales mediante eventos deduplicados.
- Separa permisos, ausencias esperadas y límites temporales sin convertirlos en fallos globales.
- Reemplaza la pantalla genérica por Promociones, Rendimiento comercial y Tendencias con lenguaje humano.
- Mantiene el módulo deshabilitado por defecto, en solo lectura y con presupuesto independiente.
- Agrega la migración central 094 y la migración interna `meli-growth/002`.

## 2.9.0 - Motor de actualizaciones seguro y atómico

- Agrega releases atómicas con launcher estable, puntero transaccional y tres versiones recuperables.
- Admite actualización remota HTTPS, paquetes firmados `.erpupd`, carpetas completas y sobrescritura clásica.
- Incorpora firmas OpenSSL, SHA-256 por archivo, canales, secuencia anti-downgrade, expiración y claves rotables.
- Agrega diagnóstico de esquema, adopción segura de migraciones históricas, checksums, locks dobles y ejecución por etapas.
- Crea backups verificados mediante `mysqldump` o streaming PHP y exige confirmación reforzada para omitirlos.
- Añade mantenimiento coordinado, health checks, rollback de código, panel de rescate y cron estable por launcher.
- Mantiene `config.env`, storage, credenciales y datos privados fuera de cada release.

## 2.7.4 - Recálculo financiero resiliente a cortes MySQL

- Agrega reconexión segura en `Database` para detectar `MySQL server has gone away` / conexión perdida.
- Ajusta la cola financiera y el importador billing para no reutilizar una conexión PDO vieja después de llamadas API o pausas.
- Reduce defaults del recálculo financiero asistido a pasos más pequeños: 10 órdenes por corrida y 20 order IDs por request billing.
- Registra reconexiones DB y último error seguro en jobs financieros.
- Agrega migración `039_financial_recalc_mysql_resilience_2_7_4.sql`.

## 2.6.3 - Estabilización auditoría, cron y envíos

- Refuerza la auditoría exacta de ventas para evitar errores `SQLSTATE[HY093]` en el snapshot crítico de IDs remotos y faltantes.
- Cambia la reparación mensual para reutilizar el flujo exacto de sistema y no duplicar validaciones de sesión.
- Optimiza la carga inicial de **Ventas → Envíos** limitando la vista sin filtros a envíos recientes y evitando ordenamientos con `COALESCE()` que impiden usar índices.
- Limita valores de combos de Envíos a datos recientes para evitar escaneos grandes.
- Limpia textos visibles de Auditoría de ventas y Envíos con tildes/UTF-8 correctos.
- Actualiza assets a `2.6.3` para evitar caché CSS/JS vieja.
- Agrega migración `034_stability_cron_audit_shipments_2_6_3.sql` con settings e índices de apoyo.

## 2.6.2 - Hotfix auditoría y notificaciones

- Corrige la auditoría exacta cuando todas las órdenes remotas existen localmente, pero una o varias quedaron normalizadas en otro día local.
- Evita que esos casos queden como faltantes reales o bloqueen el panel como auditoría incompleta permanente.
- Ajusta la tabla de auditoría para que los botones de acción no se sobrepongan.
- Oculta acciones de reparación/encolado cuando no hay faltantes reales por descargar.
- Agrega acción manual para procesar eventos webhook pendientes desde Centro de Notificaciones y Eventos recibidos.
- Mejora el mensaje de salud webhook cuando existen eventos en cola o topics no confirmados.
- Actualiza assets a `2.6.2` para evitar caché CSS/JS vieja.

## 2.6.1 - Auditoría exacta de ventas y reparación confiable

- Agrega snapshot exacto por IDs en `sync_sales_audit_remote_ids`.
- Clasifica diferencias como faltante real, fecha local desplazada o sobrante local.
- Agrega totales de consistencia diaria/mensual en auditorías de ventas.
- Cambia reparación mensual para traer únicamente faltantes reales detectados por comparación exacta.
- Reaudita el mes después de completar una reparación.
- Corrige avance de `offset` cuando un bloque se corta por límite de órdenes.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.5.1 - Protección anti-bloqueo Mercado Libre

- Agrega `ApiErrorClassifier` central para clasificar 400/401/403/404/429/5xx, timeouts, tokens inválidos y señales de app bloqueada.
- Refuerza `ApiGuardService` con circuit breakers por cuenta, endpoint, tipo de error, ventana de fallos, `Retry-After` y cooldown.
- Agrega **Configuración → Salud API ML** con semáforo de riesgo, circuitos activos, acciones admin auditadas y export CSV sanitizado.
- Detiene el cron ML cuando hay circuito global `app_blocked`.
- Agrega política configurable de retención para logs API y documentación anti-bloqueo.

Nota 2.5.0: el modo asistido de sincronizaciones ahora puede procesar manualmente el siguiente bloque en cola aunque estÃ© programado para una hora futura; cron conserva su regla de procesar solo bloques vencidos/listos.

## 2.5.0 - Integridad de ventas, auditoría confiable y facturación segura

- Agrega normalización explícita de fechas de Mercado Libre: valor crudo, offset original, UTC, hora local ERP y fecha local.
- Agrega columnas normalizadas para órdenes y pagos, manteniendo compatibilidad con campos anteriores.
- Cambia auditoría/sincronización histórica a rangos semiabiertos y modo diario recomendado.
- Agrega comparación exacta liviana por IDs, diagnóstico de fechas, recalculo desde `raw_json` y reparación en cola de órdenes faltantes.
- Agrega snapshots de cobertura/auditoría para facturación mensual y por fechas.
- Bloquea aprobación/facturación definitiva cuando la cobertura o auditoría no es confiable, salvo override admin documentado.
- Agrega tablas de diagnóstico horario, reparación de fechas, reparación de ventas y solicitudes de purga segura.
- Mantiene `ML_WRITE_ENABLED=false`; no agrega escrituras hacia Mercado Libre.

## 2.4.6 — Auditoría de ventas, sync diario programado y corrección UI

- Corrige el `confirm()` de reprogramación para que no se dispare al abrir selectores.
- Agrega **Sincronizaciones → Auditoría de ventas** usando `/orders/search` con `limit=1` y `paging.total`, sin descargar detalle completo.
- Permite comparar totales mensuales/remotos contra órdenes locales y abrir revisión diaria cuando haya diferencias.
- Permite encolar días faltantes y comparar IDs remotos de forma liviana, sin consultar detalle de orden.
- Agrega **Programación automática** para mantener ventas al día por cuenta, frecuencia, horario laboral, días activos y solape seguro.
- El cron ahora puede encolar bloques diarios pequeños según reglas, manteniendo la protección anti-429/403.
- Throttle de preguntas por cron configurable y alerta prioritaria en panel.
- Agrega migración `028_sync_audit_recurring_2_4_6.sql`.

## 2.4.5 — Modo asistido avanzado y recuperación de sincronizaciones vencidas

- Agrega detalle operativo al modo asistido: cuenta, mes, bloque, rango, órdenes, faltantes y error seguro.
- Agrega controles de pausa, continuar y cancelar dentro del modal asistido.
- Agrega reprogramación de bloques vencidos/listos desde Sincronizaciones, Agenda cron y Estado cron.
- Limita `sync.default_enqueue_delay_minutes` a valores seguros para evitar programaciones accidentales largas.
- Agrega migración `027_sync_assisted_cron_recovery_2_4_5.sql`.

## 2.4.4 — Auditoría de cron, timezone y errores visibles en sincronizaciones

- Normaliza operaciones de cron/cola/locks a UTC con `UTC_TIMESTAMP()`.
- Agrega diagnóstico horario PHP/MySQL/ERP en **Configuración → Estado cron**.
- Muestra errores de sincronización con tipo, HTTP, endpoint y recomendación segura.
- Agrega bloques atrasados/listos en el monitor de sincronizaciones y agenda cron.
- Agrega modo asistido para ejecutar bloques vencidos respetando pausas configuradas.
- Mejora la prueba de cron con resultado detallado y payload seguro.

## 2.4.3 — Programación flexible de sincronizaciones y preloader manual

- Permite crear bloques sin encolar, encolar ahora, encolar en 5/30/60 minutos o programar fecha/hora específica.
- Guarda la agenda de cola en UTC y muestra horarios convertidos a la zona horaria configurada.
- Agrega botón “Procesar próximo bloque ahora” en la pantalla principal de Sincronizaciones.
- Mejora el procesamiento manual con overlay visual, tiempo transcurrido y resultado final.
- Agrega reprogramación de bloques desde la agenda cron.
- Agrega settings `sync.default_enqueue_delay_minutes`, `sync.allow_custom_schedule` y `sync.manual_overlay_enabled`.

## 2.4.2 — Auditoría general y Pagos estable

- Estabiliza **Ventas → Pagos** para que cargue aunque falten columnas de detalle de migraciones parciales.
- Agrega diagnóstico visible para administradores sobre tabla `meli_payments`, columnas faltantes, fuente de pagos y estado de detalle externo.
- Cambia la política operativa: pagos se toman desde `orders[].payments[]`; la expansión externa queda apagada con `payments.expand_details_enabled=0`.
- El sincronizador de órdenes ya no intenta `/payments/{id}` por defecto y puede guardar pagos aunque falten columnas `detail_*`.
- Agrega `SchemaInspectorService` y auditoría básica de módulos en **Configuración → Diagnóstico**.
- Agrega migración `024_payments_audit_stability_2_4_2.sql` con settings de pagos y `module_health_checks`.
- Documenta que `GET /payments/` genérico no se usa y que Mercado Pago `/v1/payments/{id}` queda investigando.

## 2.4.1 — Búsquedas, publicaciones con imagen, enlaces ML y envíos pendientes

- Corrige el filtro de **Productos ML** evitando consultas con `GROUP BY` frágil y mostrando error amigable si ocurre un fallo técnico.
- Agrega miniaturas en Productos ML y detalle local de publicación con galería, variaciones y atributos importados.
- Mejora **Órdenes** con búsqueda por todo, número de orden, producto, SKU o comprador.
- Agrega botón externo en detalle de orden para abrir el pedido en Mercado Libre.
- Mejora **Ventas → Envíos** con presets de pendientes del día, grupos de logística, modo real API, export CSV y retorno a orden.
- Prepara base de datos para guías/etiquetas, pero deja descarga desactivada hasta confirmar soporte real para Colombia/cuenta.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.4.0 — Protección anti-bloqueos ML, preguntas, agenda cron, logs y ventas

- Agrega guardia API con registro de requests, backoff con jitter, `Retry-After` y circuit breaker por cuenta/endpoint ante `429/403`.
- Agrega agenda de cron, monitor general de cola y endpoint JSON para estado de sincronizaciones.
- Mejora el botón **Procesar próximo bloque ahora** con preloader y resultado sin recargar toda la página.
- Crea **Ventas → Preguntas** en modo solo lectura, con alertas internas y email opcional.
- Mejora **Sistema → Logs** con filtros por tipo, nivel, cuenta, HTTP, endpoint y fechas.
- Blinda **Ventas → Pagos** para mostrar error amigable si hay fallo técnico.
- Mejora **Ventas → Envíos** con filtros por cuenta/logística/estado/fecha, export CSV y enlace a orden con regreso.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.3.1 — Gestión de sincronizaciones, cron, timezone, pagos y reclamos

- Agrega gestión de bloques de sincronización: cancelar, reactivar, eliminar y eliminar planificación del mes.
- Agrega monitor autoactualizable con porcentaje, bloques, órdenes procesadas y botón “Procesar próximo bloque ahora”.
- Agrega salud del cron con registros en `cron_health_checks` y prueba manual desde Configuración.
- Agrega zona horaria configurable con valor inicial `America/Bogota`.
- Mejora filtros y totales de Pagos por cuenta, estado, método, tipo, detalle, fecha, búsqueda y ordenamiento.
- Reemplaza “Cuenta ID” en Reclamos por selector de cuenta y agrega filtros.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.3.0 — Centro de sincronización, importaciones y cobertura

- Agrega **Ventas → Sincronizaciones** con planificación por cuenta, año, mes y bloques.
- Permite dividir meses por semanas o por número de partes configurable.
- Agrega cola de sincronización en base de datos y job CLI `jobs/process_sync_queue.php`.
- Si un bloque supera el máximo de órdenes por ejecución, queda parcial y se agenda continuación con `next_run_at`.
- Agrega diagnóstico de volumen por muestras usando `/orders/search` y `paging.total`.
- Agrega cobertura de sincronización para advertir en Facturación mensual/por fechas cuando el rango puede estar incompleto.
- Agrega **Productos → Importaciones** para crear productos internos desde publicaciones ML ya importadas.
- Conserva referencias SKU/ID de Mercado Libre y evita duplicados por cuenta/publicación/variación.
- Mantiene `ML_WRITE_ENABLED=false`; no agrega escrituras hacia Mercado Libre.

## 2.1.2 — Facturación por fechas y corrección de Empresas

- Crea el módulo visual **Facturación** con opciones Mensual y Por fechas.
- Agrega facturación por fechas con emisora, cliente, cuenta, rango, base manual, estados y referencia externa al facturar.
- Corrige errores `SQLSTATE[HY093]` en Empresas usando placeholders únicos.
- Evita pantalla blanca en `Empresas → Ver/editar` y registra errores técnicos en logs.
- Deja **Reportes** para Rentabilidad y Exportaciones.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.1.1 — Menú lateral expansivo y reportes más claros

- Convierte el menú lateral en acordeón con grupos: Ventas, Productos, Reportes y Sistema.
- Reorganiza Reportes y Facturación para diferenciar cierre mensual, por fechas y rentabilidad.
- No agrega migraciones ni cambia sincronización/API de Mercado Libre.

## 2.1.0 — Multicuenta, Bodega, Reportes, Packs, Envíos y Devoluciones

- Agrega base de productos 2.1, productos internos, vínculos, sugerencias y productos vendidos sin vincular.
- Agrega módulos UI de Productos ML, Bodega, Vinculación, Packs, Envíos, Pagos, Devoluciones/Reclamos, Reporte por fechas, Rentabilidad, Exportaciones y Alertas.
- Mantiene `ML_WRITE_ENABLED=false` y no agrega escrituras hacia Mercado Libre.

## 2.0.1 — Correctivo estabilidad, actualización, empresas, logs y UI

- Corrige reporte mensual, empresas, configuración, actualizador, logs API agrupados y responsive.
# 2.20.6

- El cálculo de `Procesar ahora` usa POST/PRG, conserva la configuración y lleva directamente al resultado.
- La selección revisada queda congelada durante diez minutos y se revalida antes de crear la campaña.
- “Todo” distingue trabajo listo, futuro, ocupado, completado y con intervención pendiente.
- Las notificaciones Webhook-First seguras pueden procesarse individualmente sin competir con Automatización.
- Cada paso manual admite como máximo una salida remota; una renovación OAuth aplaza la consulta principal.
