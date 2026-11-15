# V2 #2559 / P229 — Profitability Rules: source-to-runtime authority audit

**Status:** BLOCKED ON CANONICAL RULE / COST AUTHORITY. This is a design and runtime gap report, **not** implementation, sign-off, source acceptance or a release. **Target:** `Foodex_V2` only. Parent #2503. P229 title: `قواعد الربحية` in Catalog & Pricing.

## Preflight and reuse decision

- Existing canonical Catalogue & Pricing entry point: `AdminNavigation` → `admin.commercial.sales-control` → `CommercialDashboardController::salesControl` (GET) and `saveSalesControl` (PUT). Existing product policy covers sales status, unit/break-pack, quotas, availability windows and customer/channel targeting; **no profit-floor or profit-rule mutation** is defined there.
- Existing pricing is authoritative in `CommerceQuoteService`, `CommercialPricingResolver` and `B2bPriceResolver`; `CommercialTruthRegistry` explicitly identifies the pricing write authority. UI-supplied prices are never authoritative.
- Existing `docs/architecture/COMMERCIAL_PROFITABILITY_READ_MODEL.md` defines an **after-event, rebuildable BI projection**, not the source commercial/finance ledger or a price-approval policy. Its cost is provable only from an applicable WMS standard-cost valuation policy at event time. Missing/ambiguous/historically inapplicable cost **must remain NULL**, not zero or inferred. This report must not be converted into an operational pricing/margin floor.
- Existing BI profitability analytics / reports are separate from **P229 profitability policy**; pointing P229 to a chart alone would be a misleading dead workflow. Related V2 P315 (#2645) is analytics, not profitability rule management.
- Placement: **Reuse → Extend** the existing authorized Commercial Sales Control as a contextual tab / form only **after** a real write/read/approval guard is available; no new Sidebar route, independent visibility policy or second valuation ledger. No production navigation change is made by this audit.

## Required source contracts before safe financial code

1. **Rule schema and responsibility:** Specify the supported margin policy (e.g. floor percentage or absolute contribution), actor/role, legal entity, channel, store, product or selling unit, effective interval, currency and inheritance/precedence. P229's navigation title alone does **not** authorize choosing arbitrary thresholds or blanket defaults.
2. **Valuation authority:** For every affected quote/discount/order, establish **current** authorized store+SKU+selling-unit cost provenance from the WMS valuation owner. Define semantics for mixed vendor/FOODEX legal-title stock, unknown legacy stock, cross-warehouse cases and unavailable cost. Dependency: #2844 already documents missing legal stock-owner dimension; never label unowned stock as FOODEX.
3. **SoD and adjustments:** Specify which user may propose, approve, activate, override and audit a rule; how existing discounts/promotions/coupons/price tiers and customer credit interact with the floor; and whether below-floor quotes are rejected or held for approval. Preserve other finance controls; coordinate #2853.
4. **Data integrity:** Version approved rules; use decimal money, currency-safe comparison, timezone-safe effective dates, immutable quote/order snapshots, idempotent mutations and tested rollback/recovery. Preserve historical orders.
5. **Failure semantics:** Missing/ambiguous valuation must produce explicit **UNVALUED / approval-required** state, not a fabricated positive/negative margin. Protect direct HTTP actions with role, company, store, channel, module entitlement/readiness and 403/404 as applicable; no UI-only guard.
6. **Bilingual flow:** Existing Commercial Sales Control is the canonical parent. A contextual Profitability Rules tab must be accessible via the existing authorized Catalog & Pricing navigation, work on 320–1280px, RTL/LTR, AR/EN, keyboard/a11y and all failure states. No raw IDs or JSON for business users.

## Backend / Dashboard / Mobile impact classification

| Surface | Assessment | Required evidence |
| --- | --- | --- |
| Backend | **Affected** once a profitability policy is approved: canonical price/discount guard, audited rule lifecycle and source cost authority. | Laravel positive, denied/direct HTTP, multi-role, cross-store, B2B/B2C, module-off, unvalued cost, currency and SoD tests. |
| Dashboard | **Affected** once rule lifecycle exists: scoped controls on the existing Sales Control page/tab. | Real AR/EN UI, responsive/accessibility, readiness and authorization tests. |
| Customer | **Conditionally affected** if pricing, discount or quote eligibility changes; not safe to assert no impact. | Linked V2 Customer issue, backend/mobile contract and offer/checkout regression before integration. |
| Driver | **Not directly affected by a B2B-only candidate policy**, but B2C scope undecided; do not assume no impact if rules include Retail. | Explicit B2C/Driver scope proof and regression before merge. |
| Van | **Conditionally affected** for B2B quote/order/direct-sale eligibility and approval workflow. | Linked V2 Van issue and offline/replay price authority tests if policy touches B2B. |

## Acceptance and handoff

- This document records **source and safety blockers only**. No rule entity, database migration, quotation change, unauthorized UI action or artificial approval flow has been introduced.
- Before further code: obtain the source-authoritative profitability policy semantics and the compatible valuation/legal-ownership solution. Recheck active PR/file owners and continue **the same** #2559 branch and PR.
- Implement exact owned policy + existing-page form + server enforcement + fully scoped security/failure tests; classify/implement each actual app impact; pass exact-head Repository Policy, V2 Isolation and Required CI (including child jobs). Merge only into `Foodex_V2`; close #2559 only after **real** source requirements, implementation, app parity and CI are complete.
- No `main` integration, release, deployment, tags, production data access or test of a live financial transaction is authorized.


## 2026-11-15 — Source-derived fail-closed P229 acceptance guard (interim; NOT complete)

**Owner source verified:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` lists P229 as "قواعد الربحية" in Catalog & Pricing and requires independent approval before a pricing role may exceed the profitability limit. It **does not** define the numeric minimum margin, cost/ownership attribution, currency, supplier title, effective-date or maker/checker action matrix. The source deliberately describes the target IA, not certification that the functionality exists. Related live V2 #2844 (supplier vs FOODEX ownership) and #2853 (unapproved price/discount profitability or credit bypass) remain OPEN.

**Proven implementation gap:** `CommercialDashboardController::saveSalesControl` validates and writes targeting/quota rules, not profit margins; historically it silently ignored extra top-level `min_margin_pct`-type inputs and nested `rules_json` entries could be accepted while the unrecognized profitability fields were discarded during persistence. This could falsely tell an administrator that a minimum-margin policy had been saved without any enforcement. `CommerceQuoteService` does not have a confirmed legal-title-aware quote-time WMS cost identity. The W7 commercial profitability projection explicitly leaves unproven costs and margins NULL and is a rebuildable BI read model; it is not an approval/quote policy.

**Safe implemented subset (same original branch/PR, no activation):**
- Server-side Sales Control mutation rejects attempted fields containing a distinct `profit`, `profitability`, `margin`, `cost_basis` or `stock_owner` token at the root request level and within any `rules_json` entry **before starting any policy write**. Matched fields return actual Laravel validation errors, rather than a 302 success and silently discarded fake margin configuration. This is not a generic unknown-field ban and leaves real targeting/quotas and catalog workflow intact.
- AR/EN localized validation explains that profitability authoring requires an approved margin policy and verified WMS cost and that nothing was saved.
- `backend/tests/Feature/V2ProfitabilityRuleInputGuardTest.php` uses real authenticated B2B management HTTP, both locales, nested and top-level forged profitability fields, and proves **no** product policy, customer rule or audit change; existing authorized Sales Control save remains a positive regression. This is **not** a full P229 functionality test.
- No new route/Sidebar entry, price alteration, schema migration, approval/bypass flag, fake WMS cost, finance posting or Flutter payload was added. Customer, Driver and Van are **not directly changed by this interim rejection guard**, since unsupported admin payload fields were not exposed as legitimate client API. Real P229 cost/rule/quote activation will require linked app-specific V2 issues and parity tests.

**Remaining hard source acceptance:** Obtain specific owner-approved threshold/precedence/effective-date decisions; implement trusted store/warehouse/product/selling-unit and supplier/FOODEX legal-title cost source (#2844) and P129 finance/discount checker (#2853); build real authorized P229 management workbench under Catalog & Pricing with AR/EN RTL/LTR accessibility; enforce at canonical B2B and B2C quotes/orders/discounts and Van sale/offline replay with deterministic UNVALUED/hold path, concurrency/idempotency and all negative tests. Do **not** mark #2559 complete, merge doc/guard-only PR as complete, or publish a release merely because CI is green. Keep PR #3326 DRAFT and #2559 OPEN until those source requirements are met and exact-final-head CI is green. Parent #2503 manual UAT remains separate.
