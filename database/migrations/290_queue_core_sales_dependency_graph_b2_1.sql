-- B2.1: durable logistics -> financial dependency graph.
-- It does not enable Queue Core, import legacy work, or call Mercado Libre.

ALTER TABLE queue_core_pending_capabilities
  MODIFY state ENUM('pending_b2','waiting_dependency','materialized','resolved','review')
    NOT NULL DEFAULT 'pending_b2';

CREATE TABLE IF NOT EXISTS queue_core_capability_edges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  prerequisite_capability_id BIGINT UNSIGNED NOT NULL,
  prerequisite_generation BIGINT UNSIGNED NOT NULL,
  dependent_capability_id BIGINT UNSIGNED NOT NULL,
  dependent_generation BIGINT UNSIGNED NOT NULL,
  edge_key VARCHAR(191) NOT NULL,
  input_version VARCHAR(191) NOT NULL,
  state ENUM('pending','completed','review') NOT NULL DEFAULT 'pending',
  error_class VARCHAR(100) NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_capability_edge
    (dependent_capability_id,dependent_generation,prerequisite_capability_id,prerequisite_generation),
  KEY idx_queue_core_capability_edge_prerequisite
    (prerequisite_capability_id,prerequisite_generation,state,id),
  KEY idx_queue_core_capability_edge_dependent
    (dependent_capability_id,dependent_generation,state,id),
  KEY idx_queue_core_capability_edge_scope
    (company_id,meli_account_id,state,id),
  CONSTRAINT fk_queue_core_edge_prerequisite
    FOREIGN KEY (prerequisite_capability_id) REFERENCES queue_core_pending_capabilities(id),
  CONSTRAINT fk_queue_core_edge_dependent
    FOREIGN KEY (dependent_capability_id) REFERENCES queue_core_pending_capabilities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
