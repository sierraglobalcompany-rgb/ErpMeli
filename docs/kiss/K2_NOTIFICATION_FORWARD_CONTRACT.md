# K2 Notification Forward Contract

K2 uses the existing durable notification source as business truth and Queue V4 as the current physical work pointer.

Rules:

1. `meli_notification_work_items` is the durable source.
2. `queue_v4_clean_jobs` is only the current canonical pointer storage.
3. K2 never replays historical notification rows. The first scheduler run creates `notifications.k2.forward_from_work_item_id` from the current max work item id.
4. Only work items with `id > notifications.k2.forward_from_work_item_id` can be admitted.
5. `orders_v2`/`order` work items enter through `CronAdmissionService::submitOrderExact()`.
6. Notification code must not insert Queue V4 rows directly.
7. One durable notification work item maps to at most one active canonical pointer through `notification:order_exact:work:<work_id>`.
8. Duplicate webhook deliveries coalesced into one work item produce one canonical admission and one effective execution.
9. Unsupported topics are marked `paused` with an explicit `k2_no_canonical_contract_*` reason; they are not marked complete.
10. Source finalization is deterministic: a work item becomes `complete` only after the canonical pointer reaches `completed`.

The K2 stage is local-only. It performs no Mercado Libre HTTP and does not consume the worker's remote call budget. The existing Queue V4 worker remains the only automatic drainer.
