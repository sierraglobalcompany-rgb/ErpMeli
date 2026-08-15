<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsDefinitionRepository
{
    public function sections(): array
    {
        return [
            'general' => $this->sectionDefinition('General y zona horaria', 'Cómo se muestran las fechas y opciones generales del ERP.', 'file', [
                $this->select('app.timezone', 'Zona horaria del ERP', 'America/Bogota', [
                    'America/Bogota' => 'Bogotá',
                    'America/Mexico_City' => 'Ciudad de México',
                    'America/Lima' => 'Lima',
                    'America/New_York' => 'Nueva York',
                    'UTC' => 'UTC',
                ], 'Afecta las fechas visibles; la base conserva fechas técnicas en UTC.'),
                $this->bool('sync.manual_process_enabled', 'Permitir procesamiento manual', true, 'Muestra acciones para procesar colas sin esperar al cron.'),
                $this->bool('sync.allow_custom_schedule', 'Permitir fecha y hora personalizada', true, 'Habilita programación manual de bloques.'),
                $this->bool('sync.manual_overlay_enabled', 'Mostrar progreso asistido', true, 'Muestra el monitor visual de procesos manuales.'),
            ]),
            'synchronization' => $this->sectionDefinition('Sincronización y automatización', 'Tamaño, pausas y organización de la cola de órdenes.', 'refresh', [
                $this->number('sync.max_manual_range_days', 'Rango manual máximo', 7, 1, 31, 'días', 'Limita el periodo de una sincronización iniciada desde la web.'),
                $this->number('sync.page_limit', 'Órdenes por página', 50, 1, 100, 'órdenes', 'Un lote menor reduce la carga de cada consulta.'),
                $this->number('sync.max_orders_per_run', 'Máximo por ejecución', 500, 1, 500, 'órdenes', 'Límite duro por ciclo. Aunque la base contenga un valor mayor, el ERP nunca procesará más de 500.'),
                $this->number('sync.max_api_pages_per_run', 'Páginas API por ejecución', 5, 1, 10, 'páginas', 'Límite duro de páginas remotas por ciclo; evita recorridos ilimitados aunque una configuración antigua sea incorrecta.'),
                $this->select('sync.chunk_mode', 'División mensual', 'daily', ['daily' => 'Por día (recomendado)', 'weekly' => 'Por semana', 'parts' => 'Por número de partes'], 'Define cómo se divide un mes antes de entrar a la cola.'),
                $this->number('sync.queue_max_chunks_per_run', 'Bloques por cron', 3, 1, 20, 'bloques', 'Cantidad máxima que cron toma en una ejecución.'),
                $this->select('sync.default_enqueue_delay_minutes', 'Inicio sugerido', '5', ['0' => 'Ahora', '5' => 'En 5 minutos', '30' => 'En 30 minutos', '60' => 'En 1 hora'], 'Momento predeterminado al crear una cola.'),
                $this->select('sync.overdue_reschedule_default_minutes', 'Reprogramación predeterminada', '5', ['0' => 'Ahora', '5' => 'En 5 minutos', '10' => 'En 10 minutos', '20' => 'En 20 minutos', '30' => 'En 30 minutos'], 'Momento sugerido al recuperar bloques vencidos.'),
                $this->number('sync.pause_between_pages_ms', 'Pausa entre páginas', 400, 0, 10000, 'ms', 'Reduce ráfagas hacia Mercado Libre.', true),
                $this->number('sync.chunk_parts', 'Partes por mes', 4, 1, 31, 'partes', 'Solo aplica al modo “número de partes”.', true),
                $this->number('sync.continuation_delay_minutes', 'Espera entre continuaciones', 5, 1, 60, 'min', 'Tiempo antes de retomar un bloque parcial.', true),
                $this->number('sync.monitor_refresh_seconds', 'Refresco del monitor', 12, 5, 120, 'seg', 'Frecuencia visual; no cambia la velocidad real.', true),
            ]),
            'mercadolibre' => $this->sectionDefinition('Mercado Libre y protección API', 'Presupuesto preventivo, pausas automáticas y retención técnica.', 'bell', [
                $this->bool('api.guard.enabled', 'Protección API activa', true, 'Detiene consultas ante errores repetidos.'),
                $this->bool('api.budget.enabled', 'Presupuesto preventivo activo', true, 'Reserva capacidad antes de consultar Mercado Libre.'),
                $this->bool('api.cron.priority_budget_enabled', 'Priorizar trabajos del cron', true, 'Protege órdenes y tokens antes que tareas secundarias.'),
                $this->number('api.budget.global_requests_per_15m', 'Capacidad general', 300, 1, 10000, 'consultas / 15 min', 'Límite preventivo de toda la aplicación.'),
                $this->number('api.budget.account_requests_per_15m', 'Capacidad por cuenta', 120, 1, 5000, 'consultas / 15 min', 'Evita que una cuenta consuma todo el presupuesto.'),
                $this->number('api.budget.web_request_api_limit', 'Máximo desde una pantalla', 10, 1, 100, 'consultas', 'Los trabajos mayores deben pasar a cola.'),
                $this->number('oauth.auto_refresh_lead_seconds', 'Anticipación de renovación OAuth', 3600, 300, 7200, 'segundos', 'Queue V4 renueva antes del vencimiento sin alterar la barrera comercial.', true),
                $this->number('oauth.auto_refresh_global_reserve_per_15m', 'Reserva OAuth global', 3, 3, 30, 'consultas / 15 min', 'Capacidad mínima reservada para renovar las tres cuentas.', true),
                $this->number('oauth.auto_refresh_account_reserve_per_15m', 'Reserva OAuth por cuenta', 1, 1, 10, 'consulta / 15 min', 'Impide que el trabajo comercial agote la renovación de una cuenta.', true),
                $this->number('api.guard.max_429_per_window', 'Errores de límite permitidos', 3, 1, 20, 'errores', 'Al alcanzarlo se activa una pausa automática.', true),
                $this->number('api.guard.max_403_per_window', 'Errores de permiso permitidos', 1, 1, 20, 'errores', 'Protege ante permisos o bloqueos.', true),
                $this->number('api.guard.max_401_per_window', 'Errores de autorización permitidos', 2, 1, 20, 'errores', 'Evita reintentos con tokens inválidos.', true),
                $this->number('api.guard.max_400_per_window', 'Solicitudes inválidas permitidas', 5, 1, 50, 'errores', 'Abre una pausa preventiva si el código está llamando mal la API.', true),
                $this->number('api.guard.max_unknown_400_per_window', 'Errores 400 desconocidos', 3, 1, 20, 'errores', 'Umbral más estricto para causas no clasificadas.', true),
                $this->number('api.guard.max_5xx_per_window', 'Fallos temporales permitidos', 3, 1, 20, 'errores', 'Pausa ante inestabilidad de Mercado Libre.', true),
                $this->number('api.guard.window_minutes', 'Ventana de evaluación', 10, 1, 120, 'min', 'Periodo usado para contar errores.', true),
                $this->number('api.guard.cooldown_minutes', 'Pausa automática base', 15, 1, 1440, 'min', 'Tiempo de protección después de abrir un circuito.', true),
                $this->number('api.guard.app_blocked_cooldown_minutes', 'Pausa por aplicación bloqueada', 1440, 30, 10080, 'min', 'Protección crítica de toda la aplicación.', true),
                $this->number('api.guard.unauthorized_scopes_global_pause_minutes', 'Pausa por permisos críticos', 1440, 30, 10080, 'min', 'Evita insistir cuando la aplicación perdió permisos.', true),
                $this->number('api.guard.max_retry_attempts', 'Reintentos máximos', 3, 1, 5, 'intentos', 'No aumenta el presupuesto; solo controla fallos transitorios.', true),
                $this->number('api.guard.jitter_min_ms', 'Espera aleatoria mínima', 250, 0, 10000, 'ms', 'Separa consultas simultáneas.', true),
                $this->number('api.guard.jitter_max_ms', 'Espera aleatoria máxima', 1500, 0, 30000, 'ms', 'Separa consultas simultáneas.', true),
                $this->number('api.logs.request_retention_days', 'Retención de consultas', 60, 1, 730, 'días', 'Conserva evidencia operativa sin crecer indefinidamente.', true),
                $this->number('api.logs.error_retention_days', 'Retención de errores', 180, 1, 1825, 'días', 'Mantiene fallos para análisis.', true),
                $this->number('api.logs.raw_retention_days', 'Retención técnica ampliada', 365, 1, 1825, 'días', 'No incluye secretos; use solo si necesita auditoría prolongada.', true),
            ]),
            'communications' => $this->sectionDefinition('Preguntas, reclamos y notificaciones', 'Frecuencia y límites de atención y eventos.', 'bell', [
                $this->bool('questions.sync_enabled', 'Sincronización general retirada', false, 'Debe permanecer apagada: no existe consumidor automático vigente.'),
                $this->bool('questions.endpoint_confirmed', 'Búsqueda general retirada', false, 'Debe permanecer apagada. Las preguntas exactas notificadas conservan su ruta segura.'),
                $this->number('questions.page_limit', 'Preguntas por consulta', 50, 1, 100, 'preguntas', 'Límite por página.'),
                $this->number('questions.lookback_hours', 'Ventana de revisión', 48, 1, 720, 'horas', 'Revisa preguntas recientes y reduce duplicados.'),
                $this->bool('questions.email_enabled', 'Enviar alertas por correo', false, 'Requiere que el servidor permita enviar correo.'),
                $this->text('questions.email_to', 'Correo de destino', '', 'email', 'Dirección que recibirá alertas de preguntas.'),
                $this->bool('notifications.enabled', 'Centro de notificaciones activo', true, 'Procesa eventos recibidos por webhooks.'),
                $this->bool('notifications.safe_mode', 'Modo seguro', true, 'Procesa solo eventos y topics confirmados.'),
                $this->number('notifications.max_events_per_run', 'Eventos por ejecución', 20, 1, 100, 'eventos', 'Evita procesos largos.'),
                $this->number('notifications.max_resources_per_account_per_run', 'Máximo por tienda y ciclo', 5, 1, 20, 'recursos', 'Evita que una cuenta monopolice la cola.'),
                $this->number('notifications.pause_between_requests_ms', 'Pausa entre consultas', 750, 0, 10000, 'ms', 'Reduce ráfagas de recuperación.', true),
                $this->number('notifications.max_retries', 'Reintentos', 3, 1, 10, 'intentos', 'Máximo antes de dejar un evento con error.', true),
                $this->number('notifications.cooldown_429_minutes', 'Pausa ante límite API', 30, 1, 1440, 'min', 'Respeta Retry-After cuando está disponible.', true),
                $this->number('notifications.cooldown_403_minutes', 'Pausa ante permisos', 60, 1, 1440, 'min', 'Evita insistir ante un permiso faltante.', true),
                $this->bool('notifications.missed_feeds_enabled', 'Permitir recuperar eventos perdidos', false, 'Habilita la recuperación manual controlada.', true),
                $this->bool('notifications.show_bell', 'Mostrar campana', true, 'Muestra pendientes en la barra superior.', true),
                $this->bool('notifications.show_health', 'Mostrar estado de webhooks', true, 'Presenta el semáforo de recepción.', true),
                $this->bool('notifications.webhook_first_enabled', 'Incorporar cambios desde eventos', true, 'Crea un único trabajo por orden, envío, reclamo, pregunta o producto notificado.'),
                $this->number('notifications.worker_batch_limit', 'Recursos por lote', 15, 1, 50, 'recursos', 'Cantidad máxima que procesa cada ejecución del worker.'),
                $this->number('notifications.worker_time_budget_seconds', 'Tiempo máximo por ejecución', 40, 5, 120, 'segundos', 'El trabajo se detiene de forma segura al consumir este tiempo.'),
                $this->number('notifications.target_sla_seconds', 'Tiempo objetivo de incorporación', 120, 30, 1800, 'segundos', 'Se usa para medir si las ventas entran a tiempo.'),
                $this->number('notifications.debounce_seconds', 'Espera para agrupar avisos repetidos', 5, 0, 60, 'segundos', 'Agrupa cambios consecutivos del mismo recurso antes de consultar.', true),
                $this->number('notifications.backfill_batch_limit', 'Eventos históricos por etapa', 500, 50, 1000, 'eventos', 'Clasifica el historial local sin saturar memoria.', true),
            ]),
            'financial' => $this->sectionDefinition('Conciliación financiera', 'Lotes, billing y recuperación de procesos financieros.', 'money', [
                $this->bool('financial_recalc.enabled', 'Cola financiera activa', true, 'Permite crear y procesar recálculos.'),
                $this->bool('financial_recalc.use_billing_order_details', 'Completar con billing', true, 'Busca datos financieros solo cuando hacen falta.'),
                $this->bool('financial_recalc.auto_billing_for_missing', 'Billing solo para faltantes', true, 'Evita consultar órdenes ya conciliadas.'),
                $this->number('financial_recalc.orders_per_run', 'Órdenes por ejecución', 10, 1, 100, 'órdenes', 'Lote corto y recuperable.'),
                $this->number('financial_recalc.max_orders_per_job', 'Máximo por trabajo', 500, 1, 50000, 'órdenes', 'Limita el tamaño de un trabajo.'),
                $this->number('financial_recalc.billing_order_ids_per_request', 'Órdenes por consulta de billing', 20, 1, 60, 'órdenes', 'Mercado Libre admite hasta 60; se recomienda 20.'),
                $this->number('financial_recalc.time_budget_seconds', 'Tiempo por ejecución', 30, 5, 120, 'seg', 'Permite que el cron regrese antes del timeout.'),
                $this->number('financial_recalc.pause_between_requests_ms', 'Pausa entre consultas', 800, 0, 10000, 'ms', 'Reduce presión sobre la API.', true),
                $this->bool('financial_recalc.safe_mode', 'Modo seguro', true, 'No marca como definitivo un dato incompleto.', true),
                $this->bool('financial_recalc.stop_on_429', 'Pausar ante límite API', true, 'Detiene el lote y conserva el checkpoint.', true),
                $this->bool('financial_recalc.stop_on_403', 'Pausar ante permisos', true, 'Detiene el lote y conserva el checkpoint.', true),
                $this->bool('financial_recalc.reconnect_between_steps', 'Renovar conexión MySQL', true, 'Evita conexiones vencidas durante procesos largos.', true),
            ]),
            'catalogs' => $this->sectionDefinition('Catálogos', 'Paginación, privacidad y trabajos de descripción.', 'file', [
                $this->number('catalog.public_page_size', 'Productos por página pública', 24, 12, 96, 'productos', 'Equilibra rapidez y cantidad visible.'),
                $this->number('catalog.private_page_size', 'Productos por página privada', 48, 12, 120, 'productos', 'La vista interna puede mostrar más elementos.'),
                $this->bool('catalog.tracking_enabled', 'Estadísticas anónimas', false, 'Registra visitas sin guardar IP en texto plano.'),
                $this->number('catalog.views_retention_days', 'Retención de visitas', 90, 7, 730, 'días', 'Elimina datos anónimos antiguos.', true),
                $this->number('catalog.description_job_batch_limit', 'Descripciones por lote', 20, 1, 50, 'productos', 'Procesa y guarda antes de continuar.'),
                $this->number('catalog.description_job_pause_seconds', 'Pausa entre lotes', 30, 10, 3600, 'seg', 'Reduce el riesgo de límite API.'),
                $this->number('catalog.description_job_max_attempts', 'Intentos por descripción', 3, 1, 10, 'intentos', 'Después queda visible como error.', true),
                $this->number('catalog.description_job_retention_days', 'Historial de trabajos', 90, 7, 3650, 'días', 'Conserva resumen y limpia detalle antiguo.', true),
                $this->bool('ui.products_title_tooltip_enabled', 'Mostrar nombre completo de productos', true, 'Muestra una nube únicamente cuando el título de Productos ML está recortado.', true),
                $this->number('ui.products_title_tooltip_hover_delay_ms', 'Espera del nombre completo con mouse', 2000, 500, 10000, 'ms', 'Tiempo que debe permanecer el puntero antes de mostrar el título completo.', true),
                $this->number('ui.products_title_tooltip_focus_delay_ms', 'Espera del nombre completo con teclado', 300, 0, 10000, 'ms', 'Tiempo de espera al enfocar el título mediante teclado.', true),
            ]),
            'system' => $this->sectionDefinition('Sistema, cron y actualizaciones', 'Herramientas de mantenimiento y recuperación.', 'file', [
                $this->bool('update.enabled', 'Enlace protegido de actualización', false, 'Función heredada; el Centro de actualizaciones es el método recomendado.', true),
            ]),
        ];
    }

    public function section(string $key): ?array
    {
        return $this->sections()[$key] ?? null;
    }

    private function sectionDefinition(string $title, string $description, string $icon, array $fields): array
    {
        return compact('title', 'description', 'icon', 'fields');
    }

    private function number(string $key, string $label, int $recommended, int $min, int $max, string $unit, string $help, bool $advanced = false): array
    {
        return compact('key', 'label', 'recommended', 'min', 'max', 'unit', 'help', 'advanced') + ['type' => 'number'];
    }

    private function bool(string $key, string $label, bool $recommended, string $help, bool $advanced = false): array
    {
        return compact('key', 'label', 'recommended', 'help', 'advanced') + ['type' => 'boolean'];
    }

    private function select(string $key, string $label, string $recommended, array $options, string $help, bool $advanced = false): array
    {
        return compact('key', 'label', 'recommended', 'options', 'help', 'advanced') + ['type' => 'select'];
    }

    private function text(string $key, string $label, string $recommended, string $inputType, string $help, bool $advanced = false): array
    {
        return compact('key', 'label', 'recommended', 'inputType', 'help', 'advanced') + ['type' => 'text'];
    }
}
