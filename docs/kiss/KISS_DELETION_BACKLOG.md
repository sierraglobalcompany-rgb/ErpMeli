# KISS deletion backlog

This backlog is evidence for future cleanup, not authorization to delete in R3.

| Candidate | Current callers | Plane | Runtime active | Transport capable | Production dependency | Source trace | Test dependency | Target | Safe to delete now | Blocker |
|---|---|---|---|---|---|---|---|---|---|---|
| `jobs/process_sync_queue.php` | legacy tests/resources | RETIRED | no automatic authority | no normal transport | compatibility | rg references and runtime manifest | legacy retirement tests | reduce to stub/delete | NO | package/tests still assert wrapper |
| `jobs/cron_v4.php` | legacy resources | RETIRED | no | no | compatibility | `LEGACY_AUTOMATION_RETIRED` | legacy tests | delete later | NO | release manifest cleanup |
| `jobs/queue_core_*.php` | diagnostics/releases | DIAGNOSTIC | not canonical | must remain read-only unless offline authorized | tooling | runtime manifest | readiness tests | move offline | NO | historical package references |
| CronV3 services | settings/readiness history | RETIRED | not automatic | mixed historical | UX/reporting | service classes | V3 readiness tests | remove UI dependency | NO | UI/diagnostic transition |
| Queue Core migrations 280-293 | historical schema | LEGACY_SCHEMA | no new migration | no | installed history | migrations | migrator/history tests | keep historical | NO | schema history immutable |
| Historical import paths | import/reset | LOCAL_ONLY | local only | no | admin tools | services | reset/import tests | document | NO | user workflows |
| Old transport fallback sources | API health | OBSERVABILITY | no launcher | maybe logs only | evidence | API presenter/services | health tests | normalize names | NO | incident history |
| `ApiPacingService` fallback | API/rhythm compatibility | CONTROL | no primary authority | indirect | compatibility | service refs | pacing regressions | remove after rhythm unification | NO | B429 regressions |
| Manual exact web executor | manual UI | MANUAL_EXACT | while tab open only | bounded | allowed exception | manual services | manual exact tests | keep | NO | AGENTS contract |
| Webhook worker legacy paths | notifications | K2_CANONICAL_FORWARD/RETIRED | K2 order/order forward path active by strict event generation frontier; legacy duplicate consumers inactive | no direct transport in webhook path | compatibility/backlog evidence | notification services + K2 stage | K2 R3 event-generation tests | keep stubs/retired until post-K2 cleanup | NO | physical deletion deferred to K3+ review |
| Diagnostic probes | diagnostics | DIAGNOSTIC | read-only expected | no | operator visibility | jobs/services | diagnostic tests | keep read-only | NO | audit utility |
| Duplicate domain handlers | domain | DATA | some active | mixed | possible | service catalog | release suite | consolidate slowly | NO | source ownership proof |
| Direct V4 observability SQL | health/cron pages | OBSERVABILITY | read-only | no | UI | presenters/services | UI tests | keep bounded | NO | performance/EXPLAIN |
| Old compatibility resources | release resources | BUILD | no | no | package attestations | resources/release | package tests | prune future major | NO | managed runtime attestation |
