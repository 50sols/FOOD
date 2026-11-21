# V2 #3627 — Inline branch metadata lease guard

## Scope and reproduction

Parent #2931 and master #2503. On the isolated `Foodex_V2` source, `parseClaim()` read the complete value of the `BRANCH:` line. A worker claim such as:

```text
<!-- foodex-worker-state:v1 -->
STATE: ACTIVE
OWNER: worker
BRANCH: fix/3625-v2-smart-qr-literal-search; BASE: Foodex_V2 ONLY
```

previously produced the invalid ref `fix/3625-v2-smart-qr-literal-search; BASE: Foodex_V2 ONLY`. A 404 during branch inspection hides real recent commits; a claim older than 30 minutes may therefore be released even if active development continues. This is a **reproducible parser mismatch**; it is **not** proof that the distinct external/manual watchdog comment recently observed on #3625 originated from this staged script, nor evidence that the 5-minute scheduler is enabled.

## Correction

The existing parser now strips explicit trailing `; BASE: ...` or `; TARGET: ...` annotations from `BRANCH` and `ORIGINAL_BRANCH` while retaining the real supplied Git ref. It does not synthesize a ref, change owner or PR selection, alter the original-claim timestamp, accept heartbeat-only activity as proof, or change the 30-minute threshold. Fully idle claims continue to release normally; current-commit and exact-head CI protections remain authoritative. No new worker, branch or PR deletion behavior.

## Regression

- Direct `parseClaim` assertions for annotated `BRANCH` and `ORIGINAL_BRANCH` and same-owner checkpoint identity.
- Full mocked `run()` assertions confirm branch lookups use the unannotated ref; a fresh 10-minute-old branch commit prevents release, while a truly idle 40-minute-old claim is released with the original audit labels/comment.
- Existing pagination, approval-gate, merged/closed original PR, racing successor and malformed API fail-closed tests remain unchanged.

## Surfaces / exclusions

Only V2 Node watchdog script and scoped tests change. No Backend/API business logic, Dashboard UI, Customer, Driver, Van, Laravel DB schema, AR/EN translations, auth/tenant/store/finance, or fulfillment contract is affected. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

The watchdog schedule is **not** operational until separately authorized on a default-branch dispatcher or external runner. #2931 must remain OPEN; this leaf does not certify unattended scheduling, main, release, tags, cPanel or production.

## Delivery gate

One issue-scoped PR to `Foodex_V2` only. Require Node regression and exact-final-HEAD Repository Policy, V2 Isolation, Required CI and applicable independent review before leaf completion.
