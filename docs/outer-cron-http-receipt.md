# Durable outer Cron HTTP receipt — parent-only schema303 candidate

One outer invocation owns one random 40-hex cycle ID and one row in
system_execution_runs, component queue_v4_outer_http. No outer HTTP child
rows or child columns are added. This is telemetry, not a second execution
or admission authority. CLI PHYSICAL_API_CALL remains compatible; the receipt
uses PHYSICAL_HTTP_CALL.

## Synchronous pending lifecycle

reserve validates process-local company/account, source, method and endpoint
category. Duplicate client/transport observation with identical identity is
idempotent; conflicting identity fails closed. No request-specific DB write
occurs before the physical transport boundary.

boundary writes pending_physical_request into the parent after the existing
rhythm, physical budget and prepared transport checks, before Billing's
final commitDispatch. A second distinct pending is rejected before wire;
an existing pending or persistence fault cannot be overwritten. Current
transport is synchronous curl_exec, not curl_multi, a Fiber or background
continuation. Header callbacks collect headers; progress callbacks check
deadline/Queue Core heartbeat, not another MELI request.

No rejecting telemetry write occurs after Billing's final fence and before
curl_exec. enteringWire is process-local/non-throwing. Existing transport
journals, OAuth and Billing fences remain authoritative and unchanged.

Known result persists counters and a unique physical_request_ids entry,
then clears pending. IDs describe known physical responses only, not jobs,
business success or pre-wire boundaries. Endpoint/status counters are known
lower bounds; 206 is a subset of2xx. List length equals physical_http_known
and is naturally bounded by the existing configured physical call limit.

Certified pre-wire cancellation/refund clears pending and persists local
counters in the same parent. Any number of safe refunds produces zero row
growth and no known IDs. Preflight-only blocks remain in memory until close.

Unknown response keeps the durable pending. Known-response telemetry failure
does not replace the business response or authorize resend: pending stays,
future boundaries reject, and closure is incomplete. Open/crashed receipts
always have NULL total, including when the last known response was saved.
A complete receipt requires no pending and no persistence failure.

## Existing history and release boundary

The same transport request ID is retained in api_request_logs, read/OAuth
journals and Billing capture where applicable. They are not counted twice
and their existing archival/rollup policies remain unchanged. A parent
receipt is operational-window evidence, not eternal canonical HTTP history.

Migration303 adds only http_receipt_json and the component/id retention
index to system_execution_runs. No child schema or archive ENUM changes.
Release manifest/updater remain frozen; this DRAFT candidate is not
installation-ready. A separately authorized release/preflight gate must
certify schema303 and metadata before deployment.

No real MELI/OAuth, production access, recovery, merge, deploy or Phase2.
