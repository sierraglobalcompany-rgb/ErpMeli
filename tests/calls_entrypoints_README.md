# Calls HTTP and scope QA

Run only in the coordinated local Migrator slot:

```powershell
C:/xampphp/php/php.exe tests/calls_entrypoints_http_mysql.php
C:/xampphp/php/php.exe tests/calls_entrypoints_http_mysql.php --readiness-browser
```

The default run creates one guarded, disposable MySQL database on localhost:33079 and migrates through schema 301. It starts its own hidden PHP server on port 18143, reads the actual SettingsController route declarations, and uses production Router/route metadata/Auth/session/CSRF/same-origin/controller/view/service code. Only the Meli cURL boundary is replaced. It checks G08 negatives before adding the G05 scope fixtures. All temporary files stay under `D:/Codex/tmp/erp-meli/calls-20260906/entrypoints`. The server stops and database drops in `finally`.

G05 seeds real notifications, orders, recalc, audit, item discovery, description children and an existing canonical V4 notification admission. It does not add a duplicate V4 pointer. It checks all 9 supported scopes, rejects modules/unknown, compares exact source identities and persisted HTML row counts, verifies tenant isolation, and temporarily removes the legacy campaign table from the disposable schema. The finance presentation check uses 61 eligible resources, 60 displayed rows and a one-call budget. Preview persistence is allowed; business/source/job changes and Meli transport are not.

The optional browser stage runs after those read-only snapshots, reusing the schema and server on port 18145. Its CLI fixture adds the third connected account, encrypted dummy OAuth records, global access for the dummy permanent admin, a real password hash, the installed-version marker, stopped automation and manual capacity 1. It invokes the versioned runner `tests/calls_browser_runner.cjs`, which loads Playwright from `CALLS_PLAYWRIGHT_PACKAGE_JSON` when set, then from the local `.tooling/playwright-runner` candidates. It uses actual assets and full Cron HTML. It never fakes authentication, readiness, admission or transport accounting.

For bounded debugging, append `--hold-browser`. On browser failure only, the runner stays alive without further work and writes non-secret connection metadata to `browser-debug.json`. Creating `release-browser.flag` under the same evidence directory releases the wait into normal server/database cleanup. Do not leave this debug mode running after inspection. It is a test server, not an ERP background worker.

Limit: the cronTest case exercises truthful failure on an intentionally incomplete installation and proves zero business/remote effects; it does not certify a healthy installed release or real physical Cron. No local fixture certifies production quota sustainability.
