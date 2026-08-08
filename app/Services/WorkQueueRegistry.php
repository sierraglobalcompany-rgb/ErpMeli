<?php

declare(strict_types=1);

namespace App\Services;

final class WorkQueueRegistry
{
    /** @return list<WorkQueueAdapter> */
    public function adapters(): array
    {
        return array_map(
            static fn (array $definition): WorkQueueAdapter => new SqlWorkQueueAdapter($definition),
            $this->definitions()
        );
    }

    /** @return array<string,array<string,mixed>> */
    public function definitionsByKey(): array
    {
        $result = [];
        foreach ($this->definitions() as $definition) {
            $key = (string) $definition['key'];
            $result[$key] = $definition + $this->metadataFor($key);
        }
        return $result;
    }

    /** @return array{description:string,context_route:string,context_label:string,impact:string,automatic_behavior:string} */
    public function metadataFor(string $key): array
    {
        $metadata = [
            'operational_maintenance' => [
                'description' => 'Revisa locks, checkpoints y tareas locales para mantener estable el ERP.',
                'context_route' => '/settings/cron/diagnostics',
                'context_label' => 'Abrir diagnóstico',
                'impact' => 'Solo mantenimiento interno; no cambia ventas.',
                'automatic_behavior' => 'El cron volverá a revisarlo en el siguiente ciclo disponible.',
            ],
            'notification_spool' => [
                'description' => 'Recupera notificaciones que se guardaron temporalmente cuando la base de datos no estaba disponible.',
                'context_route' => '/notifications/health',
                'context_label' => 'Revisar notificaciones',
                'impact' => 'Puede incorporar ventas recibidas por webhook; no modifica Mercado Libre.',
                'automatic_behavior' => 'Se procesa automáticamente antes de trabajos menos urgentes.',
            ],
            'notification_fallback' => [
                'description' => 'Incorpora una venta, envío, pregunta o reclamo informado por Mercado Libre.',
                'context_route' => '/notifications/health',
                'context_label' => 'Revisar notificaciones',
                'impact' => 'Actualiza la copia local del recurso informado.',
                'automatic_behavior' => 'Se reintentará cuando la cuenta y el presupuesto estén disponibles.',
            ],
            'notification_backfill' => [
                'description' => 'Revisa eventos anteriores para recuperar recursos que todavía no llegaron al ERP.',
                'context_route' => '/notifications/health',
                'context_label' => 'Revisar recuperación',
                'impact' => 'Puede crear trabajos locales faltantes sin borrar el historial.',
                'automatic_behavior' => 'Continúa desde su checkpoint en lotes pequeños.',
            ],
            'recurring_sync' => [
                'description' => 'Comprueba qué sincronizaciones programadas deben crear nuevos bloques.',
                'context_route' => '/sync/recurring',
                'context_label' => 'Ver programación',
                'impact' => 'Puede encolar trabajo; no consulta Mercado Libre por sí sola.',
                'automatic_behavior' => 'Se revisa en cada ciclo disponible.',
            ],
            'orders_sync' => [
                'description' => 'Descarga un rango de órdenes de una cuenta y conserva el progreso por bloques.',
                'context_route' => '/sync/schedule',
                'context_label' => 'Ver sincronización',
                'impact' => 'Actualiza órdenes locales y puede encolar enriquecimiento.',
                'automatic_behavior' => 'Continúa desde el último bloque confirmado.',
            ],
            'order_enrichment' => [
                'description' => 'Completa información logística de una orden ya almacenada.',
                'context_route' => '/orders',
                'context_label' => 'Ver órdenes',
                'impact' => 'Actualiza únicamente datos locales de la orden.',
                'automatic_behavior' => 'Se reintentará sin duplicar el recurso.',
            ],
            'sale_pack_reconciliation' => [
                'description' => 'Comprueba qué órdenes API forman cada número de venta de Mercado Libre.',
                'context_route' => '/sales/integrity',
                'context_label' => 'Revisar integridad de ventas',
                'impact' => 'Agrupa órdenes locales y recupera únicamente las hijas que falten.',
                'automatic_behavior' => 'Primero reconstruye relaciones locales y después verifica un pack por turno.',
            ],
            'order_date_repair' => [
                'description' => 'Normaliza fechas locales para que órdenes y auditorías coincidan.',
                'context_route' => '/sales-control/issues',
                'context_label' => 'Ver diferencias',
                'impact' => 'Corrige fechas locales; no consulta Mercado Libre.',
                'automatic_behavior' => 'Continúa desde el último registro confirmado.',
            ],
            'questions' => [
                'description' => 'Busca preguntas nuevas para mostrarlas en el ERP.',
                'context_route' => '/questions',
                'context_label' => 'Ver preguntas',
                'impact' => 'Solo lectura; nunca responde automáticamente.',
                'automatic_behavior' => 'Volverá a consultar cuando la cuenta esté disponible.',
            ],
            'financial_recalc' => [
                'description' => 'Recalcula costos, cobros y netos usando datos que ya están en el ERP.',
                'context_route' => '/financial-recalc',
                'context_label' => 'Ver cola financiera',
                'impact' => 'Actualiza cálculos locales; no consulta Mercado Libre.',
                'automatic_behavior' => 'Continúa por lotes sin repetir órdenes completadas.',
            ],
            'sale_financial_reconciliation' => [
                'description' => 'Reúne cargos oficiales de todas las órdenes que pertenecen a una misma venta.',
                'context_route' => '/sales',
                'context_label' => 'Ver ventas',
                'impact' => 'Actualiza la conciliación local; no modifica Mercado Libre.',
                'automatic_behavior' => 'Captura billing gradualmente y detiene la aprobación si la respuesta es parcial.',
            ],
            'sales_repair' => [
                'description' => 'Recupera diferencias confirmadas por una auditoría de ventas.',
                'context_route' => '/sales-control/issues',
                'context_label' => 'Ver diferencias',
                'impact' => 'Puede consultar órdenes faltantes y actualizar la copia local.',
                'automatic_behavior' => 'Respeta presupuesto y conserva el progreso.',
            ],
            'sales_audit' => [
                'description' => 'Compara las órdenes de Mercado Libre con las almacenadas en el ERP.',
                'context_route' => '/sales-control',
                'context_label' => 'Abrir Control de ventas',
                'impact' => 'Crea un diagnóstico; no modifica datos remotos.',
                'automatic_behavior' => 'Continúa por páginas hasta completar el periodo.',
            ],
            'sales_fiscal' => [
                'description' => 'Completa de forma cifrada los datos fiscales de ventas ya comprobadas.',
                'context_route' => '/sales-control/fiscal',
                'context_label' => 'Ver preparación fiscal',
                'impact' => 'Consulta datos fiscales en modo lectura y nunca emite una factura.',
                'automatic_behavior' => 'Continúa una venta por vez y respeta presupuesto, permisos y pausas.',
            ],
            'catalog_descriptions' => [
                'description' => 'Descarga descripciones faltantes para catálogos y consultas internas.',
                'context_route' => '/catalogs',
                'context_label' => 'Ver catálogos',
                'impact' => 'Actualiza snapshots locales de descripción.',
                'automatic_behavior' => 'Se procesa después de ventas y finanzas.',
            ],
            'items_sync' => [
                'description' => 'Actualiza la copia local de las publicaciones de Mercado Libre.',
                'context_route' => '/products/meli',
                'context_label' => 'Ver productos',
                'impact' => 'No cambia stock, precio ni publicaciones remotas.',
                'automatic_behavior' => 'Continúa por fases y checkpoints.',
            ],
            'module_jobs' => [
                'description' => 'Actualiza snapshots de un módulo aislado, como Insights o Growth.',
                'context_route' => '/settings/modules',
                'context_label' => 'Ver módulos',
                'impact' => 'Afecta únicamente al módulo indicado.',
                'automatic_behavior' => 'Un fallo del módulo no detiene órdenes ni webhooks.',
            ],
        ];

        return $metadata[$key] ?? [
            'description' => 'Trabajo interno registrado para la automatización.',
            'context_route' => '/settings/cron/diagnostics',
            'context_label' => 'Abrir diagnóstico',
            'impact' => 'Revise el detalle antes de intervenir.',
            'automatic_behavior' => 'El cron decidirá cuándo volver a revisarlo.',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function definitions(): array
    {
        return [
            $this->cronState('operational_maintenance', 'Mantenimiento local', 'Mantenimiento', 8, false),
            $this->cronState('notification_spool', 'Recuperar entrada temporal', 'Ventas', 2, false),
            $this->d('notification_fallback', 'meli_notification_work_items', 'Ventas notificadas', 'Ventas', 2, true,
                'SELECT w.id source_id,w.meli_account_id,a.account_name,w.status source_status,1 item_count,
                        IF(w.status="complete",1,0) progress_current,1 progress_total,1 estimated_api_calls,8 estimated_seconds,
                        CONCAT("Incorporar ",w.resource_type,
                          IF(w.occurrence_count>1,CONCAT(" · ",w.occurrence_count," avisos agrupados"),"")) content_summary,
                        w.first_received_at created_at_source,w.next_run_at next_eligible_at,w.last_started_at started_at_source,
                        w.completed_at finished_at_source,w.last_result,w.last_error_message safe_error_message,
                        w.last_error_diagnostic_id diagnostic_id,w.last_error_code normalized_error_code,
                        w.last_error_stage remediation_key,
                        CASE WHEN w.last_error_stage IN ("database","claim","local") THEN 0
                             WHEN w.last_error_stage IN ("api","fencing") THEN 1 ELSE NULL END reached_remote,
                        w.updated_at source_updated_at
                 FROM meli_notification_work_items w LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
                 WHERE w.status IN ("pending","running","retry","paused","error") ORDER BY w.first_received_at ASC'),
            $this->d('notification_backfill', 'meli_notification_backfill_runs', 'Recuperar notificaciones', 'Ventas', 2, true,
                'SELECT b.id source_id,NULL meli_account_id,NULL account_name,b.status source_status,b.source_total item_count,
                        b.analyzed_count progress_current,b.source_total progress_total,b.estimated_api_calls,20 estimated_seconds,
                        CONCAT("Recuperación de ",b.source_total," eventos") content_summary,b.created_at created_at_source,
                        b.next_run_at next_eligible_at,b.started_at started_at_source,b.completed_at finished_at_source,
                        NULL last_result,b.last_error_message safe_error_message,NULL diagnostic_id,b.updated_at source_updated_at
                 FROM meli_notification_backfill_runs b WHERE b.status IN ("draft","analyzing","ready","running","paused","error")
                 ORDER BY b.created_at ASC'),
            $this->cronState('recurring_sync', 'Programación recurrente', 'Ventas', 2, false),
            $this->d('orders_sync', 'sync_batch_chunks', 'Sincronizar órdenes', 'Ventas', 2, true,
                'SELECT c.id source_id,c.meli_account_id,a.account_name,c.status source_status,COALESCE(c.estimated_total,0) item_count,
                        c.processed_count progress_current,COALESCE(c.estimated_total,0) progress_total,
                        1 estimated_api_calls,8 estimated_seconds,
                        CONCAT("Órdenes del ",DATE_FORMAT(c.date_from,"%d/%m/%Y")," al ",DATE_FORMAT(c.date_to,"%d/%m/%Y")) content_summary,
                        c.created_at created_at_source,c.next_run_at next_eligible_at,c.started_at started_at_source,
                        c.completed_at finished_at_source,NULL last_result,c.last_error safe_error_message,NULL diagnostic_id,
                        c.error_type normalized_error_code,c.error_type remediation_key,
                        CASE WHEN c.error_type IN ("database","database_error","local","validation","invalid_request") THEN 0
                             WHEN c.error_http_status IS NOT NULL THEN 1 ELSE NULL END reached_remote,
                        c.updated_at source_updated_at
                 FROM sync_batch_chunks c LEFT JOIN meli_accounts a ON a.id=c.meli_account_id
                 WHERE c.sync_type="orders" AND c.status IN ("pending","queued","running","partial","error")
                 ORDER BY c.created_at ASC'),
            $this->d('order_enrichment', 'order_resource_enrichment_jobs', 'Completar órdenes', 'Ventas', 3, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,1 item_count,
                        0 progress_current,1 progress_total,1 estimated_api_calls,8 estimated_seconds,
                        CONCAT("Completar ",j.resource_type," ",j.external_resource_id," · orden local ",j.meli_order_id) content_summary,j.created_at created_at_source,
                        j.next_run_at next_eligible_at,j.locked_at started_at_source,NULL finished_at_source,NULL last_result,
                        j.last_error_message safe_error_message,j.last_error_diagnostic_id diagnostic_id,
                        j.last_error_code normalized_error_code,
                        CASE WHEN j.status="retry" THEN "automatic" WHEN j.status="error" THEN "manual" ELSE "unknown" END retry_policy,
                        j.failure_class remediation_key,j.reached_remote,j.next_run_at next_retry_at,
                        j.updated_at source_updated_at
                 FROM order_resource_enrichment_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.status IN ("pending","running","retry","error") ORDER BY j.created_at ASC'),
            $this->d('sale_pack_reconciliation', 'sale_pack_reconciliation_jobs', 'Reconstruir ventas agrupadas', 'Ventas', 6, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,1 item_count,
                        IF(j.status="complete",1,0) progress_current,1 progress_total,1 estimated_api_calls,8 estimated_seconds,
                        IF(j.operation="verify_pack",CONCAT("Verificar venta #",j.external_resource_id),
                           CONCAT("Recuperar orden hija #",j.external_resource_id)) content_summary,
                        j.created_at created_at_source,j.next_run_at next_eligible_at,j.started_at started_at_source,
                        j.completed_at finished_at_source,NULL last_result,j.safe_message safe_error_message,
                        NULL diagnostic_id,j.updated_at source_updated_at
                 FROM sale_pack_reconciliation_jobs j
                 LEFT JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.status IN ("pending","running","retry","partial","error","paused")
                 ORDER BY j.priority,j.created_at ASC'),
            $this->d('order_date_repair', 'order_datetime_repair_jobs', 'Reparar fechas', 'Reparaciones', 5, false,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,j.total_orders item_count,
                        j.processed_orders progress_current,j.total_orders progress_total,0 estimated_api_calls,15 estimated_seconds,
                        CONCAT("Normalizar fechas de ",j.total_orders," órdenes") content_summary,j.created_at created_at_source,
                        j.created_at next_eligible_at,j.started_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        j.error_message safe_error_message,NULL diagnostic_id,j.updated_at source_updated_at
                 FROM order_datetime_repair_jobs j LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.status IN ("pending","running","error") ORDER BY j.created_at ASC'),
            $this->cronState('questions', 'Actualizar preguntas', 'Atención', 4, true),
            $this->d('financial_recalc', 'order_financial_recalc_jobs', 'Recalcular financiero', 'Finanzas', 4, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,j.total_items item_count,
                        j.processed_items progress_current,j.total_items progress_total,
                        CASE WHEN COALESCE(j.current_phase,"local_recalc")="billing_import"
                             THEN CEIL(GREATEST(j.total_items-j.processed_items,0)/10) ELSE 0 END estimated_api_calls,
                        15 estimated_seconds,
                        CONCAT("Recalcular ",j.total_items," órdenes") content_summary,j.created_at created_at_source,
                        j.created_at next_eligible_at,j.started_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        COALESCE(j.last_error_message,j.safe_message) safe_error_message,NULL diagnostic_id,
                        CASE WHEN j.last_db_error_message IS NOT NULL THEN "database_error" ELSE NULL END normalized_error_code,
                        CASE WHEN j.last_db_error_message IS NOT NULL OR j.current_phase="local_recalc" THEN 0 ELSE NULL END reached_remote,
                        j.created_at source_updated_at
                 FROM order_financial_recalc_jobs j LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.status IN ("pending","running","error") ORDER BY j.created_at ASC'),
            $this->d('sale_financial_reconciliation', 'sale_financial_reconciliation_jobs', 'Conciliar venta agrupada', 'Finanzas', 6, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,1 item_count,
                        IF(j.status="complete",1,0) progress_current,1 progress_total,1 estimated_api_calls,12 estimated_seconds,
                        CONCAT("Completar finanzas de venta #",j.external_sale_id) content_summary,
                        j.created_at created_at_source,j.next_run_at next_eligible_at,j.heartbeat_at started_at_source,
                        j.completed_at finished_at_source,NULL last_result,j.safe_message safe_error_message,
                        NULL diagnostic_id,j.last_remote_state remediation_key,
                        CASE WHEN j.last_remote_state IS NULL AND j.attempts=0 THEN 0
                             WHEN j.last_remote_state IS NOT NULL THEN 1 ELSE NULL END reached_remote,
                        j.updated_at source_updated_at
                 FROM sale_financial_reconciliation_jobs j
                 LEFT JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.status IN ("pending","running","retry","awaiting_remote","partial","review","error","paused")
                 ORDER BY j.priority_tier,j.next_run_at,j.created_at ASC'),
            $this->d('sales_repair', 'sync_sales_repair_jobs', 'Reparar ventas', 'Reparaciones', 5, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,j.total_items item_count,
                        j.processed_items progress_current,j.total_items progress_total,j.total_items estimated_api_calls,20 estimated_seconds,
                        CONCAT("Reparar ",j.total_items," diferencias de ventas") content_summary,j.created_at created_at_source,
                        COALESCE(j.next_run_at,j.created_at) next_eligible_at,j.started_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        COALESCE(j.safe_error_message,j.error_message) safe_error_message,j.diagnostic_id,j.updated_at source_updated_at
                 FROM sync_sales_repair_jobs j LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.status IN ("pending","running","waiting_budget","retry","paused","partial","error") ORDER BY j.created_at ASC'),
            $this->d('sales_audit', 'sync_sales_audit_jobs', 'Auditar ventas', 'Auditorías', 6, true,
                'SELECT j.id source_id,r.meli_account_id,a.account_name,j.status source_status,r.remote_unique_total item_count,
                        j.processed_pages progress_current,
                        CASE WHEN j.remote_reported_total>0 THEN CEIL(j.remote_reported_total/GREATEST(j.page_limit,1)) ELSE 0 END progress_total,
                        CASE WHEN j.remote_reported_total>0 THEN CEIL(j.remote_reported_total/GREATEST(j.page_limit,1)) ELSE NULL END estimated_api_calls,
                        30 estimated_seconds,
                        CONCAT("Auditoría ",r.period_year,"-",LPAD(r.period_month,2,"0")) content_summary,j.created_at created_at_source,
                        j.next_run_at next_eligible_at,j.started_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        j.safe_error_message,j.diagnostic_id,j.last_error_class normalized_error_code,
                        j.last_error_class remediation_key,
                        CASE WHEN LOWER(COALESCE(j.last_error_class,"")) REGEXP "pdo|database|mysql|mariadb|local" THEN 0
                             WHEN j.last_http_status IS NOT NULL THEN 1 ELSE NULL END reached_remote,
                        j.updated_at source_updated_at
                 FROM sync_sales_audit_jobs j JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
                 LEFT JOIN meli_accounts a ON a.id=r.meli_account_id
                 WHERE j.status IN ("pending","running","waiting_budget","paused","error") ORDER BY j.created_at ASC'),
            $this->d('sales_fiscal', 'sales_control_fiscal_jobs', 'Preparar datos fiscales', 'Finanzas', 6, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,j.total_items item_count,
                        j.processed_items progress_current,j.total_items progress_total,
                        GREATEST(j.total_items-j.processed_items,0) estimated_api_calls,20 estimated_seconds,
                        CONCAT("Preparación fiscal de ",m.period_year,"-",LPAD(m.period_month,2,"0")) content_summary,
                        j.created_at created_at_source,j.next_run_at next_eligible_at,j.started_at started_at_source,
                        j.completed_at finished_at_source,NULL last_result,j.safe_error_message,j.diagnostic_id,
                        j.updated_at source_updated_at
                 FROM sales_control_fiscal_jobs j
                 JOIN sales_control_months m ON m.id=j.sales_control_month_id
                   AND m.company_id=j.company_id AND m.meli_account_id=j.meli_account_id
                 LEFT JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.status IN ("pending","running","waiting_budget","retry","paused","partial","error")
                 ORDER BY j.created_at ASC'),
            $this->d('catalog_descriptions', 'catalog_description_jobs', 'Descargar descripciones', 'Productos', 7, true,
                'SELECT j.id source_id,j.current_account_id meli_account_id,a.account_name,j.status source_status,j.total_items item_count,
                        j.processed_items progress_current,j.total_items progress_total,j.total_items estimated_api_calls,20 estimated_seconds,
                        CONCAT("Descripciones de ",j.total_items," publicaciones") content_summary,j.created_at created_at_source,
                        j.next_run_at next_eligible_at,j.started_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        j.last_error_message safe_error_message,NULL diagnostic_id,j.updated_at source_updated_at
                 FROM catalog_description_jobs j LEFT JOIN meli_accounts a ON a.id=j.current_account_id
                 WHERE j.status IN ("queued","running","waiting","paused_api","error") ORDER BY j.created_at ASC'),
            $this->d('items_sync', 'meli_item_sync_jobs', 'Actualizar productos', 'Productos', 7, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.phase source_status,j.discovered_count item_count,
                        j.processed_count progress_current,j.discovered_count progress_total,j.discovered_count estimated_api_calls,25 estimated_seconds,
                        CONCAT("Publicaciones descubiertas: ",j.discovered_count) content_summary,j.created_at created_at_source,
                        j.next_run_at next_eligible_at,j.locked_at started_at_source,j.completed_at finished_at_source,NULL last_result,
                        j.last_error_message safe_error_message,NULL diagnostic_id,j.updated_at source_updated_at
                 FROM meli_item_sync_jobs j LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.phase IN ("discovering","details","partial","error") ORDER BY j.created_at ASC'),
            $this->d('module_jobs', 'system_module_jobs', 'Trabajo de módulo', 'Módulos', 7, true,
                'SELECT j.id source_id,j.meli_account_id,a.account_name,j.status source_status,j.progress_total item_count,
                        j.progress_current,j.progress_total,j.progress_total estimated_api_calls,30 estimated_seconds,
                        CONCAT(j.module_id,": ",j.job_type) content_summary,j.created_at created_at_source,
                        j.next_run_at next_eligible_at,j.started_at started_at_source,j.finished_at finished_at_source,NULL last_result,
                        j.safe_error_message,NULL diagnostic_id,j.updated_at source_updated_at
                 FROM system_module_jobs j LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.status IN ("pending","running","retry","paused","failed") ORDER BY j.created_at ASC'),
        ];
    }

    /** @return array<string,mixed> */
    private function d(string $key, string $table, string $label, string $category, int $tier, bool $api, string $sql): array
    {
        return compact('key', 'table', 'label', 'category', 'tier', 'api', 'sql');
    }

    /** @return array<string,mixed> */
    private function cronState(string $key, string $label, string $category, int $tier, bool $api): array
    {
        $safeKey = str_replace("'", "''", $key);
        $sql = 'SELECT task_key source_id,NULL meli_account_id,NULL account_name,status source_status,
                       last_work_count item_count,last_processed progress_current,last_work_count progress_total,
                       NULL estimated_api_calls,COALESCE(last_duration_ms,5)/1000 estimated_seconds,
                       CONCAT("' . str_replace('"', '""', $label) . ' · ",last_work_count," elementos conocidos") content_summary,
                       created_at created_at_source,next_run_at next_eligible_at,last_started_at started_at_source,
                       last_finished_at finished_at_source,last_selection_reason last_result,
                       last_error_message safe_error_message,NULL diagnostic_id,updated_at source_updated_at
                FROM cron_task_state WHERE task_key=\'' . $safeKey . '\'';
        return $this->d($key, 'cron_task_state', $label, $category, $tier, $api, $sql);
    }
}
