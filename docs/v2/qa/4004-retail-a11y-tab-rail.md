# V2 G009 / #4004 — Retail Dashboard native horizontal GET tabs

## Scope and source authority

- **Canonical screen:** `GET /admin/b2c/dashboard`, existing `admin.b2c.dashboard` route/tenant/store-authorized controller.
- **Existing Blade:** `backend/resources/views/admin/b2c-workspace.blade.php`; no new route, Sidebar, controller, theme, JS or Flutter.
- **Prior separate fix:** #3978 / merged #3979 normalizes malformed array-shaped `dashboard_tab` as 404 and authorizes store-scope; do not replicate/undo it.
- **Navigation owner:** existing Retail Dashboard contextual PAGE_TAB navigation, five siblings: Overview / Sales / Inventory / Customers / Analytics; Assistant drawer stays a separate button and is not a tab.
- **Actor / context:** authorized Retail store actor with effective B2C role, selected store and GET dates/filter context; role/tenant scope unchanged.
- **Placement:** REUSE existing link-based `dashboard-tabs` (no new Material tabs, client tab role or component); preserve `data-dashboard-pane` server-selected content.

## Defect and bounded remediation

At `Foodex_V2@390e1ac7e19de6f63c7a256db322c8bf72557df3` the selected link was only indicated by the `.active` class; it had no `aria-current="page"`. The default tab hit-area min-height was 40px at >=768px. The implementation now:
- Adds `aria-current="page"` to the **one active** GET link chosen by the already-authoritative tab/store context. Other links omit the attribute, and all links remain real `href` navigation.
- Keeps the rail **one horizontal row** at every responsive breakpoint: explicit `display:flex;flex-wrap:nowrap`, `overflow-x:auto`, `max-width:100%;min-width:0`, `overscroll-behavior-inline:contain`, `scrollbar-width:thin`, non-shrinking labels. The existing assistant launcher is separate.
- Makes every tab link at least 44px high (including 768/1024/1440), preserves green active pill and includes a 3px `:focus-visible` ring with `scroll-margin-inline`, using existing colors.
- Preserves `<html lang="ar" dir="rtl">` / `lang="en" dir="ltr"` from the template; the scrollbar inherits inline direction and no physical left/right order was forced.
- **Correct keyboard pattern:** these are page-navigation links in `<nav>`, not same-document WAI-ARIA `role=tab`. Native Tab advances focus; Enter activates `href`; Space/arrow keys use browser scrolling, not custom arrow-to-switch; do not set `aria-selected` or roving tabindex on this separate-page navigation.
- No stored state, field, POST form, API, permission, controller or data mutation changed. Existing 17 forms and 14 POST forms remained unchanged in the source diff.

## Test and negative authority

The existing `V2RetailDashboardTabShapeScopeTest` is extended with a dedicated positive AR/EN × all five-tabs test that asserts:
- Valid store-bound GET 200, RTL/LTR, selected `data-dashboard-pane`, **exactly 5 anchors and exactly 1 active current link** in the Retail tab rail, no `role="tab"` or `aria-selected`;
- CSS nowrap/overflow containment, min-height 44px, visible focus ring, preserving real `href`, exact selected route;
- No change to stores/orders/products/inventory/promotions on GET;
- Existing malformed array tabs GET 404, foreign/inactive store 404 and inactive/roleless actor 403, no security bypass.

**Negative scope:** Customer app NO_DIRECT_IMPACT; Driver app NO_DIRECT_IMPACT; Van app NO_DIRECT_IMPACT (Dashboard Blade and Laravel test/doc only), Online-first and B2B VAN ONLY/B2C DRIVER ONLY not touched. `system.update`, installer, backups and billing/dispatch unrelated.

## Viewport and input acceptance grid

| Browser width | Expected source contract | Manual status |
| --- | --- | --- |
| 320px | horizontal native scroll, 44px links, focus ring inside rail, assistant still operable | NOT_RUN (requires browser/device) |
| 375px | same single row with keyboard access, no clipped controls | NOT_RUN |
| 768px | same single row (desktop base 44px), no return to flex wrapping | NOT_RUN |
| 1024px | same one row / bounded overflow, accessible RTL/LTR | NOT_RUN |
| 1440px | all five links stay adjacent in one rail, active green/current semantics | NOT_RUN |

Source CSS and real Laravel HTML are **automated contract evidence**, not evidence of computed layout at those pixel widths or native screenreader output. These UX/device checks remain under #2750 and final #2503, `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`.

## Review/release

`REVIEW_RISK: HIGH` conservative user-role-adjacent navigation semantics (not a new privilege). Record `TECHNICAL_REVIEW: PASS` only after exact final SHA Repository Policy + V2 Isolation + full Required CI (Laravel/AR-EN/MySQL) all SUCCESS; inspect changed file fence and GitHub mergeability. Single original PR targets `Foodex_V2` only. No main/tags/releases/production.
