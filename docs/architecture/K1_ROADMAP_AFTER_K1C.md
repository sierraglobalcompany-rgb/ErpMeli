# K1 roadmap after K1C

1. Merge K1B first, then merge K1C.
2. Deploy through the approved production mechanism only after review.
3. Verify the operator can understand:
   - whether automation is active;
   - how many physical API calls each natural cycle may use;
   - which pending items are ready, waiting or require review;
   - where to process one safe manual step.
4. Keep technical debt visible only in diagnostics until a separate cleanup is authorized:
   - temporary `--max-jobs` alias;
   - internal Queue V4 naming;
   - historical source/batch table names.
5. Do not change Cron cadence, schema, updater or Mercado Libre transport as part of K1C.

