CREATE TABLE IF NOT EXISTS meli_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_question_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(40) NULL,
    seller_id BIGINT UNSIGNED NULL,
    buyer_id BIGINT UNSIGNED NULL,
    status VARCHAR(80) NULL,
    text TEXT NULL,
    answer_text TEXT NULL,
    asked_at DATETIME NULL,
    answered_at DATETIME NULL,
    raw_json JSON NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_question (meli_account_id, external_question_id),
    KEY idx_meli_questions_status (meli_account_id, status, asked_at),
    KEY idx_meli_questions_item (meli_account_id, external_item_id),
    CONSTRAINT fk_meli_questions_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_question_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('internal','email','whatsapp_future') NOT NULL DEFAULT 'internal',
    status ENUM('pending','sent','skipped','error') NOT NULL DEFAULT 'pending',
    destination VARCHAR(255) NULL,
    error_message VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_question_notifications_status (status, channel, created_at),
    UNIQUE KEY uq_question_notification (meli_question_id, channel),
    CONSTRAINT fk_question_notifications_question FOREIGN KEY (meli_question_id) REFERENCES meli_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('questions.sync_enabled', '0', 'questions', 0),
('questions.page_limit', '50', 'questions', 0),
('questions.lookback_hours', '48', 'questions', 0),
('questions.email_enabled', '0', 'questions', 0),
('questions.email_to', '', 'questions', 0),
('questions.endpoint_confirmed', '0', 'questions', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
