-- ERP Meli 2.19.6 — ciclo seguro del procesamiento manual.
-- Aditiva e idempotente. No modifica datos comerciales ni realiza llamadas remotas.

-- claimNext() ordena por sesión, estado, elegibilidad e ID. Este índice evita
-- bloquear más filas de las necesarias al reclamar un micro-lote.
SET @has_manual_eligible_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='manual_processing_items'
      AND index_name='idx_manual_item_eligible'
);
SET @add_manual_eligible_index = IF(
    @has_manual_eligible_index=0,
    'ALTER TABLE manual_processing_items ADD KEY idx_manual_item_eligible (manual_processing_session_id,status,next_eligible_at,id)',
    'SELECT 1'
);
PREPARE stmt_add_manual_eligible_index FROM @add_manual_eligible_index;
EXECUTE stmt_add_manual_eligible_index;
DEALLOCATE PREPARE stmt_add_manual_eligible_index;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_processing.visible_item_limit','250','manual_processing',0),
('manual_processing.require_remote_samples','1','manual_processing',0),
('manual_processing.finish_after_current_batch','1','manual_processing',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.6','Ciclo seguro del procesamiento manual y evidencia remota verificable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
