# V2 #2809 / P071 — Retention-aware administrative user deletion

**Source:** P071 of FOODEX Unified RBAC ERP, parent #2664/P334 and #2503. Integration `Foodex_V2` only. **HIGH risk: identity, retention, privacy, authorization.**

## Reuse → Extend placement

- Existing Security Workbench > Users grid; *no new Sidebar or management screen*. The existing ellipsis menu for an inactive account offers **Review permanent-deletion eligibility** to a Super Admin holding `users.delete`. An explicitly selected single-user preview appears inline on the same Users page, in Arabic/English with RTL/LTR.
- Server authorization applies independently to both preview and DELETE. The action requires `users.delete` **and** the platform SUPER_ADMIN role; a store-scoped/delegated role cannot gain an administrative purge by forging an HTTP request. Permission remains independent of `users.status.manage`.
- No background scheduled delete, bulk delete, new mobile API or destructive customer self-service reuse.

## Fail-closed implementation

- `AdminUserDeletionService::inspect` blocks any active account, self-delete, Platform Owner registry or role, any historical Super Admin role, and any discoverable direct identity relationship. It inspects database tables/columns and **actual foreign keys** (not just a hardcoded finance list), user-actor/role/session/permission/SSO/driver/van/order/finance links, polymorphic audit relationships and pending password resets.
- An incomplete/unknown schema or an unreadable relationship table **denies** deletion. The UI only offers the destructive confirmation form when the chosen account has passed the current preview. The DELETE action rechecks under a database row lock and re-validates typed email, typed `DELETE`, impact acknowledgement and mandatory reason; no client-supplied preview is trusted.
- All financial/operational/audit/permission/SSO/custody rows remain unchanged. No FK child record is detached or cascaded, and no local silent anonymization of financial history occurs.
- A successful, wholly unlinked inactive account removal and its immutable target ID/hash audit are in the same transaction. Failed preflight never revokes credentials, edits the account, or writes a success audit.
- **Legal retention note:** This action is not a substitute for retention/legal classification. Accounts with history remain archived. The confirmation requires an authorized policy/hold assessment for an unlinked identity. Pending P075/#2813 historical seven-account review and P072/#2810 additional guarded lifecycle proof are separate open acceptance obligations; do not claim those complete from this PR.

## Acceptance / tests

- `V2UserPermanentDeletionGuardTest`: real authenticated Web HTTP, exact typed confirmation, active/self/protected account denial, archived account with role + audit denied (no cascade), successful unlinked inactive account audit, forbidden direct HTTP, AR/EN + RTL/LTR.
- Exact-final-head required workflows: Repository Policy, V2 Isolation, Laravel tests + Pint/PHPStan/Composer security, bilingual localization, MySQL/Redis relevant checks. *No CI evidence is asserted in advance of the runs.*
- Customer: **No Flutter/API contract change**; only global Security Dashboard HTTP. Existing Customer self-service deletion and authentication remain separate.
- Driver: **No Flutter/API contract change**; driver history/custody blocks deletion via reference preflight.
- Van: **No Flutter/API contract change**; Van visits, assignments, financial history and B2B-only contracts remain untouched.
- Manual/browser and legal/historical attestation are tracked by #2503/#2813. **Do not close #2664 until all remaining acceptance criteria and exact-head CI are independently satisfied.**

No main, tags, cPanel, production deploy or release.
