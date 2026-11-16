# FOODEX V2 #2806 / P068 — distinct Disable, Archive and conditional Delete

**Target:** `Foodex_V2` only. **Review risk:** HIGH (identity, sessions, RBAC, retention). This is the unfinished explicit archive-state acceptance after original PR #3308 (already merged), not a duplicate of its ellipsis/menu work. Original user lifecycle links remain under Administration Hub → Security Users; no new Sidebar or public API.

## Authoritative states and actions

| State | Persisted proof | Authorized action | Data retained |
| --- | --- | --- | --- |
| Active | `is_active=1, archived_at=NULL` | Temporary Deactivate or deliberate Archive | All |
| Temporarily disabled | `is_active=0, archived_at=NULL` | Activate, Archive, eligible Delete preview | All until conditional deletion |
| Archived | `is_active=0, archived_at!=NULL, archived_by` | Explicit Restore archived account; eligible Delete preview guarded by P071 | All historical finance, tokens revoked |
| Permanently removed | No `users` row, immutable audit target hash | Only on an inactive, truly unlinked account by P071 #2809 | All linked accounts fail closed; no cascades |

Archive action requires active `SUPER_ADMIN` and independent `users.status.manage`; direct store-delegated calls fail 403. Admin must enter reason, exactly matching account email, `ARCHIVE` and impact acknowledgment. P072 protected self/owner/last active Super Admin and unfinished Driver/Van assignments/visits are enforced under same transaction and lock order as existing deactivation. Duplicated archival requests deny with no extra success event. Archive never modifies stored role grants, documents, invoices, receivables, custody, tax/ledger rows or uploads.

Archive reuses `RbacManager::setUserActive` to revoke API tokens and sessions before durable `archived_at` marking, with the outer database transaction ensuring both changes and `user.archived` audit commit or roll back atomically. An already inactive target keeps its original `deactivated_at` but has any lingering tokens revoked. Restoring is a separate explicitly labeled action available only to the global Super Admin; existing typed-confirmation/status service clears archived state and audits activation. The original irreversible delete route remains guarded by `users.delete`, `AdminUserDeletionService` full reference/retention preflight, and role restrictions (#2809).

## Source security review — historical archival-actor deletion provenance

The additive `users.archived_by` RESTRICT self-FK creates a retained link **within the users table**. P071's general table inventory intentionally skips the users row, so the permanent-deletion preflight must separately inspect incoming `users.archived_by` references. The existing `AdminUserDeletionService::inspect` now returns `references` before any mutation when a previously privileged archival actor is referenced by another archived identity, even if that actor has since lost roles and has no ordinary linked record. The P071 direct HTTP deletion path rejects the attempt with the localized existing retention reason and zero destructive audit event. The additive `V2UserPermanentDeletionGuardTest` covers preview, direct denial, preserved both identity rows, intact archival provenance and no deletion audit. No cascading delete and no new approval bypass.

Pint is enforced on exact HEAD; the prior failed fast-fail was one extra blank line in `V2DistinctUserArchiveLifecycleTest`, corrected on the original PR. Archive stale-identity typed confirmation and archived no-op status denial are verified by issue-owned negative tests; keep final exact-head CI and high-risk source review as mandatory merge gates.

## Placement and AR/EN

Existing Users 3-dot action menu now exposes Archive separately from Deactivate and the per-record Permanent Delete eligibility preview. The archived row is labeled Archived rather than merely Inactive; the control becomes Restore archived account. All AR/EN labels, errors, warnings and impact notes reuse original locale and modal. Buttons retain shared responsive/keyboard/RTL behavior. No database IDs or JSON are shown as user inputs.

## Security acceptance and regressions

`V2DistinctUserArchiveLifecycleTest` asserts: positive archive + restore, preserved role assignments and historical data, token revocation, deactivation timestamp unaffected by later archive, audit provenance, direct denied Wholesale actor and self, live Van visit denial without side effects, invalid confirmation and duplicate replay fail closed, AR/EN RTL/LTR and existing Security Users navigation. Existing `V2UserOpenAssignmentOffboardingTest`, `V2UserPermanentDeletionGuardTest`, `V2UserLifecycleRowActionsTest`, `V2UserSoftArchiveRetentionTest` and `V2ProtectedUserLifecycleTest` cover independent source prerequisites.

## App / API impact

- **Dashboard: affected** — existing Security Users row/actions and server route, real persisted archived marker, no additional Sidebar. 
- **Customer: no Flutter/API contract change** — platform customer privacy deletion remains separately guarded and cannot be archived through the admin-only destructive path; existing deactivation token revocation unchanged.
- **Driver: no Flutter/API contract change** — active B2C delivery assignments block offboarding; session revocation retained; B2C DRIVER ONLY.
- **Van: no Flutter/API contract change** — active B2B custody/visits block offboarding; session revocation retained; B2B VAN ONLY.

**Source/code acceptance:** implemented under existing authorization. Final manual mobile/browser/a11y business UAT and external legal-hold attestation deferred to V2 final #2503 / #2750, never claimed performed. P075/#2813 seven historical-account current-state attestation remains an **independent issue** and cannot be fabricated; the runtime P071 per-account retain-or-deny path remains fail-closed until legal clearance. No `main`, tag, release, cPanel or production changes.

**CI / merge evidence:** Pending required exact-HEAD Policy + V2 Isolation + full Required CI and HIGH source audit; do not merge or close until terminal green.
