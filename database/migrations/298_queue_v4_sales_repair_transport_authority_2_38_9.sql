-- ERP MELI 2.38.9: make Exact Sales Repair a first-class Queue V4 transport source.
-- No table or column is added; the immutable physical journal accepts the new source.

ALTER TABLE queue_v4_clean_transport_events
    MODIFY source_kind ENUM('queue','oauth','sales_audit','sales_repair') NOT NULL;
