# AGENT_FILE_OWNERSHIP

Generated for ERP MELI 2.40.1 multi-agent release closure.

Rules:
- ONE FILE -> ONE WRITE OWNER.
- Agents may read any file.
- Agents propose patches for owned-by-integrator files; only `/root` edits final release truth/manifests/packages.
- No production, push, deploy, remote merge, or real Mercado Libre HTTP.

## Integrator (/root)
- ERP_MELI_CURRENT_TRUTH.md
- VERSION
- release/build manifests and final evidence package
- final integration commits
- artifact packaging

## Agent 1 — Git / Current Truth / Release Authority
Read-only. Propose only.

## Agent 2 — Build / Updater / Installable Artifact
Read-only until integrator requests exact build command/output. Propose canonical path.

## Agent 3 — Database / Migrations / Upgrade Path
Read-only/local disposable DB only. Propose only.

## Agent 4 — Functional Regression / Operations
Read-only except tests/fixtures if needed and non-overlapping.

## Agent 5 — Security / Config / Deploy Safety
Read-only. Propose only.

## Agent 6 — KISS / Dead Code / Surface Budget
Read-only. Propose SAFE_DELETE/SAFE_MERGE/KEEP_REQUIRED/NO_EVIDENCE only.

## Agent 7 — Red Team / Adversarial Release QA
Runs after integrated local commit only. Read-only/propose only.
