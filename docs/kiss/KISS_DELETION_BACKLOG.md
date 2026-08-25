# KISS deletion backlog

This backlog is evidence for future cleanup, not authorization to delete in R3.

K5 update: Wave #1 intentionally deletes zero files. The current Queue V4 launcher remains `jobs/queue_v4_clean.php`; all reviewed legacy candidates still have at least one manifest, updater, test, diagnostic, compatibility or K6 recovery dependency. Deletion is therefore deferred file-by-file instead of bundled.

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
| Legacy finance recovery scripts | finance | MANUAL_OFFLINE_ONLY | no normal automatic authority | no manual HTTP allowed in K3 | historical recovery evidence only | finance recovery artifacts + `CronAdmissionService` | K3 finance canonicalization tests | move behind explicit recovery prompt / later delete | NO | historical orphan recovery requires dry-run, selection hash and explicit authorization |
| Direct finance pointer mutations | finance | RETIRED_BLOCKED | no | no | none for normal forward path | `SaleFinancialService` must not own `queue_v4_clean_jobs` | K3 source/pointer contract | delete/refactor if discovered | NO | needs static + MariaDB proof across finance regressions |
| Diagnostic probes | diagnostics | DIAGNOSTIC | read-only expected | no | operator visibility | jobs/services | diagnostic tests | keep read-only | NO | audit utility |
| Duplicate domain handlers | domain | DATA | some active | mixed | possible | service catalog | release suite | consolidate slowly | NO | source ownership proof |
| Direct V4 observability SQL | health/cron pages | OBSERVABILITY | read-only | no | UI | presenters/services | UI tests | keep bounded | NO | performance/EXPLAIN |
| Old compatibility resources | release resources | BUILD | no | no | package attestations | resources/release | package tests | prune future major | NO | managed runtime attestation |
| MeliApiClient source-string branches | API execution | CONTROL | partially active before K4 | yes, via client | simplification target | `MeliApiClient` + `MeliTransportSourcePolicy` | K4 source/capability tests | consolidate into policy snapshot | YES for duplicate interpretation only | do not collapse endpoint security boundaries |
| `cron_v3_rate_gate` bypass | API execution legacy | RETIRED | no active V4 authority | possible if legacy invoked | compatibility/noise | `cron_v3_remote` source | K4 test + legacy tests | remove from normal client path | YES | keep logical legacy classification until K5 |
| `module:*` wildcard source | explicit `module:meli-logistics` caller exists, wildcard does not | RETIRED_BLOCKED | no wildcard authority | no wildcard transport | none for wildcard | rg caller census + K4 R2 module test | K4 R2 source/capability tests | wildcard removed; exact `module:meli-logistics` retained for `GET /shipments/{numeric}` | YES for wildcard only | future module transport still requires named source and matrix |

## K5 physical delete wave #1 gate

| Candidate | K5 action | Why not deleted now |
|---|---|---|
| `jobs/process_sync_queue.php` | KEEP / RETIRED_BLOCKED | legacy retirement tests and updater/runtime manifest still assert the wrapper contract |
| `jobs/cron_v4.php` | DELETE_LATER | release manifest and readiness transition evidence still reference it |
| `jobs/queue_core_canary.php` | DELETE_LATER | Queue Core package/attestation references remain |
| `jobs/queue_core_convergence.php` | DELETE_LATER | legacy authority tests and manifest references remain |
| `jobs/queue_core_deploy_check.php` | DELETE_LATER | runtime manifest reference remains; no replacement package proof yet |
| `jobs/queue_core_health_snapshot.php` | DIAGNOSTIC_ONLY | diagnostic/readiness tests still require the guarded file |
| `jobs/queue_core_historical.php` | K6_RECOVERY | historical recovery evidence is reserved for K6 |
| `jobs/queue_core_preflight.php` | DIAGNOSTIC_ONLY | updater/readiness package references remain |
| `jobs/queue_core_release_certify.php` | DIAGNOSTIC_ONLY | release certification tests still reference it |
| `jobs/queue_core_rollback.php` | K6_RECOVERY | rollback/recovery evidence remains reserved |

Deletion can resume only after a narrow prompt proves `RUNTIME_CALLERS=0`, `ROUTE_REFERENCES=0`, `UPDATER_INSTALL_DEPENDENCY=0`, `K6_RECOVERY_DEPENDENCY=0`, replacement tests pass and `resources/runtime-manifest.json` no longer points to the candidate.
