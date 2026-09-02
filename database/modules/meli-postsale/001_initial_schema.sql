CREATE TABLE IF NOT EXISTS ml_postsale_conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_pack_id VARCHAR(80) NOT NULL,
 external_order_id VARCHAR(80) NULL,status VARCHAR(60) NULL,message_count INT UNSIGNED NOT NULL DEFAULT 0,last_message_at DATETIME NULL,
 snapshot_json JSON NULL,observed_at DATETIME NOT NULL,UNIQUE KEY uq_postsale_conversation(meli_account_id,external_pack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_pack_id VARCHAR(80) NOT NULL,
 external_message_id VARCHAR(120) NOT NULL,sender_role VARCHAR(40) NULL,body_encrypted MEDIUMTEXT NULL,sent_at DATETIME NULL,snapshot_json JSON NULL,
 UNIQUE KEY uq_postsale_message(meli_account_id,external_message_id),KEY idx_postsale_messages_pack(meli_account_id,external_pack_id,sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_message_id VARCHAR(120) NOT NULL,
 external_attachment_id VARCHAR(120) NOT NULL,private_path VARCHAR(500) NULL,mime_type VARCHAR(120) NULL,size_bytes BIGINT UNSIGNED NULL,
 expires_at DATETIME NULL,UNIQUE KEY uq_postsale_attachment(meli_account_id,external_attachment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_claim_id VARCHAR(80) NOT NULL,
 external_return_id VARCHAR(120) NOT NULL,status VARCHAR(60) NULL,external_order_id VARCHAR(80) NULL,units_json JSON NULL,tracking_number VARCHAR(120) NULL,
 destination_json JSON NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,UNIQUE KEY uq_postsale_return(meli_account_id,external_return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_return_shipments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_return_id VARCHAR(120) NOT NULL,
 external_shipment_id VARCHAR(120) NOT NULL,status VARCHAR(60) NULL,tracking_number VARCHAR(120) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_postsale_return_shipment(meli_account_id,external_return_id,external_shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_claim_details (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_claim_id VARCHAR(80) NOT NULL,
 status VARCHAR(60) NULL,stage VARCHAR(60) NULL,reason VARCHAR(500) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_postsale_claim(meli_account_id,external_claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_product_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 external_review_id VARCHAR(120) NOT NULL,rating DECIMAL(4,2) NULL,comment_text TEXT NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_postsale_review(meli_account_id,external_review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_sales_feedback (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,
 rating VARCHAR(40) NULL,comment_text TEXT NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_postsale_feedback(meli_account_id,external_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_postsale_sync_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,job_type VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,
 checkpoint_json JSON NULL,next_run_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_postsale_jobs(status,next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
