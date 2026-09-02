ALTER TABLE system_cold_archives
    MODIFY dataset_key ENUM(
        'notification_events',
        'notification_success',
        'notification_incidents',
        'api_request_logs',
        'cron_health_checks',
        'financial_job_items'
    ) NOT NULL;

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.1',
    'Retención de notificaciones separada entre resultados terminales e incidentes sin bloquear trabajos activos.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
