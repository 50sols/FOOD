# V2 #3868 — Retail-linked Wholesale account status HTTP422 AR/EN guard

- **Integration:** `Foodex_V2` only; parent #2503 remains open for consolidated acceptance.
- **REVIEW_RISK: HIGH** — identity, user status and financial/tenant-adjacent backend API. Human-readable response localization only; the authorization boundary, Retail-derived state, account/customer/store IDs and transaction/audit policy must remain unchanged.
- **Source:** `PATCH /api/v1/admin/b2b/accounts/{account}/status`, `B2bAccountController::updateStatus`. Existing authenticated `b2b.accounts.manage` gate is authoritative. A Retail-linked Wholesale account may not be overridden independently of its Retail store status.
- **Translation:** user saved `ar` or `en` takes precedence over contradictory `Accept-Language`; unsupported saved locale follows the existing request helper. EN remains byte-for-byte `Retail-linked Wholesale account status is controlled by the Retail store status.`.

## File-by-file technical review checklist

| Path | Intended change | Abuse/rollback/compatibility |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/B2bAccountController.php` | Replace only the pre-existing HTTP422 error-string literal with a Laravel translation lookup and existing locale resolver. | Same authorized Gate, Retail store lookup, active/inactive comparison, HTTP422 and early abort; no database write, account status transition or entitlement expansion. Revert only the replacement for rollback. |
| `backend/lang/ar/b2b_account_mapping_errors.php` | Add one Arabic `retail_status_managed` key. | No English fallback in Arabic. Key parity with EN, prior key unchanged. Revert new key only. |
| `backend/lang/en/b2b_account_mapping_errors.php` | Add one English `retail_status_managed` key. | Exact previous message retained. Key parity with AR, prior key unchanged. Revert new key only. |
| `backend/tests/Feature/V2RetailLinkedB2bAccountStatusLocaleTest.php` | Seeded, authenticated real-HTTP status rejection AR/EN matrix, active/suspended store states, contrary header, unauthenticated and FINANCE denial, unchanged account/store and no creation of watched business/audit records. | Negative tests must fail if an unauthorized override or locale regression is introduced. Extend rather than weaken if CI finds a fixture issue. |
| `docs/v2/qa/retail-linked-b2b-account-status-422-ar-en.md` | Scope, adversarial tests, app impact and evidence register. | Documentation only, no release. |

## Negative, positive and recovery checks

1. Retail store active → derived linked account `active`; an authorized B2B Admin trying `suspended` receives HTTP422 in saved locale, account remains active and store unchanged.
2. Retail store inactive → derived account `suspended`; trying `active` receives identical localized HTTP422, no write.
3. AR saved + EN header and EN saved + AR header each show correct exact text. Only human message changes; keep JSON `message` and numeric 422 contract intact.
4. No authenticated actor → 401; FINANCE actor lacking `b2b.accounts.manage` → 403. Do not permit status override through role/menu visibility.
5. On each rejection, counts of Accounts, B2B Customers, Retail links, Stores, Users, Orders, Invoices, Payments, Inventories, Stock Movements and Audit Logs remain stable; account status, store state, owner principal locale, and association unchanged.
6. Run existing `B2bAccountLifecycleTest` positive create/activate and existing `V2B2bAccountMissingMappingLocaleTest` alongside this new test, so the existing independent non-Retail path stays functional. No migration, rebuild or data conversion.

**Rollback:** this is a source-only change with no migration or persistent state. Revert controller message lookup and paired catalog additions via a reviewed GitHub PR if a regression is observed. Preserve Retail status control and all data.

## Surface / mobile impact

- **Backend/API:** Existing Admin Wholesale PATCH status endpoint's rejected 422 human `message` is localized; unchanged response shape/status, route, authorization, tenant and mutation behavior.
- **Dashboard:** Can display the already-provided localized API error; no route, Sidebar, view, action or navigation implementation changed.
- **Customer:** NOT AFFECTED. No Customer public API/DTO, auth, checkout, notification or Flutter files; no app issue or build required for this administrative error-string path.
- **Driver:** NOT AFFECTED. B2C DRIVER ONLY assignment, trips, auth and app API unchanged.
- **Van:** NOT AFFECTED. B2B VAN ONLY dispatch, ownership, identity and app API unchanged.
- **Release:** No `main`, production, tag, ZIP or APK is authorized by this patch.

## CI evidence register — complete from exact final PR HEAD only

- [ ] Laravel focused tests and full backend suite, PHP syntax / Pint / PHPStan / security and static checks.
- [ ] Localization AR/EN and contract/OpenAPI compatibility.
- [ ] MySQL/Redis install/upgrade/recovery, where invoked by the required CI gate.
- [ ] Repository Policy: SUCCESS on final tested HEAD.
- [ ] FOODEX V2 Isolation Gate: SUCCESS on final tested HEAD.
- [ ] FOODEX Required CI terminal `required-ci-gate`: SUCCESS on final tested HEAD.
- [ ] Separate file-by-file GitHub `TECHNICAL_REVIEW: PASS` record before integration. Same-account review is not independent GitHub approval.
- [ ] SHA-guarded merge into `Foodex_V2` only; verify integrated commit, close **only** #3868 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` and leave #2503 OPEN.

Never mark pending checks as passed based on a successful file push, PR creation or earlier SHA.
