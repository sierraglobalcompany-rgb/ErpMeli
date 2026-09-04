# K1C decision on K1B legacy debt

K1C does not remove K1B compatibility aliases or historical queue terminology from advanced diagnostics.

Decision:

- Primary operator surfaces must describe capacity as physical API calls and current work as pending items.
- Technical surfaces may still mention Queue V4, worker, job, batch, `--max-jobs`, database tables and source adapters when that wording is necessary for diagnosis or compatibility.
- `--max-calls=N` and legacy `--max-jobs=N` remain only as temporary support overrides documented inside diagnostics; the human command remains `queue_v4_clean.php --runtime=45`.
- K1C does not change drainer behavior, cron cadence, schema, updater, production state or Mercado Libre transport.

Reason:

K1B established the canonical work/drainer contract. K1C is a UX and configuration cleanup on top of that contract, not a second migration. Removing technical debt from runtime compatibility paths belongs to a later explicit cleanup after K1B is merged and production evidence is stable.
