# ERP KISS change SOP

## 1. Authority

Start every change by naming the current Git head, tree, version, schema and production scope. Reject implicit production actions.

## 2. Architecture trace

Trace producer → source table → V4 pointer → canonical contract → drainer → domain handler → remote/rhythm → finalization before changing code.

## 3. Ownership

Keep business source ownership outside Queue V4. Keep physical FIFO, leases and drain authority inside Queue V4.

## 4. KISS constraint

Prefer one boundary fix over new tables, queues, crons or generalized frameworks. If the change needs a new authority, stop and ask.

## 5. Failure contract

Separate functional failure from non-failure defer. Functional retry may consume attempts; policy/local defer must not create false failure history.

## 6. Concurrency proof

Every claim/takeover/release change needs either single-transaction proof or a two-process race test with independent PDO connections.

## 7. Performance proof

Every FIFO scan or lookahead change needs an upper bound and a real benchmark against representative prefixes.

## 8. Publication

Before release: lint, diff-check, targeted tests, current release suite, manifest dry-run, package attestation and SHA256 package.

## 9. Noise control

Do not report local policy pauses as remote HTTP 429. Do not promise automatic processing without a certified launcher.

## 10. Production

Production install/recovery is a separate explicit prompt. Use dry-run, selection hash, rollback gates and small cohorts for data repair.
