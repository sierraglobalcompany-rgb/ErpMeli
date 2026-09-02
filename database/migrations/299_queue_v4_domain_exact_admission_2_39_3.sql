-- ERP MELI 2.39.3: admit exact tenant-scoped domain work into the existing Queue V4 FIFO.
-- This migration adds one generic job type and changes no table, index, or other column.

ALTER TABLE queue_v4_clean_jobs
    MODIFY job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL;
