# V2 P185 — Customer, Sales & Customer Service business domain

Issue: [#2515](https://github.com/walidatiyaai2025-gif/FOOD/issues/2515); parent #2503; source Unified RBAC/ERP/Sidebar discussion, Part 2 §4 (matrix key P185, actual DOCX P174). **Foodex_V2 only**.

## Source placement, authoritative contract and implementation

The target parent `العملاء والمبيعات وخدمة العملاء` owns the source sections P186 Customers, P192 Sales and P198 Customer Service & WhatsApp. Those **three existing functional subgroups** retain their canonical keys `accounts`, `sales`, `customer_service`, server-authorized routes, saved administrator order and the existing leaf ownership rules. The parent is a **non-link, AR/EN accessible business-domain heading** rendered in `admin._sidebar` around *only already-authorized, already-visible adjacent subgroups*. It never creates a route or exposes a hidden/unready child.

- Reuse → Extend → Compose → New Screen: **Compose existing groups**. No duplicate Sidebar key/route, controller, backend authorization policy, new menu module, new screen or persistence.
- Arabic: `العملاء والمبيعات وخدمة العملاء`; English: `Customers, sales and customer service`. Paired `backend/lang/{ar,en}/v2_sidebar_domains.php` keys. The existing Dashboard layout supplies AR/RTL + EN/LTR direction.
- P185 parent: `<section role="group" aria-labelledby="p185-domain-title-N" data-nav-domain="customer-sales-service">`. Visible `h2` is *not* an anchor/button or permission decision. Existing native `<details><summary>` wrappers retain keyboard and accessibility semantics for subgroups.
- `accounts` contains canonical Customer 360 and authenticated Retail/Wholesale customer and group destinations; `sales` contains authorized ready Sales Quotations and commercial-exception approval destinations; `customer_service` exposes WhatsApp Inbox **only when the real module capability and HTTP authorization allow it**. Future P195 contracts/pricing and other unready business leaves are excluded until their separately owned implementation is ready.
- The same `navGroups` list from `AdminNavigation::groupsFor()` and `AdminSidebarManager::apply()` drives the entire heading and children. There is no independent cosmetic RBAC, forced entitlement, disabled-module bypass or hidden direct-URL access.
- The heading opens/closes only around contiguous visible domain subgroups. If an administrator reorders groups, separate accessible heading segments preserve saved ordering instead of reordering them or duplicating links. When none of the three subgroups is authorized, **no P185 heading renders**. CSS `:has` already used for P237 hides empty domain headings during client-side search filtering.
- Existing group → page → optional child-page **three interactive levels remain unchanged**. The domain `h2` adds a noninteractive organizational label only, so no fourth navigation link depth is created.

## Explicit route and security inventory

| Group | Canonical destination | Server-side authority / scope |
|---|---|---|
| Customers | `admin.customer-360.index` | `customers.view` or `b2b.accounts.view` as determined by effective controller/tenant scope |
| Customers | `admin.b2b.customer-groups.index` | `customers.view` + authorized Wholesale B2B store |
| Customers | `admin.b2c.customer-groups.index`, `admin.b2c.module?module=customers` | `customers.view`, Retail Store A only; Store B and Wholesale cross-channel denied |
| Sales | `admin.sales-quotations.index` | `orders.view` and existing B2B/B2C + store scope and quotation readiness |
| Sales | `admin.operations.orders.index` (when projected; commercial exceptions are a permission-gated Orders workbench view) | existing effective order/approval entitlement; no visibility grant |
| Customer Service | `admin.whatsapp-inbox.index` | `whatsapp.inbox.view`, operational Inbox capability, permitted active store and existing customer-service role/scope |

The group and each child are derived **after** the real authorization and operational readiness checks; direct HTTP controllers still deny foreign store / disabled module / unauthenticated or unprivileged actors. A hidden heading or presentation override never affects direct-URL authority.

## Automated acceptance evidence and security negatives

The resumed *original* issue branch and PR #3262 preserve four prior real Laravel HTTP negative scenarios and add three source-specific HTTP/DOM assertions in `backend/tests/Feature/V2CustomerSalesSidebarGroupAuthorityTest.php`:

1. Wholesale Customer Service Manager in both Arabic and English: real B2B groups HTTP 200, route/channel admission, correct `dir`, one localized parent heading and existing Accounts/Sales subgroup semantics; both customer group and sales quotation links appear once, with no duplicate.
2. Retail Store Admin: own-store B2C groups allowed; foreign Store B and wholesale direct-route denied (403/404), no B2B customer group link.
3. Explicit `customers.view` DENY overrides legacy `admin_sidebar_role_visibility` force-show, without revoking the role's underlying grants.
4. Inactive and roleless actors receive no unauthorized Customers/Order links; no empty subgroups.
5. The P185 outer domain is a single accessible heading containing only already-authorized groups, preserving the current Customers and Sales pages under both locales.
6. Retail Store A P185 domain renders one scoped Retail customer group and Sales link, never any foreign Wholesale group; foreign store request is rejected.
7. WhatsApp capability disabled → no service subgroup; `INTERNAL_TEST` enabled → existing WhatsApp Inbox subgroup within the *same* parent heading; disabled again → real HTTP 404. No new API/role/messaging rule.

Existing P186 (#2516), P192 (#2522), P198 (#2528) and source P381 (#2901) tests remain independently authoritative. Original P185 issue-scoped tests are preserved and extended instead of replaced. Source review must consider those tests and the existing responsive/sidebar-search CSS; exact-final-head PHP syntax, Pint, PHPStan, full Laravel, MySQL/Redis, localization, Repository Policy and V2 Isolation/terminal Required CI are a hard merge/closure gate. **Do not claim success without final run links.**

## Per-app and dashboard impact

- **Dashboard:** business-domain heading and two new AR/EN keys only. Responsive CSS and mobile-web off-canvas reuse the existing `.foodex-nav-domain` treatment. Existing keyboard/summary disclosure and local search semantics retained; manual browser/device/screen-reader UAT belongs to #2750/#2503.
- **Backend/Admin:** no HTTP route, controller, DB migration, permission code, role, tenant/entitlement calculation, module readiness or user status changes. Existing HTTP negative tests validate these contracts.
- **Customer Flutter:** not affected. No Customer API, DTO, app source, authentication/notification/deep-link or buying/checkout behavior modified.
- **Driver Flutter:** not affected. No Driver API, GPS/collection/dispatch/status or B2C-only routing change.
- **Van Flutter:** not affected. No Van API, B2B assignment, visits, territory, offline replay or fulfillment change.
- B2B orders remain **Van ONLY**, B2C orders remain **Driver ONLY**.

## Technical review, rollback and remaining V2-wide work

**REVIEW_RISK: LOW (presentation + localization and automated tests, with no authorization/tenant/data/contract modifications)**. File-by-file review: `admin._sidebar` creates only a derived domain heading with safe unique HTML ids and no anchor; bilingual keys are paired; existing tests remain in place; P185 tests include actual authorized/denied routes. Failure modes: no domain when no groups authorized, no additional URL, no item duplication, no access escalation, saved sort order maintained, existing P237 domain remains unchanged. Rollback reverts only this markup and paired translations; no schema/data changes.

**P185 grouping implementation** is separate from remaining unfinished **leaf business capabilities**, notably #2525 contracts/prices, and from the final owner/staging 177-node UX/a11y acceptance tracked in #2503/#2750. Completion of P185 heading must not mark any incomplete child business capability or P381 whole-tree final acceptance complete. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`; owner has explicitly requested finish/closure subject to this policy, but that is not a claim that live staging UAT occurred.

Original PR #3262 and branch `test/2515-v2-customer-sales-sidebar-authority` were reopened/restored from the preserved historical commit in response to the owner's 2026-11-15 directive. They remain the **only** PR/branch for #2515; reconciliation uses latest Foodex_V2 as first parent and the preserved original history as second parent. Never use `main`, tags, releases, APK/ZIP promotion or production deployment.
