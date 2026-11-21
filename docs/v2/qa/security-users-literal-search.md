# V2 #3675 — Security Dashboard and Admin API literal user search

Parent #2503 / #2818 (P082). Integration **Foodex_V2 ONLY**. No main, tags, APKs, cPanel, release or production.

## Verified defect / placement
Two existing authorized security user-read surfaces treated `q` entered by an operator as raw SQL LIKE syntax rather than literal text: `Admin/SecurityController::index` searches name, username and email; `Api/V1/SecurityController::users` searches name and email. `%` or `_` can match unrelated rows. Values were already bound via Eloquent so this is *not an SQL injection claim or demonstrated RBAC bypass*, but user-facing match correctness and potential overbroad result set.

**Reuse** `/admin/security?section=users&q=...` and `/api/v1/admin/security/users?q=...` with no new route/page. Keep Dashboard `security.view` and API `users.view` OR `security.view` authorization, MFA/security middleware, role user payload, status/assignment filters, sort, pagination, AR/EN and RTL/LTR existing UI.

Trim search input; encode `!`→`!!`, `%`→`!%`, `_`→`!_` and preserve literal backslash. Surround the escaped text with one deliberate leading/trailing `%` for substring matching. Bind `name LIKE ? ESCAPE '!'` and `username/email LIKE ? ESCAPE '!'` where applicable, retaining existing grouped OR filters under authorized user listing. Empty q remains unfiltered after authorization. Do not alter any role, permission, user status or mutation API.

## Risk and test contract

`REVIEW_RISK: HIGH` — privileged user identity and RBAC list visibility. Inspect each file for Gate placement, input validation, wildcard/escaped-bang abuse, no ungrouped OR or authorization widening, no user/role audit mutations, and rollback as a pure source revert.

Extend `backend/tests/Feature/EnterpriseRbacTest.php`, which already uses `RefreshDatabase`, `CoreReferenceSeeder`, real `SUPER_ADMIN` and B2B role fixtures. Verify literal name and username matches on the browser route, literal name/email matches on authenticated Sanctum API, special characters leading and embedded, decoys, empty/nonmatching text, q-only wildcard, existing active-status and per-page filters, explicit 403 for unauthorized role, and absence of user/role assignment writes. Other suite cases validate security governance, role mutation, audit and effective permissions.

Focused command: `cd backend && php artisan test --filter=test_security_user_search_is_literal_on_dashboard_and_admin_api_without_role_mutation`. Final integration requires exact-final-head Repository Policy, V2 Isolation and Required CI including Laravel, PHP/Pint/PHPStan/security, AR/EN and MySQL/Redis acceptance, terminal `required-ci-gate` SUCCESS.

## Impact matrix

| Component | Impact |
| --- | --- |
| Admin Dashboard | Existing Security Center authorized user-list literal search only |
| Admin API | Existing privileged security users list `q` filtering only; same authorization and response schema |
| Customer Flutter | NOT affected — does not consume privileged admin user list, no identity/login/roles/token API change |
| Driver Flutter | NOT affected — no B2C dispatch, delivery/wallet, public API/Flutter change |
| Van Flutter | NOT affected — no B2B Van order/custody/route/offline/public API/Flutter change |

There is no schema/migration, session/token change, queue, scheduler, notification or monetary transaction. B2B=VAN ONLY and B2C=DRIVER ONLY unaffected.

## Acceptance / handoff
One issue/one branch/one PR, exactly four files: the two existing SecurityControllers' read q blocks, one authentic security HTTP regression in existing EnterpriseRbacTest and this QA doc. Same-account technical review must be recorded as `COMMENTED`, **not** independent GitHub `APPROVED`; classify risk HIGH and verify negative tests, exact final CI and no overlapping worker file fence. SHA-guarded merge only to Foodex_V2, close only when verified CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with PR, tested SHA, integration SHA and three CI links. Human/business/staging UI acceptance remains in master #2503, OPEN. No main/release/production.
