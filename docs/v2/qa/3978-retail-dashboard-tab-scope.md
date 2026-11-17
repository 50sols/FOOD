# V2 #3978 — Retail dashboard tab input shape and store-context safety

- Parent acceptance: G009 #2750; P031 #2782; V2 master #2503.
- Source/target: `Foodex_V2`, existing `GET /admin/b2c/dashboard`, controller `B2cWorkspaceController::show`, view `admin/b2c-workspace.blade.php`.
- Placement: **REUSE** existing horizontally scrollable Dashboard five-tab navigation and server-rendered active panel. No new tab, page, sidebar destination, API, database migration, or access grant.
- Distinct functional destinations remain existing `/admin/b2c/{products,inventory,orders,customers,drivers,promotions,content,reports,settings}` routes; catalog tabs owned by #3950/PR #3971. Retail store creation remains a six-step dependent wizard, **not tabs**.
- Ownership: this issue changes only `backend/app/Http/Controllers/Admin/B2cWorkspaceController.php` in its existing `show()` GET path and adds `V2RetailDashboardTabShapeScopeTest.php` plus this QA evidence file. No overlapping active PR files at preflight.

## Reproduced source defect and bounded fix

Existing view computed `(string) request('dashboard_tab','overview')`. A GET parameter `dashboard_tab[]=inventory` or associative `dashboard_tab[name]=sales` is an array, and direct string casting may raise an Array-to-string-conversion warning/500 before rendering. The original controller did not validate tab query shape. The narrow fix rejects non-string shapes with **404** in `show()` **after the existing effective store and module authorization checks** and before the Blade render. Missing/scalar/legacy unknown values retain existing behavior; the existing `assistant` alias still maps to inventory. No backend tab whitelist or alternative permission is invented.

## Source authority and denial matrix

| Scenario | HTTP expectation / safeguard |
| --- | --- |
| Authorized Retail Store A, overview/sales/inventory/customers/analytics | 200, one selected pane, links preserve Store A + selected date range |
| Authorized Retail Store B using same dual-store actor | 200, links preserve Store B; no silent Store A fallback |
| Arabic and English | RTL/LTR page context and translated existing labels |
| `dashboard_tab[]=...` or `dashboard_tab[name]=...` | 404 before Blade casts array; not 500 |
| Unknown scalar tab | Existing default to overview retained |
| Actor with Store A role requests foreign Store B | 404 via existing tenant context |
| Actor requests inactive Retail store | 404 via existing store scope |
| Roleless actor | 403 through existing permission/context guard |
| Inactive account | **403** from existing `management.dashboard` / `EnsureManagementDashboardAccess`; it logs out the inactive browser actor and invalidates the session before denial |
| GET tab navigation | No stores, orders, products, inventory or promotions business-row writes |

**Important:** Source authority, role decisions, store context, permissions, entitlements, and any `support_access` audit/consent belong to existing controller/middleware; the new shape check does not replace or loosen them. No mere Sidebar appearance asserts access. Do not turn an unauthorized 403 into a 200 or alter a legitimate 404; the new input-shape 404 applies only after existing authorization. Existing Store A/B P031/P082 tests remain independently authoritative.

## File-by-file security/quality review

**REVIEW_RISK: HIGH** (HTTP input handling adjacent to tenant isolation); **TECHNICAL_REVIEW: PENDING_EXACT_HEAD_CI**.

1. `B2cWorkspaceController.php`: `request->query` only reads GET parameter; controller checks scalar before view. No change to `workspaceContext`, `authorizeModule`, underlying queries, business formulas or mutations. Reject array before conversion, leave valid/default untouched. Threats: forged Store B, unauthenticated/roleless/inactive, array query warnings; deny via existing guards.
2. `V2RetailDashboardTabShapeScopeTest.php`: `RefreshDatabase` and reference seeds; real authenticated HTTP, two scoped stores, all five tabs, AR/EN, date retention, malformed/unknown inputs, roleless/inactive and foreign-store negative paths, unchanged business counts. No mock authorization.
3. This QA record: no runtime behavior. Actual CI status and final technical audit must be checked against final PR head; this document is not a claim of execution or all-green.

Mobile impact: **Customer / Driver / Van: NO_DIRECT_IMPACT individually**. Customer API/Flutter unchanged; Driver API/Flutter and B2C-only dispatch unchanged; Van API/Flutter and B2B-only Van dispatch unchanged. No API contract or mobile source paths in the diff; do not assert Flutter tests passed on this basis.

## Acceptance / deferred evidence

- Verify full current-head Repository Policy, FOODEX V2 Isolation Gate, Required CI terminal gate, applicable backend/Pint/static analysis/security and AR/EN. Failed/pending/skipped-required checks never count as success.
- Validate real browser viewports 320/360/390/412/430, 768/1024/1280, keyboard focus, scroll, tab state and Arabic/English under final G009 #2750 acceptance; source/HTTP tests alone do not prove screenshots or manual UAT.
- Merge only original PR #3979 to `Foodex_V2` once source complete, technical audit PASS, lease/file ownership revalidated and exact-head required checks green. Close #3978 only with merged SHA and CI proof. Keep #2750 and #2503 OPEN until full program acceptance. No `main`, production, release, tag, or cPanel actions.

### Exact-head CI rescue (2026-11-17)
- Required CI run `43985553502` on `f0ae4305eff57ce2aa9cdde805617502e320551a` completed **FAILURE** because `V2RetailDashboardTabShapeScopeTest` asserted 401 for inactive browser user, while the actual admin route intentionally returns 403; all 4,220 other tests passed and one was skipped. Source: `EnsureManagementDashboardAccess::handle()` logs out, invalidates session, then `abort(403)`; `EnsureActiveUser`'s 401 contract is **not** attached to this admin route. Existing route, role, module, tenant and session enforcement must not be altered to satisfy a mistaken expectation.
- Scoped correction: assert 403 in the dedicated test, align this QA matrix with the actual administrative middleware, and keep the controller/query guard unchanged. New exact-head CI and all required gates must pass before any technical PASS or integration decision; no historic green may be reused.
