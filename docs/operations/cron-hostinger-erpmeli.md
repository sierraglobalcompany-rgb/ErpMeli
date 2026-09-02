# Hostinger Cron — ERP Meli Queue V4

Current production Cron command:

```text
/usr/bin/php /home/u390570745/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/queue_v4_clean.php --runtime=45 --max-calls=2
```

Schedule:

```text
* * * * *
```

Rules:

- Keep exactly one ERP Meli Queue V4 Cron entry active.
- Do not duplicate this Cron with legacy `--max-jobs` launchers.
- Do not use manual Cron execution as validation.
- Do not change Billing interval, Billing scope, backoff policy, schema, or version as part of Cron validation.

Known UI caveat:

The ERP screen may still show `Cron físico: No certificado`; operational validation is based on Hostinger Cron visibility plus natural heartbeat/receipt advancement.
