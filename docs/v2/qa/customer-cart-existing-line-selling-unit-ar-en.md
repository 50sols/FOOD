# V2 #3840 — Bilingual existing-line selling-unit rejection

## Source / placement
- Source: FOODEX Localization Contract; V2 master #2503; existing Customer Cart B2C add-item route `POST /api/v1/cart/items`.
- Reuse the existing `GuestCartController::addItem` decision and `localizedCartMessage` helper. This is **not** the distinct guest-to-customer merge conflict resolved in #3782.
- Preserve the original HTTP 422, `errors.selling_unit_code.0` envelope, product identity, cart ownership, B2C store scoping, pricing, inventory, allowed conversion, and idempotent absence of side effects on rejection.
- New paired language key `smart_ordering_cart.existing_line_selling_unit_conflict`: supported actor saved `ar/en` first, otherwise `Accept-Language` including regional variants; exact English original preserved.

## Threat and negative-case evidence
- Seed a real B2C product, store, stock and active BAG selling unit; create one authorized guest cart line.
- Attempt to add the same product with a different/base selling unit using the **same opaque guest token** under `ar-KW` then `en-US`. Both must reject with HTTP 422 and exact language-specific field message.
- Original cart item row, cart and item counts, orders, stock movements, audit logs and live inventory remain unchanged after each rejection.
- Existing positive authoritative BAG conversion / price flow and B2B Customer tenant/Wholesale restrictions are preserved. No route, permission, DTO, request shape, DB schema or write-path change.
- A bearer token, guest-cart token or store is never logged or returned in a new error; response reveals no additional tenant/business data.
- Rollback: revert the one existing error expression, paired catalog keys and new test; no migration or data change.

## Surface impact matrix
| Surface | Impact | Reason / regression |
| --- | --- | --- |
| Backend/API | Yes, error text only | Same HTTP 422 and `errors.selling_unit_code` field; real Laravel guest HTTP negative test |
| Dashboard | None | No admin route, Sidebar, permission, view or admin DTO touched |
| Customer | Yes, existing error text only | Raw API localized message, no Flutter source/JSON schema/route change; existing B2C customer cart behavior preserved |
| Driver | None | No B2C dispatch, Driver auth, API or Flutter change |
| Van | None | No B2B Van routing, account, API or Flutter change |

Owner-locked B2B = VAN ONLY; B2C = DRIVER ONLY. Cross-tenant and application contracts unchanged.

## Required acceptance and review
**REVIEW_RISK: HIGH**: commerce-state and cart-token adjacent. File-by-file technical review:
1. Controller: one already-fail-closed validation message changes to the existing locale resolver only; no control flow, scope or mutation adjustment.
2. AR/EN catalogs: identical one-key addition, authentic Arabic, original English preserved.
3. `CartDomainTest`: seeded B2C guest HTTP rejects with both regional Accept-Language variants; checks status, field, no count or inventory changes; existing tests retained.
4. This document: distinguishes same-cart operation from merge flow and records separate Customer/Driver/Van impact.

A same-account GitHub COMMENTED engineering audit is **not** independent peer approval. Only integrate into `Foodex_V2` after **exact-final-HEAD** Repository Policy, V2 Isolation, localization AR/EN, Laravel/Pint/PHPStan/security, MySQL/Redis install/upgrade/recovery and terminal Required CI all **SUCCESS**. Human/device/staging final acceptance remains tracked by #2503. No main/release/production.
