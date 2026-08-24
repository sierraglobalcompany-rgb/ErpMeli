# K2 Notification Topic Matrix

Scope: forward-only KISS path for new Mercado Libre notifications after the K2 activation watermark.

| Topic | Registry canonical | Durable source | Canonical work | K2 action | Classification |
|---|---|---|---|---|---|
| `orders_v2` / `orders` | `order` | `meli_notification_work_items` | `order_exact` | automatic canonical admission | `V4_AUTOMATIC` |
| `shipments` | `shipment` | `meli_notification_work_items` | none certified in K1B | durable pause with explicit `k2_no_canonical_contract_*` | `BUFFER_ONLY` |
| `payments` | `payment` | `meli_notification_work_items` or ignored event | none; registry says recognized no fetch | no remote work | `LOCAL_ONLY` |
| `items` / `item_prices` / `prices` | `item` | `meli_notification_work_items` | none certified in K1B | durable pause with explicit `k2_no_canonical_contract_*` | `BUFFER_ONLY` |
| `questions` | `question` | `meli_notification_work_items` | none certified in K1B | durable pause with explicit `k2_no_canonical_contract_*` | `BUFFER_ONLY` |
| `claims` / `post_purchase` / `claims_actions` | `claim` | `meli_notification_work_items` | none certified in K1B | durable pause with explicit `k2_no_canonical_contract_*` | `BUFFER_ONLY` |
| `messages` | `message` | `meli_notification_work_items` or ignored event | none; registry says recognized no fetch | no remote work | `LOCAL_ONLY` |

K2 deliberately does not invent shipment, question, claim, item, payment, or message exact workers. Those topics remain durable and visible until a later KISS slice certifies a canonical work contract for each one.
