-- ERP Meli 2.28.0 — Fase 0 del nuevo camino comercial.
-- Preparación aditiva: flags, contrato de fases y gates. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.0',0,'commercial_path'),
    ('commercial_path.phase0_foundation_ready','1',0,'commercial_path'),
    ('commercial_path.coverage_temporal_enabled','0',0,'commercial_path'),
    ('commercial_path.order_change_sweep_enabled','0',0,'commercial_path'),
    ('commercial_path.rehydrate_existing_orders_enabled','0',0,'commercial_path'),
    ('commercial_path.financial_retry_v2_enabled','0',0,'commercial_path'),
    ('commercial_path.billing_evidence_capture_enabled','0',0,'commercial_path'),
    ('commercial_path.item_scroll_continuity_enabled','0',0,'commercial_path'),
    ('commercial_path.materialized_pacing_enabled','0',0,'commercial_path'),
    ('commercial_path.remote_writes_allowed','0',0,'commercial_path'),
    ('commercial_path.web_views_remote_transport_allowed','0',0,'commercial_path'),
    ('commercial_path.require_api_map_confirmed_endpoints','1',0,'commercial_path'),
    ('commercial_path.phase_order','foundation|coverage|order_change_sweep|rehydration|financial_retry|item_scroll|pacing',0,'commercial_path'),
    ('commercial_path.next_phase','coverage',0,'commercial_path'),
    ('commercial_path.release_gate_update_from_local_dump','1',0,'commercial_path'),
    ('commercial_path.release_gate_clean_install','1',0,'commercial_path'),
    ('commercial_path.release_gate_zero_remote_transport','1',0,'commercial_path'),
    ('commercial_path.release_gate_scope_audit','1',0,'commercial_path'),
    ('commercial_path.release_gate_backup_restore','1',0,'commercial_path')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.28.0',
    'Fase 0 del camino comercial: flags aditivos, orden de fases y gates sin activar nuevas consultas remotas.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
