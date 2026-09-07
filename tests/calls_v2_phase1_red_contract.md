# Calls V2 Phase 1 RED contract

Run from the repository root:

```powershell
php tests/calls_v2_phase1_red_contract.php --red
```

This is intentionally a RED-only contract for later product phases. On input
HEAD `6777cca6603a2071cd7529a5c78871b5fdf60e8d`, it exits `1` and identifies:

- automatic Billing grouped neighbor selection and multi-ID GETs;
- pack Billing multi-ID GETs;
- F1, a `PHYSICAL_STARTED` pre-curl marker counted as an exact physical call;
- F2, a known HTTP status that can be lost when the local journal write throws;
- schema 301 evidence insufficiency: `sale_financial_evidence.evidence_type`
  does not permit `billing_order_v2`, so one-order checkpoint evidence fails
  closed pending an authorized migration.

The test reads source and migration text only. It does not connect to MySQL or
Mercado Libre, mutate production code, or expose credentials.
