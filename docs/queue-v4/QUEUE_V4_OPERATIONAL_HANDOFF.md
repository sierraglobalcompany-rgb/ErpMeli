# Queue V4 Clean — operational handoff

## Certified baseline

```text
FUNCTIONAL_BASELINE=PASS
OPERATIONAL_CERTIFICATION=PASS
VERSION=2.37.2
PENDING_MIGRATIONS=0
ENGINE=ACTIVE
READINESS=CERTIFIED
SCHEDULER_ENABLED=YES
OAUTH=3/3
READINESS_GET=3/3
REAL_TRAFFIC=PASS
AUTOMATIC_CRON_PROCESSING=PASS
MANUAL_PROCESSOR_USED=NO
QUEUE_CLEAN_IDLE=YES
READY=0
RUNNING=0
WAITING=0
REVIEW=0
DEAD=0
CRON_ERRORS_OBSERVED=0
```

Queue V4 Clean processed real traffic autonomously through the Hostinger Cron:
a naturally produced job moved from `ready` through automatic processing to
`completed`, without using **Procesar ahora**. The final executable and error
queues were empty.

This is the functional and operational baseline. Do not reopen Queue V4
architecture, recovery, compatibility, or migration work unless a real runtime
error appears. Queue V2, Queue V3, and the previous Queue Core are not
operational authorities.

## Non-blocking P2 debt

1. The administrative UI reports `automation_not_stopped`,
   `scheduler_not_stopped`, and `engine_active` as blocked conditions after
   activation. Those conditions are expected in `ACTIVE/CERTIFIED`. During the
   next normal settings/UI intervention, distinguish pre-activation
   requirements from current active status. Do not issue a release solely for
   this message.
2. The UI does not expose `last_scheduler_at` or a short Cron execution history.
   Treat that as future observability work, not an operational blocker.

## Handoff

```text
P0=0
P1=0
P2=2_NON_BLOCKING
CODE_CHANGED=NO
NEW_RELEASE_CREATED=NO
NEXT_SINGLE_ACTION=RETURN_TO_MAIN_ERP_ROADMAP
```
