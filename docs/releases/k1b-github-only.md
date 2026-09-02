# K1B GitHub-only release note

K1B is a GitHub-only implementation branch for review.

```text
PRODUCTION_VERSION=2.40.1
PRODUCTION_SCHEMA=300
PRODUCTION_CHANGED_BY_THIS_PR=NO
CRON_CHANGED_BY_THIS_PR=NO
DB_WRITES_BY_THIS_PR=0
REAL_MELI_HTTP_BY_THIS_PR=0
QUEUE_V5_CREATED=NO
NEW_CRON_CREATED=NO
```

This branch introduces the canonical work contract and drainer boundary while preserving current runtime behavior. Queue V4 remains the current physical adapter/drainer until a future reviewed deploy package is explicitly approved.

No production package, updater/cutover, FileZilla ZIP, Hostinger Cron change, schema bump, or version bump is produced by K1B.
