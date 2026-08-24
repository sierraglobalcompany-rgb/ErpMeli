# K2 notification noise backlog

Scope: K2 forward-only notification/webhook canonical path. This backlog classifies old or duplicate notification execution surfaces. It is evidence for future cleanup, not authorization to delete runtime files in K2.

| Path / surface | Current role | K2 classification | Forward duplicate risk | Target | Notes |
|---|---|---|---|---|---|
| `public/webhook_mercadolibre.php` | Lightweight Mercado Libre ingress | KEEP | NO | KEEP | Validates, persists/deduplicates and returns; no heavy remote execution inline. |
| `app/Services/WebhookSpoolService.php` | Durable file/DB spool bridge | KEEP | LOW | KEEP | Remains durable ingress adapter; K2 admission uses durable work-items after activation watermark. |
| `app/Services/WebhookService.php` | Persists notification events and work-items | KEEP | LOW | KEEP | Business ingress truth for notifications. |
| `app/Services/NotificationWorkItemService.php` | Legacy direct work-item processing | LOGICALLY_RETIRED | MEDIUM | MERGE_LATER | Already skips direct processing when Queue V4 owns webhook; do not re-enable as drainer. |
| `jobs/process_webhooks.php` | Legacy notification job entrypoint | RETIRED_BLOCKED | MEDIUM | DELETE_LATER | Not a K2 launcher; no second Cron. |
| `app/QueueCore/WebhookTriggerService.php` | Queue Core webhook trigger | RETIRED_BLOCKED | MEDIUM | DELETE_LATER | Legacy Queue Core path; not canonical K2. |
| `app/QueueCore/WebhookProducer.php` | Queue Core webhook producer | RETIRED_BLOCKED | MEDIUM | DELETE_LATER | Do not use for new notification forward path. |
| Manual notification retry/requeue surfaces | Admin/manual exact handling | MANUAL_EXACT | LOW | KEEP_WITH_LABEL | Must remain explicit one-step/manual, never automatic background. |
| Unsupported topics (`shipments`, `items`, `questions`, `claims`, `payments`, `messages`) | Durable but no certified canonical work | BUFFER_ONLY | NO | FUTURE_KISS_SLICE | K2 marks safe paused/unsupported, no HTTP and no fake completion. |
| `orders_v2` / `order` | Exact order notification | V4_AUTOMATIC | NO | KEEP | K2 maps to existing `order_exact` canonical work through `CronAdmissionService`. |

Required K2 target:

- `NEW_FORWARD_PATHS_ACTIVE=1`
- `LEGACY_DUPLICATE_FORWARD_CONSUMERS=0`
- Physical deletion waits for a later KISS cleanup after runtime callers, production dependency, transport capability and tests are all zero.
