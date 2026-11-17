# V2 #4007 — Treasury page-tab rail acceptance

## Scope

Reuse the existing canonical horizontal page-tab rail on the existing Treasury workbench. The only product markup change is the opt-in `foodex-tabs--horizontal` class on the authorized native GET links. The existing shared modifier supplies one-row flex layout, contained horizontal scrolling, visible keyboard focus, and the 44px shared target height.

No route, controller, role, permission, entitlement, module readiness, Sidebar, financial form, POST operation, or API changes. The single active link continues to be rendered by the server with `aria-current="page"`; links remain native anchors rather than ARIA application tabs.

## Mobile impact

Customer / Driver / Van: **NO_DIRECT_IMPACT**. This is an existing admin Blade/CSS navigation correction only; no mobile API, Flutter, identity, dispatch, or financial behavior changes. B2B=VAN ONLY and B2C=DRIVER ONLY remain untouched.

## Evidence

- `backend/tests/Feature/V2TreasuryPageTabRailTest.php` checks the canonical rail and CSS modifier on authorized AR/EN responses, one active page link, native-link semantics, the shared 44px target, no GET-side role/finance/audit writes, and a foreign-store direct GET 403.
- Exact final-head Repository Policy, FOODEX V2 Isolation, and FOODEX Required CI are required before merge.
- Browser viewport and screen-reader acceptance remains part of #2750 final UAT; this source/test evidence does not claim that manual acceptance occurred.

## Shared-file sequencing

`backend/resources/views/admin/enterprise-treasury.blade.php` is also changed by original #2632 / PR #3942. The PR has a successful exact-head CI result but remains open/draft with P302 source completeness recorded false. This change stays on the #4007 branch and must be reconciled against that PR before merging; no other branch is modified.
