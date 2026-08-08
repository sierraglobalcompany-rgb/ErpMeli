<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lista cerrada y conservadora. Una tabla que no aparezca aquí nunca se borra.
 *
 * Las identidades configuradas por personas, los cierres y la evidencia quedan
 * fuera de esta política. Los predicados de órdenes e ítems retienen además
 * cualquier fila que alimente un informe o un vínculo local.
 */
final class ImportedMeliDataResetPolicy
{
    /** @return list<array{key:string,label:string,table:string,account_column:string,extra:string,scope:string}> */
    public function operations(): array
    {
        return [
            $this->direct('api_errors', 'Detalle de errores de consultas', 'api_error_logs'),
            $this->direct('api_requests', 'Historial de consultas', 'api_request_logs'),
            $this->direct(
                'work_projection',
                'Proyección operativa derivada de trabajos retirados',
                'system_work_queue_projection',
                'meli_account_id',
                '1=1',
                'work_projection'
            ),
            $this->direct('app_notifications', 'Avisos locales derivados de datos importados', 'app_notifications'),
            $this->direct(
                'question_notifications',
                'Avisos derivados de preguntas importadas',
                'question_notifications',
                'meli_question_id',
                '1=1',
                'question_parent'
            ),
            $this->direct('questions', 'Preguntas importadas', 'meli_questions'),
            $this->direct('claims', 'Reclamos importados', 'meli_claims'),
            $this->direct('notification_work', 'Trabajo de notificaciones', 'meli_notification_work_items'),
            $this->direct('notification_events', 'Notificaciones importadas', 'meli_notification_events'),
            $this->direct('webhooks', 'Eventos webhook almacenados', 'meli_webhook_events'),
            $this->direct(
                'financial_jobs',
                'Trabajos financieros derivados',
                'order_financial_recalc_jobs',
                'meli_account_id',
                '1=1',
                'financial_job'
            ),
            $this->direct(
                'financial_job_items',
                'Recursos restantes de trabajos financieros derivados',
                'order_financial_recalc_job_items',
                'order_financial_recalc_job_id',
                '1=1',
                'financial_job_parent'
            ),
            $this->direct('enrichment_jobs', 'Trabajos de enriquecimiento', 'order_resource_enrichment_jobs'),
            $this->direct('item_jobs', 'Trabajos de publicaciones', 'meli_item_sync_jobs'),
            $this->direct(
                'catalog_description_jobs',
                'Trabajos de descripciones de catálogo',
                'catalog_description_jobs',
                'current_account_id',
                '1=1',
                'catalog_job'
            ),
            $this->direct(
                'catalog_description_job_items',
                'Recursos de trabajos de descripciones de catálogo',
                'catalog_description_job_items'
            ),
            $this->direct('sales_audit_jobs', 'Trabajos de comprobación de ventas', 'sync_sales_audit_jobs'),
            $this->direct('sync_batches', 'Planes de sincronización derivados', 'sync_batches'),
            $this->direct('sync_runs', 'Ejecuciones de sincronización', 'meli_sync_runs'),
            $this->direct('sync_logs', 'Historial de sincronización', 'meli_sync_logs'),
            $this->direct('api_budgets', 'Presupuesto calculado del historial', 'api_budget_windows'),
            $this->direct(
                'api_circuits',
                'Circuitos derivados del historial',
                'api_circuit_breakers',
                'meli_account_id',
                'NOT EXISTS ('
                . 'SELECT 1 FROM api_guard_admin_actions protected_action '
                . 'WHERE protected_action.api_circuit_breaker_id=`api_circuit_breakers`.id'
                . ')'
            ),
            $this->direct('api_capabilities', 'Capacidades comprobadas de la API', 'meli_api_capabilities'),
            $this->direct(
                'insights_account_capabilities',
                'Capacidades derivadas de Insights',
                'ml_insights_account_capabilities'
            ),
            $this->direct(
                'insights_catalog_competition',
                'Comparaciones de catálogo importadas',
                'ml_insights_catalog_competition'
            ),
            $this->direct(
                'insights_item_performance',
                'Rendimiento de publicaciones importado',
                'ml_insights_item_performance'
            ),
            $this->direct(
                'insights_item_prices',
                'Precios de publicaciones importados',
                'ml_insights_item_prices'
            ),
            $this->direct(
                'insights_moderations',
                'Moderaciones importadas',
                'ml_insights_moderations'
            ),
            $this->direct(
                'insights_price_history',
                'Historial de precios importado',
                'ml_insights_price_history'
            ),
            $this->direct(
                'insights_reputation',
                'Reputación importada',
                'ml_insights_reputation_snapshots'
            ),
            $this->direct(
                'insights_jobs',
                'Trabajos derivados de Insights',
                'ml_insights_sync_jobs'
            ),
            $this->direct(
                'manual_campaigns',
                'Campañas anteriores vinculadas a datos importados',
                'manual_campaigns',
                'id',
                '1=1',
                'manual_campaign'
            ),
            $this->direct(
                'manual_events',
                'Bitácora de campañas anteriores',
                'manual_campaign_events',
                'manual_campaign_id',
                '1=1',
                'manual_campaign_parent'
            ),
            $this->direct(
                'manual_items',
                'Recursos de campañas anteriores',
                'manual_campaign_items',
                'manual_campaign_id',
                '1=1',
                'manual_campaign_parent'
            ),
            $this->direct(
                'manual_operations',
                'Operaciones de campañas anteriores',
                'manual_campaign_operations',
                'manual_campaign_id',
                '1=1',
                'manual_campaign_parent'
            ),
            $this->direct('manual_preview_items', 'Recursos de previsualizaciones anteriores', 'manual_campaign_preview_items'),
            $this->direct('manual_previews', 'Previsualizaciones anteriores', 'manual_campaign_previews'),
            $this->direct(
                'manual_reservations',
                'Reservas de campañas anteriores',
                'manual_campaign_reservations',
                'manual_campaign_id',
                '1=1',
                'manual_campaign_parent'
            ),
            $this->direct(
                'manual_processing_sessions',
                'Sesiones heredadas vinculadas a datos importados',
                'manual_processing_sessions',
                'id',
                '1=1',
                'manual_session'
            ),
            $this->direct(
                'manual_processing_items',
                'Recursos del procesador manual heredado',
                'manual_processing_items',
                'manual_processing_session_id',
                '1=1',
                'manual_session_parent'
            ),
            $this->direct(
                'manual_processing_scopes',
                'Alcances del procesador manual heredado',
                'manual_processing_scopes',
                'manual_processing_session_id',
                '1=1',
                'manual_session_parent'
            ),
            $this->direct('notification_recovery_items', 'Recursos de recuperaciones de notificaciones', 'meli_notification_recovery_run_items'),
            $this->direct('product_review_items', 'Resultados importados de revisiones de publicaciones', 'meli_product_update_review_items'),
            $this->direct('product_reviews', 'Revisiones importadas de publicaciones', 'meli_product_update_reviews'),
            $this->direct('product_suggestions', 'Sugerencias calculadas desde publicaciones importadas', 'product_match_suggestions'),
            $this->direct('datetime_repair_jobs', 'Trabajos derivados de reparación de fechas', 'order_datetime_repair_jobs'),
            $this->direct('fiscal_job_items', 'Recursos pendientes de preparación fiscal', 'sales_control_fiscal_job_items'),
            $this->direct('fiscal_jobs', 'Trabajos pendientes de preparación fiscal', 'sales_control_fiscal_jobs'),
            $this->direct('sales_year_runs', 'Ejecuciones derivadas del control anual', 'sales_control_year_runs'),
            $this->direct('sale_financial_jobs', 'Trabajos derivados de conciliación financiera', 'sale_financial_reconciliation_jobs'),
            $this->direct('sale_pack_rebuild', 'Trabajos derivados de reconstrucción de ventas', 'sale_pack_rebuild_runs'),
            $this->direct('sale_pack_jobs', 'Trabajos derivados de verificación de packs', 'sale_pack_reconciliation_jobs'),
            $this->direct('sales_repair_jobs', 'Reparaciones de auditoría anteriores', 'sync_sales_repair_jobs'),
            $this->direct('sync_coverage', 'Cobertura de sincronización', 'meli_sync_coverage'),
            $this->direct('sync_offsets', 'Posiciones de sincronización', 'meli_sync_offsets'),
            $this->direct('sync_checkpoints', 'Puntos de continuación', 'meli_sync_checkpoints'),
            $this->direct(
                'order_billing',
                'Detalle financiero importado no retenido',
                'meli_order_billing_details',
                'meli_account_id',
                $this->deletableOrderRelation('meli_order_billing_details', 'meli_order_id')
            ),
            $this->direct(
                'order_financials',
                'Cálculos financieros no retenidos',
                'meli_order_financials',
                'meli_account_id',
                $this->deletableOrderRelation('meli_order_financials', 'meli_order_id')
            ),
            $this->direct(
                'payments',
                'Pagos importados no retenidos',
                'meli_payments',
                'meli_account_id',
                $this->deletableOrderRelation('meli_payments', 'meli_order_id')
            ),
            $this->direct(
                'shipments',
                'Envíos importados no retenidos',
                'meli_shipments',
                'meli_account_id',
                $this->deletableOrderRelation('meli_shipments', 'meli_order_id')
            ),
            $this->direct(
                'orders',
                'Órdenes sin evidencia retenida',
                'meli_orders',
                'meli_account_id',
                $this->orderEvidenceExclusion('`meli_orders`.id')
            ),
            $this->direct(
                'packs',
                'Paquetes sin órdenes retenidas',
                'meli_packs',
                'meli_account_id',
                $this->packEvidenceExclusion()
            ),
            $this->direct(
                'items',
                'Publicaciones sin vínculos locales',
                'meli_items',
                'meli_account_id',
                'NOT EXISTS (SELECT 1 FROM product_meli_links pml WHERE pml.meli_item_id=`meli_items`.id)'
                . ' AND NOT EXISTS (SELECT 1 FROM catalog_items ci WHERE ci.meli_item_id=`meli_items`.id)'
                . ' AND NOT EXISTS ('
                . 'SELECT 1 FROM product_import_sources pis '
                . 'WHERE pis.source_meli_item_id=`meli_items`.id'
                . ')'
                . ' AND NOT EXISTS ('
                . 'SELECT 1 FROM internal_products ip '
                . 'WHERE ip.source_meli_item_id=`meli_items`.id'
                . ')'
                . ' AND NOT EXISTS ('
                . 'SELECT 1 FROM meli_order_items moi JOIN meli_orders mo ON mo.id=moi.meli_order_id '
                . 'WHERE moi.meli_account_id=`meli_items`.meli_account_id '
                . 'AND moi.external_item_id=`meli_items`.external_item_id'
                . ')'
            ),
        ];
    }

    /** @return list<string> */
    public function protectedTables(): array
    {
        return [
            'companies',
            'company_settings',
            'users',
            'user_company_access',
            'meli_accounts',
            'meli_tokens',
            'meli_oauth_states',
            'app_settings',
            'app_versions',
            'schema_migrations',
            'system_component_schema_contracts',
            'system_update_trusted_keys',
            'internal_products',
            'product_meli_links',
            'catalogs',
            'catalog_items',
            'monthly_reports',
            'monthly_report_items',
            'monthly_report_orders',
            'monthly_adjustments',
            'date_report_runs',
            'date_report_items',
            'date_report_orders',
            'date_report_item_orders',
            'sales_control_closes',
            'sales_control_reopenings',
            'sales_control_fiscal_snapshots',
            'system_backup_archives',
            'system_backup_audit_events',
            'system_retention_runs',
            'imported_data_reset_requests',
            'imported_data_reset_steps',
            'imported_data_reset_generations',
            'imported_data_reset_table_policy',
        ];
    }

    /** @return list<string> */
    public function resetLabels(): array
    {
        return [
            'Puntos de continuación y rangos de sincronización',
            'Cobertura y última sincronización',
            'Trabajos pendientes vinculados a datos eliminados',
            'Presupuesto y circuitos derivados del historial',
        ];
    }

    /**
     * Inventario de negocio conocido por esta versión. No se usan comodines:
     * una tabla nueva con datos de Mercado Libre debe revisarse antes de que
     * el análisis pueda continuar.
     *
     * @return list<string>
     */
    public function explicitBusinessTables(): array
    {
        return [
            'manual_campaign_adapter_health','manual_campaign_events','manual_campaign_items',
            'manual_campaign_operations','manual_campaign_preview_items','manual_campaign_previews',
            'manual_campaign_reservations','manual_campaign_steps','manual_campaign_workers',
            'manual_campaigns','manual_engine_probe_runs','manual_operation_profiles',
            'manual_processing_engine_health','manual_processing_events','manual_processing_items',
            'manual_processing_scopes','manual_processing_sessions',
            'meli_accounts','meli_api_capabilities','meli_billing_capture_runs','meli_categories',
            'meli_claim_events','meli_claims','meli_item_attributes','meli_item_descriptions',
            'meli_item_pictures','meli_item_stock_locations','meli_item_sync_job_items',
            'meli_item_sync_jobs','meli_item_variations','meli_items',
            'meli_notification_backfill_runs','meli_notification_backfill_unique_resources',
            'meli_notification_events','meli_notification_recovery_run_items',
            'meli_notification_recovery_runs','meli_notification_work_items','meli_oauth_states',
            'meli_order_billing_details','meli_order_financials','meli_order_items','meli_orders',
            'meli_pack_order_expectations','meli_pack_orders','meli_packs','meli_payments',
            'meli_product_update_review_changes','meli_product_update_review_items',
            'meli_product_update_reviews','meli_questions','meli_sale_financial_allocations',
            'meli_sale_financial_history','meli_sale_financial_lines','meli_sale_financials',
            'meli_shipment_history','meli_shipment_labels','meli_shipments',
            'meli_sync_checkpoints','meli_sync_coverage','meli_sync_locks','meli_sync_logs',
            'meli_sync_offsets','meli_sync_runs','meli_tokens','meli_webhook_events',
            'order_datetime_repair_items','order_datetime_repair_jobs',
            'order_financial_recalc_job_items','order_financial_recalc_jobs',
            'order_resource_enrichment_job_orders','order_resource_enrichment_jobs',
            'catalog_description_job_items',
            'product_match_suggestions',
            'sale_financial_reconciliation_jobs','sale_pack_rebuild_runs',
            'sale_pack_reconciliation_history','sale_pack_reconciliation_jobs',
            'sale_pack_relation_repair_audit','sales_control_access_audit',
            'sales_control_captures','sales_control_closes','sales_control_fiscal_items',
            'sales_control_fiscal_job_items','sales_control_fiscal_jobs',
            'sales_control_fiscal_snapshots','sales_control_month_sources',
            'sales_control_months','sales_control_reopenings','sales_control_year_runs',
            'sales_control_years','sync_batch_chunks','sync_batches','sync_chunk_runs',
            'sync_data_purge_requests','sync_diagnostics','sync_recurring_rules',
            'sync_sales_audit_days','sync_sales_audit_evidence_events','sync_sales_audit_jobs',
            'sync_sales_audit_missing_orders','sync_sales_audit_remote_ids',
            'sync_sales_audit_run_days','sync_sales_audit_run_orders',
            'sync_sales_audit_run_pages','sync_sales_audit_runs','sync_sales_audits',
            'sync_sales_capture_validations','sync_sales_repair_job_items',
            'sync_sales_repair_jobs',
            'ml_insights_account_capabilities','ml_insights_catalog_competition',
            'ml_insights_item_performance','ml_insights_item_prices',
            'ml_insights_moderations','ml_insights_price_history',
            'ml_insights_reputation_snapshots','ml_insights_sync_jobs',
        ];
    }

    /** @return array{key:string,label:string,table:string,account_column:string,extra:string,scope:string} */
    private function direct(
        string $key,
        string $label,
        string $table,
        string $accountColumn = 'meli_account_id',
        string $extra = '1=1',
        string $scope = 'direct'
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'table' => $table,
            'account_column' => $accountColumn,
            'extra' => $extra,
            'scope' => $scope,
        ];
    }

    private function deletableOrderRelation(string $table, string $column): string
    {
        $qualified = '`' . $table . '`.`' . $column . '`';
        return '(' . $qualified . ' IS NULL OR NOT EXISTS ('
            . 'SELECT 1 FROM meli_orders evidence_order '
            . 'WHERE evidence_order.id=' . $qualified
            . ' AND NOT (' . $this->orderEvidenceExclusion('evidence_order.id') . ')'
            . '))';
    }

    private function orderEvidenceExclusion(string $column): string
    {
        return 'NOT EXISTS (SELECT 1 FROM monthly_report_orders x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM monthly_adjustments x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM date_report_orders x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM date_report_item_orders x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sales_control_fiscal_job_items x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sales_control_fiscal_items x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sales_control_fiscal_snapshots x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sync_sales_audit_run_orders x WHERE x.found_local_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sync_sales_audit_remote_ids x WHERE x.found_local_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM meli_pack_order_expectations x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS (SELECT 1 FROM sale_pack_relation_repair_audit x WHERE x.meli_order_id=' . $column . ')'
            . ' AND NOT EXISTS ('
            . 'SELECT 1 FROM meli_sale_financial_allocations x '
            . 'JOIN meli_order_items protected_item ON protected_item.id=x.meli_order_item_id '
            . 'WHERE protected_item.meli_order_id=' . $column
            . ')'
            . ' AND NOT EXISTS ('
            . 'SELECT 1 FROM meli_sale_financials x '
            . 'JOIN meli_orders protected_order ON protected_order.id=' . $column . ' '
            . 'WHERE x.meli_account_id=protected_order.meli_account_id '
            . 'AND x.identity_type="order" '
            . 'AND x.external_sale_id=CAST(protected_order.external_order_id AS CHAR)'
            . ')'
            . ' AND NOT EXISTS ('
            . 'SELECT 1 FROM meli_sale_financials financial '
            . 'JOIN meli_sale_financial_lines financial_line '
            . 'ON financial_line.meli_sale_financial_id=financial.id '
            . 'JOIN meli_orders protected_order ON protected_order.id=' . $column . ' '
            . 'WHERE financial.meli_account_id=protected_order.meli_account_id '
            . 'AND financial_line.external_order_id='
            . 'CAST(protected_order.external_order_id AS CHAR)'
            . ')';
    }

    private function packEvidenceExclusion(): string
    {
        return 'NOT EXISTS ('
            . 'SELECT 1 FROM meli_pack_orders x WHERE x.meli_pack_id=`meli_packs`.id'
            . ') AND NOT EXISTS ('
            . 'SELECT 1 FROM meli_pack_order_expectations x '
            . 'WHERE x.meli_pack_id=`meli_packs`.id'
            . ') AND NOT EXISTS ('
            . 'SELECT 1 FROM sale_pack_reconciliation_history x '
            . 'WHERE x.meli_pack_id=`meli_packs`.id'
            . ') AND NOT EXISTS ('
            . 'SELECT 1 FROM sale_pack_relation_repair_audit x '
            . 'WHERE x.previous_meli_pack_id=`meli_packs`.id '
            . 'OR x.canonical_meli_pack_id=`meli_packs`.id'
            . ') AND NOT EXISTS ('
            . 'SELECT 1 FROM meli_sale_financials x '
            . 'WHERE x.meli_account_id=`meli_packs`.meli_account_id '
            . 'AND x.identity_type="pack" '
            . 'AND x.external_sale_id=CAST(`meli_packs`.external_pack_id AS CHAR)'
            . ')';
    }
}
