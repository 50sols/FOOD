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

During preflight PR #3218 was OPEN on `Foodex_V2` and changed `backend/app/Enterprise/Finance/AccountsReceivableService.php` and `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`. These shared Finance controller/service files are read-only for #2696. The **initial** PR added only issue-scoped tests and this source record. The latest source-hardening commit also changes the non-overlapping `FinanceWorkbenchAccess.php` guard; see the current checkpoint below. Related #2695 Accountant and #2697 AP Officer have separate issue ownership.

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
by the earlier test/document-only patch. The subsequent Workbench role hardening does not change mobile runtime, Finance endpoints or dispatch.


## 2026-11-16 — Current non-overlapping Workbench source-hardening increment

**Current original PR #3221:** OPEN/DRAFT, base `Foodex_V2` only. Current
source-checkpoint HEAD `fa206eba0e354c28084443832af27c1d638db70b`
(the actual head and exact-head CI must always be rechecked before merging).

**Changed code and tests:**
- `backend/app/Enterprise/Finance/FinanceWorkbenchAccess.php`: require the
  `finance.ar.read` store/legal-entity entitlement for Workbench eligibility
  whenever an actor holds `AR_OFFICER`; `canPerform` now fails closed for all
  non-AR Finance capabilities for that role, even if a second B2B `FINANCE`
  assignment contributes broad `finance.manage`. Normal actors without
  `AR_OFFICER` retain their prior Finance access matrix.
- `backend/tests/Feature/V2ArOfficerAuthorizationContractTest.php`: a
  deterministic active wholesale store and enterprise hierarchy, real legal
  entity AR+GL+AP entitlements, mixed AR/Finance role checks, authorized AR
  Workbench GET in Arabic/English, direct GL journal-create 403 and unchanged
  journals, denied GL/AP/Foundation/approval capability checks, revocation of
  AR entitlement while GL remains and original role/Store isolation tests.
- Latest CI must be checked on this exact head. Earlier terminal-success
  source-fixture head `98ec8fd` does **not** prove these new runtime changes.
  The new HEAD also corrects the exact Pint ordered-import/method-spacing
  failure from predecessor `6734812` (Required run #43868915795).

**High-risk residual acceptance blockers (do not waive):**
1. Standalone `AR_OFFICER` cannot enumerate or resolve a Wholesale context:
   `User::hasInheritedPermission`, `AdminNavigation::canAccess`,
   `TenantContextResolver::wholesale` and
   `FinanceWorkbenchController::contextOptions` apply the intentional B2B
   global-role boundary. The mixed `AR_OFFICER`+`FINANCE` positive journey
   is test evidence for limited Workbench use only, not the required standalone
   AR-only workflow.
2. `FinanceWorkbenchController::accessMatrix` and the Finance/Payables
   domain services remain separately authoritative; the new
   `FinanceWorkbenchAccess` guards do **not** prove all unrelated GL/AP tabs,
   direct domain-service use or Treasury access denied. Generic
   `finance.manage` and an unapproved incompatible-role matrix under #2851
   prevent a full cross-domain SoD assertion.
3. #2633 / original PR #3309 owns shared Finance controller, receivables
   service, Web routes, Dashboard view and AR/EN locale files. They were not
   modified or silently taken over in this issue.
4. Before #2696 merge/closure: implement the positive *standalone*
   B2B AR workflow with trusted scope and navigable entry; enforce exact
   action-level RBAC/SoD/maker-checker, cross-entity/store/company/channel
   authorization, real-row GL/AP/Treasury denial, data preservation, retries,
   audit/recovery and browser AR/EN; re-evaluate Customer/Driver/Van impact
   after any API change; complete a HIGH-risk final-head code review and get
   every required CI check terminal SUCCESS.

**Current status:** SOURCE_INCOMPLETE; **DO NOT MERGE / DO NOT CLOSE**;
no main, version promotion, ZIP release or production deployment.
