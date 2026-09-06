# Task 7 manual-step browser preparation

Date: 2026-09-05 (America/Bogota)

Status: ready for parent review, without commit or staging. This report covers a disposable localhost browser proof of the manual `Procesar ahora` flow. It is test preparation, not final release certification and not evidence of production throughput.

## Commit authority and ownership

- Branch: `codex/capacity-config`.
- Final observed HEAD: `755016067cd2a586107b5da8daa07e5982819f2b`.
- Browser-owned source: `tests/cap2_browser_step_setup.php`, `tests/cap2_browser_step_router.php`, `tests/cap2_browser_step_wire_fixture.php`, and `tests/cap2_browser_step.js`.
- Browser-owned evidence is under `D:/Codex/tmp/erp-meli/cap2-20260905/qa/browser-step/`; only the eight exact files listed below are direct package evidence.
- This task did not modify runtime, shared fixtures, manifests, registries, packages, migration files, the staging area, or commits.

## Authority and isolation

The CLI setup and HTTP router both require `APP_ENV=test`, `ML_WRITE_ENABLED=false`, DB host `127.0.0.1`, DB port `33079`, and a database matching `^erp_meli_k1d_test_cap2_browser_step_[a-z0-9_]+$`. The HTTP router additionally requires PHP `cli-server` and a loopback client. The full schema is installed once by the guarded CLI setup; no HTTP request runs `Migrator`.

The browser proof uses production `Auth`, `Session`, CSRF and same-origin checks, `SettingsController`, `ManualPreviewService`, `ManualSingleStepService`, `ManualQueueLauncher`, production queue projections/adapters/handlers, physical-budget and dispatch fences, PDO MySQL, and the real manual-processing and result templates. It does not define a fake admission guard or handler. Only the physical cURL boundary is a namespaced test fixture; it records every request to `wire.jsonl` and returns deterministic order/question responses.

The browser context aborts every request not targeting exactly `http://127.0.0.1:18129`. The final assertion found no attempted external host. No real Mercado Libre HTTP, email, SSH, FTP, Cron, deployment, push, merge, or production session/configuration was used.

## TDD progression

The initial RED run reached the local PHP server before the router existed and failed against the missing local state endpoint (`red-browser.log`). Intermediate RED runs found test-preparation defects rather than runtime changes: replay presentation, shared-429 ordering, continuation enum/selection, and the real orders safety window. The last two are intentionally preserved:

- `red-continuation-selection.log` showed that the real projection could select another pending resource before the retry when the limit was one. The final proof selects both real projected resources and verifies both through their own physical calls.
- `red-orders-window.log` showed the production hard safety ceiling of three order-search calls per 15 minutes. The final fixture truthfully mixes the saved order cursor with a question resource; it does not reset the safety limit.

The setup never writes a preview. All preview tokens come from the actual UI. The continuation endpoint removes only its own failure trigger, advances the saved first chunk's `next_run_at` to the current test clock, and refreshes the real projection. It never rewrites status or cursor. The old consumed POST is replayed before the new UI preview and produces zero additional physical requests.

## Final browser behavior

The final cached-CLI Chrome run exited 0 with:

```text
CAP2_BROWSER_STEP_PASS actual UI previews; normal 200 checkpoint; protected 429 truthful stop; post-checkpoint failure; old POST zero-wire replay; explicit new preview continuation; desktop/mobile; LOCAL_NETWORK_ONLY=PASS
```

Observed scenarios:

- Normal selection: two exact resources attended, one complete and one waiting; the order chunk durably saved cursor `1` and the run made exactly two physical calls.
- Post-checkpoint failure: the first order call saved cursor `1`; a test-only SQL trigger rejected insertion of the next queue job before its physical call. The UI showed a safe diagnostic, the second resource was not fabricated complete, and the consumed preview replay made zero wire calls.
- Explicit continuation: after removing only that trigger and advancing the saved resource clock, a new actual UI preview selected both projected resources. The question completed through its own 200 call and the order continued from offset `1` to cursor `2`/complete through its own 200 call. The result reported attended `2`, completed `2`, waiting `0`, review `0`, not started `0`.
- Protected 429: the first selected question made one physical request and stored the protected outcome; the second remained unprocessed. The result reported attended `1`, completed `0`, review `1`, not started `1`; replay of the consumed preview made zero wire calls.
- Desktop evidence uses 1440x1000. Mobile evidence uses 390x844. All four screenshots were visually inspected; the truthful result counts and diagnostic content were visible without an observed overlay or horizontal clipping.

The final wire contains exactly six lines, in order: normal order search, normal question, failure order search, continuation question, continuation order search, and protected question. The final SQL inspection before cleanup showed four consumed previews; normal order partial at cursor `1`; continuation order complete at cursor `2`; normal and continuation questions complete; the protected first question in retry and its second question pending; and five reached-remote HTTP 200 rows plus one reached-remote HTTP 429 row.

## Commands and results

The full-schema setup was run once against the owned empty database:

```text
APP_ENV=test ML_WRITE_ENABLED=false DB_HOST=127.0.0.1 DB_PORT=33079
DB_NAME=erp_meli_k1d_test_cap2_browser_step_final_k8 DB_USER=root
C:/xampphp/php/php.exe tests/cap2_browser_step_setup.php
=> CAP2_BROWSER_STEP_SETUP=PASS DB=erp_meli_k1d_test_cap2_browser_step_final_k8 SCHEMA=301 SOURCES=6
```

The owned hidden PHP server used the same guarded environment plus `APP_URL=http://127.0.0.1:18129` and:

```text
C:/xampphp/php/php.exe -S 127.0.0.1:18129 tests/cap2_browser_step_router.php
```

The actual browser was launched only from the pre-existing cache, with its session/profile under the D evidence directory:

```text
C:/Program Files/nodejs/node.exe <cached-playwright-cli.js> -s=cap2-step-evidence open http://127.0.0.1:18129/__fixture/session
C:/Program Files/nodejs/node.exe <cached-playwright-cli.js> -s=cap2-step-evidence --raw run-code <tests/cap2_browser_step.js contents>
C:/Program Files/nodejs/node.exe <cached-playwright-cli.js> -s=cap2-step-evidence close
=> exit 0, CAP2_BROWSER_STEP_PASS ... LOCAL_NETWORK_ONLY=PASS
```

Static verification:

```text
C:/xampphp/php/php.exe -l tests/cap2_browser_router.php
C:/xampphp/php/php.exe -l tests/cap2_browser_step_router.php
C:/xampphp/php/php.exe -l tests/cap2_browser_step_setup.php
C:/xampphp/php/php.exe -l tests/cap2_browser_step_wire_fixture.php
=> all four: no syntax errors, exit 0

C:/Program Files/nodejs/node.exe --check tests/cap2_browser_config.js
C:/Program Files/nodejs/node.exe --check tests/cap2_browser_step.js
=> both exit 0

git diff --check -- <six browser-owned source files>
=> exit 0
```

The final PHP server log contains no `Fatal`, `Warning`, `Notice`, `Uncaught`, `fixture_error`, or `Failed` match. The owned browser/server were closed and `erp_meli_k1d_test_cap2_browser_step_final_k8` was dropped through the guarded cleanup path.

## Direct evidence inventory

| File | Bytes | SHA-256 |
|---|---:|---|
| `setup.log` | 99 | `CC6263208C0DE4A03583116BAE342F1D5F94FC3249D7C1A4DD8359BBD3F0F689` |
| `final-browser.log` | 10463 | `91D5339115BE93769F53A5453DBB2768BDCA476C20EB7049BC5296EADAE4B0A7` |
| `php-server.log` | 15000 | `FD4D39697B50A73183CE632F64FBAD8BB0C78A5E44E06E6E31E193A3565C76DF` |
| `wire.jsonl` | 462 | `A98E46E6E6AD2AC890E74B638C46C9B25D1A32BC2BB2D130AA5367083E01AA26` |
| `normal-checkpoint-desktop.png` | 162350 | `8E3BAFF77A4C85BE6E09559C22CC349FA2E7DACE672EB12B5A94BF0B1257C15E` |
| `post-checkpoint-failure-desktop.png` | 146995 | `215F12CBD90F658C6307E8C9EAFB1A819E90503AFFF63F154FFD9684DA65733A` |
| `continuation-result-mobile.png` | 129533 | `1536B04EBE878053445AC23E26E96C8697BF0EAB7265736AE1010D70E25A6509` |
| `protected-429-mobile.png` | 128621 | `819108966F9F54EE94D1C31CF8DF46E72A06A4E9ED4A2611D07DC0D454144A96` |

Directories such as `profile`, `.playwright-cli`, `install`, private fixture metadata, post-state inspection, and RED/intermediate logs are not direct package evidence.

## Limitations and remaining authority

- This local fake-wire proof validates admission, controller, preview, launcher, service, handlers, fences, SQL state, and presentation around deterministic physical responses. It is not proof of Mercado Libre availability or real-network behavior.
- Advancing one saved resource's `next_run_at` is controlled test-clock preparation. It is not an operational recovery instruction.
- The evidence does not claim that a ceiling of 55 or 100 is sustainable, nor does it replace the capacity/performance comparison, final whole-matrix regression, package verification, or independent full-branch review.
- Only the parent task has authority to select, stage, commit, package, or claim final Task 7/release completion.
