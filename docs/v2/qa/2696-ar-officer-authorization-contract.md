# V2 #2696 — Accounts Receivable Officer authorization (T122R16)

**Integration boundary:** `Foodex_V2` only. Parent #2503. No main, production, release, tag or live finance migration.

## Existing authoritative implementation

The built-in `AR_OFFICER` role is defined in `backend/config/job_roles.php`, seeded by `CoreReferenceSeeder` using `BuiltInJobRoleCatalog`, and localized in `backend/lang/{ar,en}/job_roles.php`. The current role is global with tenant business scope, requires the `finance` module, and grants `finance.view`, `finance.manage` and `reports.view`. It has **no** `finance.post`, `finance.approve`, `reports.export`, treasury/security, order dispatch or inventory grants. Never infer posting or approvals from a broad manage permission.

Placement is **Reuse**, not a new Sidebar route: the existing Finance → ERP Finance workbench (`admin.erp.finance.index`). Server authority is resolved by real role permission, Finance module lifecycle and legal-entity capability entitlement. Its receivables view is the existing `receivables` tab. The access matrix currently maps `finance.ar.read` to `finance.view` plus entitlement. No separate `AR_OFFICER` application screen is justified.

## Regression evidence added

`backend/tests/Feature/V2ArOfficerAuthorizationContractTest.php` verifies:
- Seeded immutable bilingual system role, tenant/global boundary and negative permission bundle.
- Disabled Finance module denies direct workbench access and effective Finance grants; temporary enablement restores grants but not post/approve/export; disabling retains auditable role assignments.
- A scoped per-user **deny** of `finance.manage` wins over the role while retaining read-only permission.
- A global AR Officer with an unrelated Retail Operations assignment cannot receive Finance authority at that Retail store or access its workbench.
- Deactivating the role revokes effective grants without deleting the user-role audit pivot.

**Important coverage boundary:** these focused tests do not certify the entire Accounts Receivable collection/settlement workflow, real ledger reconciliation, approvals, cross-legal-entity data reads, or all Enterprise Finance entitlements. `finance.manage` is an existing broad permission shared by multiple finance actions, so its business-specific mutation scope needs independent reconciliation with Finance/API owners before certifying full T122R16 acceptance. Do not claim that a green role test alone completes the source requirement.

## Concurrent-work exclusion

During preflight PR #3218 was OPEN on `Foodex_V2` and changed `backend/app/Enterprise/Finance/AccountsReceivableService.php` and `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`. These files are read-only for #2696. This PR adds only issue-scoped regression tests and this source record. Related #2695 Accountant and #2697 AP Officer have separate issue ownership.

## Cross-app impact

- **Customer:** no customer app, API, identity, B2B credit, price or order contract changes in this test/document delta; no Flutter work required for this delta.
- **Driver:** no Driver auth, B2C dispatch, wallet or settlements contract changes; B2C Driver-only dispatch invariant unchanged.
- **Van:** no Van auth, B2B visits, consignment, collections or routes change; B2B Van-only dispatch invariant unchanged.

If subsequently implementing AR mutations/API contracts, separately re-evaluate all three applications and link affected V2 mobile issues.

## Verification and completion policy

Run focused Laravel tests, syntax and Pint/PHPStan, AR/EN localization, V2 isolation and repository policy, plus the exact-head Required CI and MySQL/Redis fresh install, upgrade and recovery checks. Merge **only** on green exact head into `Foodex_V2`. Keep #2696 **OPEN** unless all T122R16 source/financial/approval, role scope and cross-app acceptance criteria have actually been completed; manual device/browser UAT is deferred under #2503.


## 2026-11-16 owner-requested source completion checkpoint

The original issue-scoped branch was restored from the preserved historical HEAD,
merged with the latest isolated Foodex_V2 commit (without rewriting its two original
files), and original draft PR #3221 was reopened. Its historical green CI did **not**
represent acceptance of the current isolated head.

**Least-privilege acceptance decision pending source integration:** the merged #3872
negative-channel contract intentionally does not admit AR_OFFICER to the broad B2B
\`global_roles\` or any Retail store role. Existing \`FinanceWorkbenchController::contextOptions\`
requires \`AdminNavigation::canAccess(actor, 'b2b')\` before enumerating wholesale
Finance contexts. Consequently, an otherwise valid AR-only user cannot follow a
positive Finance → Receivables journey today; merely adding \`finance.view\` or
Finance legal-entity entitlements does not fix the admission path. An AR-only
workflow must respect explicit module readiness, legal-entity + store-scoped
AR entitlements, no AP/GL/Treasury access, and deny Retail or foreign company scope.
Global B2B channel admission is **not** a safe substitute.

An additional bilingual real-HTTP regression now exercises direct GL create,
approval-request, posting, opening-balance approval/post attempts and ensures
these are denied to the standalone AR_OFFICER without changing journal/ledger rows.
This covers a negative SoD invariant, **not** the outstanding positive workflow.

**Owned-file coordination:** issue #2633 original PR #3309 is actively editing
\`FinanceWorkbenchController.php\`, \`AccountsReceivableService.php\`,
Finance routes, view and locale files. #2696 has notified its owner and must wait
for integration or explicit file-fence handoff before altering those shared paths.
In parallel, #2696 may edit only its own regression file and this trace.

**Still required before merging/closing:** a real authorized B2B AR-only positive
HTTP and Sidebar journey; per-action draft/manage versus post/approve; legal-entity,
cross-company, store and channel negative evidence; actual maker/checker and
audited collection/recovery boundaries; any required API/app impact trace; reviewed
HIGH-risk changed-file audit; exact-final-head CI. No source-scoped acceptance
is waived by this test increment. Customer/Driver/Van runtime/API remain unchanged
by this test/document-only patch.
