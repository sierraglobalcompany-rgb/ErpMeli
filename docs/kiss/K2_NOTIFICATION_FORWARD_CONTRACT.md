# K2 Notification Forward Contract

K2 uses the existing durable notification source as business truth and Queue V4 as the current physical work pointer.

Rules:

1. `meli_notification_work_items` is the durable source.
2. `queue_v4_clean_jobs` is only the current canonical pointer storage.
3. K2 never replays historical notification rows. The first scheduler run creates `notifications.k2.forward_from_work_item_id` from the current max work item id.
4. The watermark is initialized by row existence, not truthiness. An empty source table is a valid frontier with value `0`, so the first later notification can be admitted.
5. Only work items with `id > notifications.k2.forward_from_work_item_id` can be admitted or reconciled by K2.
6. `orders_v2`/`order` work items enter through `CronAdmissionService::submitOrderExact()`.
7. Notification code must not insert Queue V4 rows directly.
8. One durable notification work item maps to at most one active canonical pointer through `notification:order_exact:work:<work_id>`.
9. Duplicate webhook deliveries coalesced into one work item produce one canonical admission and one effective execution.
10. Unsupported topics are marked `paused` with an explicit `k2_no_canonical_contract_*` reason; they are not marked complete.
11. Source finalization is deterministic: a work item becomes `complete` after the canonical pointer reaches `completed`. If the deterministic pointer already exists as `completed`, K2 converges the source to `complete` instead of creating a duplicate or marking an error.
12. If the canonical pointer is active (`ready`, `running`, or `waiting`), the source remains `queued`. If the pointer is terminal non-success (`review` or `dead`), the source is marked safe error/held with evidence.
13. Admission is bounded by the shared `CronDeadlineContext` and a local wall-time guard. If the budget is gone or nearly gone, K2 stops without partially inventing work.
14. Concurrent first-run activation is fail-closed. A loser rereads the committed watermark and never loses the first post-frontier notification.

The K2 stage is local-only. It performs no Mercado Libre HTTP and does not consume the worker's remote call budget. The existing Queue V4 worker remains the only automatic drainer.
