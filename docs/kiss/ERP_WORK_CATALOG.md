# ERP work catalog

## fresh_orders_discovery

- WORK_TYPE: fresh_orders_discovery
- PLANE: DATA_PLANE
- PRODUCER: scheduler/admission producer
- BUSINESS_SOURCE_OF_TRUTH: recent order discovery window
- ADMISSION_BOUNDARY: Queue V4 producer/admission
- PHYSICAL_FIFO_MAPPING: `queue_v4_clean_jobs.job_type=fresh_orders_discovery`
- CANONICAL_WORK_TYPE: V1 supported
- IDEMPOTENCY_AUTHORITY: V4 idempotency key
- AVAILABLE/FIFO_RULE: `available_at ASC, id ASC`
- DRAIN_AUTHORITY: canonical FIFO drainer authority
- CANONICAL_EXECUTOR: `CanonicalFifoDrainer`
- DOMAIN_HANDLER: order discovery service
- REMOTE_ALLOWED: YES, through `MeliApiClient`
- REMOTE_ENDPOINT: order search/discovery endpoints
- RHYTHM_AUTHORITY: `ApiRhythmPolicyService`
- DEADLINE_AUTHORITY: kernel runtime/deadline
- RETRY_AUTHORITY: Queue V4 deferral and API rhythm
- EFFECTIVE_RULE: execute only under claim/lease
- NON_FAILURE_RULE: defer without false functional failure
- FINALIZATION_OWNER: Queue V4 repository
- OBSERVABILITY: attempts/runs/transport journal
- LEGACY_PATHS: V3 discovery paths retired from launcher authority
- STATUS: V4_AUTOMATIC

## order_exact

- WORK_TYPE: order_exact
- PLANE: DATA_PLANE
- PRODUCER: webhook/fresh/sales repair exact admission
- BUSINESS_SOURCE_OF_TRUTH: order resource
- ADMISSION_BOUNDARY: `CronAdmissionService::submit`
- PHYSICAL_FIFO_MAPPING: `queue_v4_clean_jobs.job_type=order_exact`
- CANONICAL_WORK_TYPE: V1 supported
- IDEMPOTENCY_AUTHORITY: source/resource idempotency
- AVAILABLE/FIFO_RULE: `available_at ASC, id ASC`
- DRAIN_AUTHORITY: canonical FIFO drainer
- CANONICAL_EXECUTOR: `CanonicalFifoDrainer`
- DOMAIN_HANDLER: `OrderSyncService::processEnrichmentResource`
- REMOTE_ALLOWED: YES, one exact resource path
- REMOTE_ENDPOINT: order exact endpoint
- RHYTHM_AUTHORITY: API rhythm, not Billing endpoint breaker
- DEADLINE_AUTHORITY: kernel deadline
- RETRY_AUTHORITY: Queue V4 retry/defer
- EFFECTIVE_RULE: one claimed pointer
- NON_FAILURE_RULE: policy/local defer is not functional failure
- FINALIZATION_OWNER: Queue V4 repository
- OBSERVABILITY: attempts and transport events
- LEGACY_PATHS: manual exact remains single-step only
- STATUS: V4_AUTOMATIC

## financial_recalc

- WORK_TYPE: financial_recalc
- PLANE: DATA_PLANE
- PRODUCER: order persistence/financial source
- BUSINESS_SOURCE_OF_TRUTH: order financial state
- ADMISSION_BOUNDARY: V4 admission
- PHYSICAL_FIFO_MAPPING: `domain_exact` / `financial_recalc`
- CANONICAL_WORK_TYPE: V1 supported
- IDEMPOTENCY_AUTHORITY: source idempotency
- AVAILABLE/FIFO_RULE: Queue V4 FIFO
- DRAIN_AUTHORITY: canonical FIFO drainer
- CANONICAL_EXECUTOR: `CanonicalFifoDrainer`
- DOMAIN_HANDLER: `OrderFinancialRecalcJobService`
- REMOTE_ALLOWED: normally local/derived
- REMOTE_ENDPOINT: none unless downstream domain requires
- RHYTHM_AUTHORITY: not Billing breaker
- DEADLINE_AUTHORITY: kernel
- RETRY_AUTHORITY: Queue V4
- EFFECTIVE_RULE: claim then recalc
- NON_FAILURE_RULE: local defer remains non-functional
- FINALIZATION_OWNER: Queue V4/source service
- OBSERVABILITY: V4 attempts
- LEGACY_PATHS: old financial recalculation workers not automatic
- STATUS: V4_AUTOMATIC

## financial_reconciliation

- WORK_TYPE: financial_reconciliation
- PLANE: DATA_PLANE
- PRODUCER: financial source creation/recovery admission
- BUSINESS_SOURCE_OF_TRUTH: `sale_financial_reconciliation_jobs`
- ADMISSION_BOUNDARY: `CronAdmissionService::submit`
- PHYSICAL_FIFO_MAPPING: `domain_exact` / `financial_reconciliation`
- CANONICAL_WORK_TYPE: V1 supported
- IDEMPOTENCY_AUTHORITY: `source:<source_id>`
- AVAILABLE/FIFO_RULE: source/pointer aligned `next_run_at`/`available_at`
- DRAIN_AUTHORITY: canonical FIFO drainer
- CANONICAL_EXECUTOR: `CanonicalFifoDrainer`
- DOMAIN_HANDLER: `SaleFinancialService::processDomainExactBatch`
- REMOTE_ALLOWED: YES, Billing endpoint when permitted
- REMOTE_ENDPOINT: `/billing/integration/group/ML/order/details`
- RHYTHM_AUTHORITY: Billing 900s and B429 30/120/360/720
- DEADLINE_AUTHORITY: kernel deadline plus capacity deferral
- RETRY_AUTHORITY: functional retry vs non-failure defer split
- EFFECTIVE_RULE: Billing may batch up to contract limits
- NON_FAILURE_RULE: PACK_INCOMPLETE and rhythm defer do not penalize attempts
- FINALIZATION_OWNER: SaleFinancialService plus Queue V4 repository
- OBSERVABILITY: transport journal, attempts, safe_message
- LEGACY_PATHS: legacy financial recovery/offline tools require separate authorization
- STATUS: V4_AUTOMATIC

## oauth_refresh

- WORK_TYPE: OAuth control
- PLANE: CONTROL_PLANE
- PRODUCER: scheduler/OAuth supervisor
- STATUS: V4_CONTROL_PLANE
- NOTE: consumes scheduler capacity before worker; not FIFO business work.

## sales_audit

- WORK_TYPE: sales_audit
- PLANE: CONTROL_PLANE/DATA_PLANE
- PRODUCER: annual sales run service
- STATUS: V4_OR_CONTROL_CERTIFIED_BY_RELEASE_SUITE
- NOTE: not changed by R3.

## sales_repair

- WORK_TYPE: sales_repair
- PLANE: DATA_PLANE
- PRODUCER: exact repair service
- STATUS: MANUAL_EXACT/V4_REPAIR_DEPENDING_ON_ENTRY
- NOTE: must remain bounded and idempotent.

## maintenance / backup / reset

- WORK_TYPE: local maintenance
- PLANE: LOCAL_ONLY
- STATUS: LOCAL_ONLY
- NOTE: not automatic queue work.

## notifications / webhooks

- WORK_TYPE: notification/webhook processing
- PLANE: K2_CANONICAL_FORWARD
- PRODUCER: `WebhookSpoolService` / `NotificationWorkItemService` durable ingress
- BUSINESS_SOURCE_OF_TRUTH: `meli_notification_events` as occurrence/generation identity plus `meli_notification_work_items` as resource aggregate
- ADMISSION_BOUNDARY: `QueueV4CleanNotificationAdmissionStage` -> `CronAdmissionService::submitOrderExact`
- PHYSICAL_FIFO_MAPPING: `queue_v4_clean_jobs.job_type=order_exact`
- CANONICAL_WORK_TYPE: V1 `order_exact` for order/order only
- IDEMPOTENCY_AUTHORITY: `notification:order_exact:work:<work_item_id>:event:<notification_event_generation_id>`
- AVAILABLE/FIFO_RULE: V4 `available_at ASC, id ASC` after canonical pointer admission
- DRAIN_AUTHORITY: canonical FIFO drainer
- CANONICAL_EXECUTOR: `CanonicalFifoDrainer`
- DOMAIN_HANDLER: `OrderSyncService::processEnrichmentResource`
- REMOTE_ALLOWED: only when the later `order_exact` pointer is claimed by V4
- RHYTHM_AUTHORITY: normal order endpoint rhythm, not Billing breaker
- DEADLINE_AUTHORITY: K2 admission stage wall-time bound plus kernel deadline
- RETRY_AUTHORITY: Queue V4 for canonical pointer; unsupported notification topics safe-paused
- EFFECTIVE_RULE: forward-only; latest_event_id must be greater than activation event watermark for every K2 mutation
- FINALIZATION_OWNER: source-pointer reconciliation in K2 admission stage
- LEGACY_PATHS: notification/webhook legacy consumers remain retired/blocked, not physically deleted in K2 R1
- STATUS: K2_CANONICAL_FORWARD for order/order; BUFFERED_UNSUPPORTED for shipment/payment/item/question/claim/message/unknown

## legacy Queue Core / V3

- WORK_TYPE: legacy
- PLANE: RETIRED
- STATUS: RETIRED_BLOCKED
- NOTE: not a canonical launcher.
