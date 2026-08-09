# 2.36.2 external cutover: byte and DB recovery authority

This runbook closes the two operational P2 findings from the failed 2.36.1
cutover. It is external tooling only: it does not call or modify the application
updater/installer and it does not authorize production deployment.

## P2-A: exact byte authority

Never hash migration or updater files from a checkout. Git attributes and line
ending conversion can make those bytes differ from both the object database and
the final ZIP. The reviewed authority is
`tests/fixtures/release/external-cutover-byte-authority-2362.json`:

- `git_blob_oid` plus `raw_git_sha256` identify bytes returned by
  `git cat-file blob <oid>` and the identical Git-exact ZIP member bytes;
- the legacy `sha256` value is retained as evidence of the 2.36.1 checkout
  hash; when it differs from `raw_git_sha256`, it is explicitly not an
  authority for Git or package verification;
- when `raw_git_sha256` is absent, the legacy and raw/package SHA-256 are
  identical;
- two build-only updater tools may be absent from the managed runtime ZIP, but
  all 29 paths must still match their Git blobs at final HEAD.

Before staging, run the verifier against the reviewed final commit and the exact
candidate ZIP:

```text
php tests/external_cutover_byte_authority_2362.php \
  --commit=<reviewed-final-head> \
  --package=/private/inbox/ERP_MELI_2.36.2_GIT_EXACT.zip
```

Required output contains `updater_locked=29`, `migrations=14`,
`authority=RAW_GIT_OR_PACKAGE_BYTES` and `corrupt_package=REJECTED`. A missing,
duplicated or mismatched required ZIP member blocks the cutover. Checkout hashes
are diagnostic only and cannot override either exact authority.

## P2-B: no idle DB connection across runtime waits

The external filesystem lock remains held for the complete cutover, but a MariaDB
connection is short-lived. Each critical metadata operation follows this order:

1. Open a new non-persistent PDO connection immediately before the operation.
2. Verify MariaDB, schema maximum 293, and exactly migrations 280–293.
3. Acquire the short DB advisory lock on that fresh connection.
4. Start a transaction, lock `app_settings.app.version` with `FOR UPDATE`, verify
   the expected source version, mutate only version metadata, and commit.
5. Release the advisory lock and discard the PDO before pointer/FPM/filesystem
   work. Never park it while waiting for a control file.
6. On rollback signal, repeat steps 1–5 with another fresh connection. Do not
   reuse a promotion, migration or observation handle.

The next production model is schema 293 and `app.version=2.35.1`; therefore
`MIGRATIONS_TO_APPLY_EXPECTED=0`. The metadata transition is directly
2.35.1→2.36.2. A failure after promotion and before pointer activation restores
2.35.1 with a fresh transaction; schema remains 293.

The destructive proof is opt-in and refuses remote hosts, port 3306 and any
fixture without an explicit disposable-clone acknowledgement:

```text
ERP_2362_REHEARSAL_ACK=DISPOSABLE_CLONE_SCHEMA_293
ERP_2362_REHEARSAL_DSN=mysql:host=127.0.0.1;port=<non-3306>;dbname=<clone>;charset=utf8mb4
ERP_2362_REHEARSAL_USER=<fixture-user>
ERP_2362_REHEARSAL_PASS=<fixture-password>
php tests/external_cutover_fresh_connection_2362_mysql.php
```

The test promotes 2.35.1→2.36.2, kills a separately held waiting connection,
performs a no-HTTP runtime boundary, and rolls back 2.36.2→2.35.1 using a new
connection. Required result: `expired_connection=CONFIRMED` and
`rollback_connection=FRESH`. Secrets are supplied only through environment
variables and are never printed.

Both procedures preserve API and Automation STOPPED, `ML_WRITE_ENABLED=false`,
Cron V4 disabled and real Mercado Libre HTTP at zero.
