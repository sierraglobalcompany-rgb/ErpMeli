# K2 Notification Forward Contract

K2 uses the existing durable notification source as business truth and Queue V4 as the current physical work pointer.

Rules:

1. `meli_notification_events` is the durable occurrence/generation identity.
2. `meli_notification_work_items` is the durable resource aggregate.
3. `queue_v4_clean_jobs` is only the current canonical pointer storage.
4. K2 never replays historical notification events. The first scheduler run creates `notifications.k2.forward_from_event_id` from the current max event id.
5. The event watermark is initialized by row existence, not truthiness. An empty event table is a valid frontier with value `0`, so the first later notification can be admitted.
6. Blank, negative, decimal, garbage or overflow watermark values are fail-closed diagnostics: no admissions, reconciliations or source mutations.
7. Only event generations with `latest_event_id > notifications.k2.forward_from_event_id` can be admitted or reconciled by K2.
8. `orders_v2`/`order` work items enter through `CronAdmissionService::submitOrderExact()`.
9. Notification code must not insert Queue V4 rows directly.
10. One durable notification work item can map to multiple sequential canonical generations, but each generation has at most one pointer through `notification:order_exact:work:<work_id>:event:<event_id>`.
11. Duplicate webhook deliveries coalesced into one work item and the same event generation produce one canonical admission and one effective execution.
12. A newer event arriving while the source is `queued` or `running` preserves the active `processing_event_id` and sets `rerun_requested=1`; no inline execution occurs.
13. Source finalization is deterministic by generation: when pointer generation `G` completes, only events `id > activation_event_watermark AND id <= G` are marked processed. If `latest_event_id > G` or `rerun_requested=1`, the source returns to `pending` for a later generation; otherwise it becomes `complete`.
14. Unsupported topics are marked `paused` with an explicit `k2_no_canonical_contract_*` reason; they are not marked complete.
15. If the canonical pointer is active (`ready`, `running`, or `waiting`), the source remains `queued`. If the pointer is terminal non-success (`review` or `dead`), the source is marked safe error/held with evidence.
16. Admission is bounded by the shared `CronDeadlineContext` and a local wall-time guard. If the budget is gone or nearly gone, K2 stops without partially inventing work.
17. Concurrent first-run activation is fail-closed. A loser rereads the committed event watermark and never loses the first post-frontier notification.
18. If `notifications.enabled=0` or `notifications.webhook_first_enabled=0`, K2 creates no new canonical admissions; durable receipt may continue.

The K2 stage is local-only. It performs no Mercado Libre HTTP and does not consume the worker's remote call budget. The existing Queue V4 worker remains the only automatic drainer.
