# ERP execution index

This index is intentionally human-readable. It names the current execution surfaces and their authority without granting new runtime authority.

## A. Data plane

### fresh_orders_discovery

- ROLE: discover recent orders and feed exact order work.
- OWNER: Queue V4 canonical drainer.
- FILE: `app/QueueV4Clean/CanonicalFifoDrainer.php`.
- METHOD/SYMBOL: `handle()` dispatches canonical work type.
- STATE AUTHORITY: `queue_v4_clean_jobs`.
- NEXT CALL: order discovery domain service.
- NOTES: V4 automatic, no manual background continuation.

### order_exact

- ROLE: incorporate one exact order resource.
- OWNER: Queue V4 canonical drainer.
- FILE: `app/Services/OrderSyncService.php`.
- METHOD/SYMBOL: `processEnrichmentResource()`.
- STATE AUTHORITY: V4 pointer plus order persistence tables.
- NEXT CALL: `MeliApiClient` only through dispatch fence and rhythm policy.
- NOTES: must not inherit Billing endpoint breaker.

### financial_recalc

- ROLE: local/derived financial recalculation after order persistence.
- OWNER: Queue V4 canonical drainer.
- FILE: `app/Services/OrderFinancialRecalcJobService.php`.
- METHOD/SYMBOL: `processDue()` and exact processing.
- STATE AUTHORITY: `order_financial_recalc_jobs` source rows and V4 pointer.
- NEXT CALL: local calculation / downstream reconciliation admission.
- NOTES: K3 normal forward path is automatic only for tenant/account-owned sources admitted through `CronAdmissionService`; historical orphan repair is a separate recovery workflow.

### financial_reconciliation

- ROLE: official Billing reconciliation.
- OWNER: `SaleFinancialService` business logic plus Queue V4 FIFO/pointer authority and Billing rhythm authority.
- FILE: `app/Services/SaleFinancialService.php`.
- METHOD/SYMBOL: `processDomainExactBatch()`.
- STATE AUTHORITY: `sale_financial_reconciliation_jobs` source and `queue_v4_clean_jobs` pointer.
- NEXT CALL: `MeliApiClient` Billing endpoint if policy permits.
- NOTES: K3 preserves source ownership outside Queue V4. Queue V4 owns claim, lease, contiguous batch selection and parking. Billing batches are same tenant/account, max 60 order ids, 900s/B429 rhythm unchanged. Billing 429 can park only this work family.

## B. Control plane

### CronAdmissionService

- ROLE: create physical V4 pointers from business sources.
- OWNER: admission boundary.
- FILE: `app/Services/CronAdmissionService.php`.
- METHOD/SYMBOL: `submit()`.
- STATE AUTHORITY: `queue_v4_clean_jobs`.
- NEXT CALL: no execution; worker later claims.
- NOTES: must preserve idempotency key and tenant/account isolation.

### QueueV4CleanRepository

- ROLE: durable persistence for authority, claim, lease, completion and defer.
- OWNER: Queue V4 persistence layer.
- FILE: `app/QueueV4Clean/QueueV4CleanRepository.php`.
- METHOD/SYMBOL: `acquireDrainAuthority()`, `claim()`, `releaseDrainAuthority()`.
- STATE AUTHORITY: Queue V4 tables.
- NEXT CALL: kernel or scheduler.
- NOTES: R3 hardened release ordering.

### CanonicalWorkContract

- ROLE: define explicit executable contract.
- OWNER: K1B canonical kernel.
- FILE: `app/QueueV4Clean/CanonicalWorkContract.php`.
- METHOD/SYMBOL: contract constants/factory.
- STATE AUTHORITY: immutable version/type rules.
- NEXT CALL: `CanonicalFifoDrainer`.
- NOTES: unsupported versions/types fail closed.

### CanonicalFifoDrainer

- ROLE: single shared execution kernel.
- OWNER: Queue V4 canonical kernel.
- FILE: `app/QueueV4Clean/CanonicalFifoDrainer.php`.
- METHOD/SYMBOL: `run()`, `handle()`, `handleDomainExact()`.
- STATE AUTHORITY: claim result plus contract.
- NEXT CALL: domain handler.
- NOTES: worker/updater/manual future must use this kernel, not duplicate execution.

### QueueV4CleanWorker

- ROLE: thin automatic adapter.
- OWNER: CLI worker.
- FILE: `app/QueueV4Clean/QueueV4CleanWorker.php`.
- METHOD/SYMBOL: `run()`.
- STATE AUTHORITY: canonical drainer.
- NEXT CALL: `CanonicalFifoDrainer::run()`.
- NOTES: no separate execution semantics.

### QueueV4CleanScheduler

- ROLE: scheduler wrapper and control-plane orchestration.
- OWNER: CLI scheduler.
- FILE: `app/QueueV4Clean/QueueV4CleanScheduler.php`.
- METHOD/SYMBOL: `run()`, `runWithinExecutionWindow()`.
- STATE AUTHORITY: readiness/OAuth/sales audit/worker sequence.
- NEXT CALL: control stages and worker.
- NOTES: FIFO claim applies to eligible V4 work after control-plane slots.

## C. Remote execution

### MeliApiClient

- ROLE: Mercado Libre API transport client.
- OWNER: remote gateway.
- FILE: `app/Services/MeliApiClient.php`.
- METHOD/SYMBOL: request execution internals.
- STATE AUTHORITY: API response/fake transport in tests.
- NEXT CALL: transport, rhythm and result classifiers.
- NOTES: K4 resolves the transport source once through `MeliTransportSourcePolicy::capabilities()` and then consumes that snapshot. It does not own finance, notification, OAuth or sales business semantics.

### MeliTransportSourcePolicy

- ROLE: source capability and security boundary authority.
- OWNER: remote execution policy.
- FILE: `app/Services/MeliTransportSourcePolicy.php`.
- METHOD/SYMBOL: `capabilities()`, `assertAllowed()`.
- STATE AUTHORITY: static source contract, endpoint deny matrix and target classification.
- NEXT CALL: `MeliApiClient`, `CurlMeliHttpTransport`, Queue V4 dispatch fences.
- NOTES: K4 R2 keeps security boundaries explicit. Reduction means removing duplicate interpretation in the client, not collapsing sales, order exact, Billing, readiness, OAuth or modules into one generic source. `queue_v4_clean_readiness` is a preflight-only source for `GET /users/me`; `module:meli-logistics` is the only confirmed module source and may call only `GET /shipments/{numeric}`. `module:*` and `module:meli-*` remain retired.

### MeliReadGateway / meli-logistics

- ROLE: narrow module read gateway for Logistics shipment detail.
- OWNER: module transport boundary.
- FILES: `app/Modules/Shared/Services/MeliReadGateway.php`, `app/Modules/MeliLogistics/Services/LogisticsSyncService.php`.
- METHOD/SYMBOL: `MeliReadGateway::get('meli-logistics', ..., '/shipments/{id}')`.
- STATE AUTHORITY: module caller supplies `module:meli-logistics`; `MeliEndpointRegistry` confirms `/shipments/{numeric}`.
- NEXT CALL: `MeliApiClient` with fake/real transport through normal rhythm, budget and telemetry.
- NOTES: K4 R2 permits only `GET /shipments/{numeric}`. Orders, users, Billing, OAuth, questions and claims are denied for this source; other module ids still fail closed until explicitly certified.

### ApiRhythmPolicyService

- ROLE: pacing, permits, 429 backoff and Retry-After authority.
- OWNER: API rhythm layer.
- FILE: `app/Services/ApiRhythmPolicyService.php`.
- METHOD/SYMBOL: `rateLimitNextSafeAt()`, `dispatched()`.
- STATE AUTHORITY: API rhythm tables.
- NEXT CALL: MeliApiClient/Queue V4 deferral.
- NOTES: Billing 30/120/360/720 and 900s minimum unchanged. Normal current automatic sources use this as the primary rhythm authority; `ApiPacingService` is compatibility only.

### ApiPacingService

- ROLE: legacy compatibility spacing when persistent rhythm schema is unavailable for compatibility/manual paths.
- OWNER: compatibility layer.
- FILE: `app/Services/ApiPacingService.php`.
- STATE AUTHORITY: `api_request_pacing_state`.
- NEXT CALL: none for Queue V4 normal automatic execution.
- NOTES: K4 R1 keeps the class but disconnects it from current V4 automatic sources. It is not a second normal rhythm authority; compatibility callers fail closed if pacing cannot return an enabled reservation.

### QueueV4CleanDispatchFence

- ROLE: bind physical remote transport to current claimed work.
- OWNER: Queue V4 transport fence.
- FILE: `app/QueueV4Clean/QueueV4CleanDispatchFence.php`.
- METHOD/SYMBOL: dispatch fence APIs.
- STATE AUTHORITY: transport context.
- NEXT CALL: journal/result path.
- NOTES: prevents blind remote work.

### OAuth

- ROLE: refresh/control credentials before remote work.
- OWNER: Queue V4 OAuth control plane.
- FILE: `app/QueueV4Clean/QueueV4CleanOAuthSupervisor.php`.
- METHOD/SYMBOL: `run()`.
- STATE AUTHORITY: OAuth operation tables.
- NEXT CALL: OAuth refresh only when policy permits.
- NOTES: not a K1B R3 change.

## D. Observability

### Transport journal

- ROLE: record physical/pretransport API evidence.
- OWNER: Queue V4/API health.
- FILE: `app/QueueV4Clean/QueueV4CleanTransportJournal.php`.
- METHOD/SYMBOL: journal methods.
- STATE AUTHORITY: transport events/logs.
- NEXT CALL: health/presenter/materializer.
- NOTES: needed for B429 truth, not changed by R3.

### Sales audit and sales repair

- ROLE: control/data plane support for annual sales and exact repair.
- OWNER: Queue V4 stages/domain services.
- FILES: `QueueV4CleanSalesAuditStage`, `SalesAuditRunService`, `SalesAuditExactRepairService`.
- STATE AUTHORITY: sales audit tables plus V4.
- NOTES: included in release suite; no R3 semantics change.

### Maintenance/reset/backup

- ROLE: local/manual maintenance surfaces.
- OWNER: local admin services.
- FILES: backup, maintenance, reset services.
- STATE AUTHORITY: local request/session tables.
- NOTES: not automatic queue drainers.

## E. Legacy/retired

### process_sync_queue.php

- ROLE: retired legacy launcher wrapper.
- OWNER: legacy compatibility.
- FILE: `jobs/process_sync_queue.php`.
- STATE AUTHORITY: none for automatic work.
- NEXT CALL: none; normal invocation blocked/retired.
- NOTES: delete later only after K2/deletion audit.

### Queue Core / V3 remnants

- ROLE: historical/diagnostic/offline remnants.
- OWNER: legacy compatibility.
- FILES: `jobs/cron_v4.php`, `jobs/queue_core_*.php`, CronV3 services.
- STATE AUTHORITY: historical tables/resources.
- NEXT CALL: none for canonical automatic processing.
- NOTES: catalogued for deletion backlog, not removed in R3.

### Notifications/webhooks

- ROLE: durable event buffering plus forward-only canonical work admission.
- OWNER: K2 notification forward path.
- FILES: `WebhookSpoolService`, `NotificationWorkItemService`, `QueueV4CleanNotificationAdmissionStage`, `CronAdmissionService`.
- STATE AUTHORITY: `meli_notification_events` ingress and `meli_notification_work_items` durable source; `queue_v4_clean_jobs` remains the technical pointer.
- ADMISSION: `QueueV4CleanNotificationAdmissionStage` reads only event generations after `app_settings.notifications.k2.forward_from_event_id`.
- CANONICAL CONTRACT: order/order notifications become Canonical Work Contract V1 `order_exact` via `CronAdmissionService::submitOrderExact`.
- EXECUTION: `CanonicalFifoDrainer` executes the resulting V4 pointer; source-pointer reconciliation closes only the active pointer generation, never mutates pre-activation events, and preserves newer notification events through rerun even if the previous pointer lands in `review` or `dead`.
- UNSUPPORTED TOPICS: shipment/payment/item/question/claim/message/unknown remain buffered then safe-paused with visible classification; no fake completion.
- STATUS: K2_CANONICAL_FORWARD for order/order, BUFFERED_UNSUPPORTED for other topic families.
- NOTES: historical rows before activation are not replayed or mutated.
