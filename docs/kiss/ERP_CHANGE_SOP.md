# ERP KISS change SOP

## 1. Authority

Start every change by naming the current Git head, tree, version, schema and production scope. Reject implicit production actions.

## 2. Architecture trace

Trace producer → source table → V4 pointer → canonical contract → drainer → domain handler → remote/rhythm → finalization before changing code.

## 3. Ownership

Keep business source ownership outside Queue V4. Keep physical FIFO, leases and drain authority inside Queue V4.

For finance work, `order_financial_recalc_jobs` and `sale_financial_reconciliation_jobs` are the durable source authority. Queue V4 may create/claim/defer/complete physical pointers, select a bounded Billing batch and park only `financial_reconciliation`; it must not become the owner of financial business semantics. `SaleFinancialService` owns Billing business decisions but must not update `queue_v4_clean_jobs` directly.

For notification/webhook work, `meli_notification_events` is the occurrence/generation authority and `meli_notification_work_items` is the durable resource aggregate. K2 may only mutate generations strictly after the stored event activation watermark and at or before the active `processing_event_id`. Historical notification events require a separate recovery prompt.

## 4. KISS constraint

Prefer one boundary fix over new tables, queues, crons or generalized frameworks. If the change needs a new authority, stop and ask.

## 5. Failure contract

Separate functional failure from non-failure defer. Functional retry may consume attempts; policy/local defer must not create false failure history.

## 6. Concurrency proof

Every claim/takeover/release change needs either single-transaction proof or a two-process race test with independent PDO connections.

## 7. Performance proof

Every FIFO scan or lookahead change needs an upper bound and a real benchmark against representative prefixes.

For K3 finance, due `PACK_INCOMPLETE` prefixes must be measured as due work, not hidden behind future `available_at`; Billing batch scans remain capped at 60.

## 8. Publication

Before release: lint, diff-check, targeted tests, current release suite, manifest dry-run, package attestation and SHA256 package.

## 9. Noise control

Do not report local policy pauses as remote HTTP 429. Do not promise automatic processing without a certified launcher.

For API execution changes, resolve each transport source once through `MeliTransportSourcePolicy::capabilities()` and keep the API client generic. Do not merge source labels unless the endpoint permissions, dispatch fences, rhythm authority, budget behavior and manual/automatic boundary are identical.

Readiness and module labels are especially narrow: readiness is preflight-only (`GET /users/me`), while module HTTP has no wildcard authority. If a module needs Mercado Libre transport, add one explicit source, one exact endpoint family, one caller census, and one behavioral fake-transport test proving allowed and denied paths. K4 R2's only module source is `module:meli-logistics` for `GET /shipments/{numeric}`.

## 10. Production

Production install/recovery is a separate explicit prompt. Use dry-run, selection hash, rollback gates and small cohorts for data repair.

## 11. API authority

`ApiRhythmPolicyService` is the primary persistent timing authority for current automatic Mercado Libre requests. `ApiBudgetService` is a workload allowance, not timing. `ApiPacingService` is compatibility only unless a future prompt proves otherwise.

Compatibility pacing is not a blind fallback: if persistent rhythm is unavailable and pacing cannot produce an enabled reservation, the request must fail closed before fake or real transport.
