# V2 #3855 — Wholesale checkout options missing store AR/EN

## Existing route / placement
- Source: Customer Wholesale checkout options `GET /api/v1/b2b/checkout/options`, controller `StorefrontController::b2bCheckoutOptions`. Existing authorized app-preview B2B route calls the same method.
- Reuse only the existing nonpositive `store_id` guard; no new route, navigation, screen, endpoint, DTO or permission. The existing HTTP422 and `errors.store_id.0` JSON field are unchanged.
- Paired translation key `b2b_checkout_options_errors.store_required`: saved authenticated actor locale `ar`/`en` first; otherwise regional `Accept-Language` supported negotiation and safe Arabic default. Original English text retained exactly.

## Security / negative cases
- Authenticate an active User via Sanctum. Missing and zero `store_id` requests return localized `HTTP 422 errors.store_id.0` under saved AR and EN vs contradictory regional header; unsupported saved locale uses `ar-KW` or `en-US` safely.
- Anonymous direct HTTP request remains 401. No new permission or side channel, no exposure of Wholesale store IDs, tenant or account details.
- No carts, orders, invoices, payments, stock movements, audit logs or store settings may be written during the rejection. Real seeded HTTP regression records before/after counts for each of these.
- Other B2B customer/account/store ownership and entitlement checks remain where they were after validation. Valid options and minimum-order business rules are not changed; existing `WholesaleMinimumOrderTest` preserved.

## App impact
| Surface | Impact | Rationale |
| --- | --- | --- |
| Backend/API | Existing error text only | Same 422/field and B2B checkout options route |
| Dashboard | None | No admin action, finance, Sidebar or permissions changed |
| Customer | Existing message localized | Wholesale app uses the same API, no Flutter, navigation, DTO or request changes |
| Driver | None | B2C DRIVER ONLY fulfillment untouched |
| Van | None | B2B VAN ONLY fulfillment untouched |

## Review / rollback
**REVIEW_RISK HIGH** due to authenticated Wholesale customer identity and tenant-adjacent API, even though the actual patch only translates an existing pre-query validation error.

Five-file review fence: existing `StorefrontController.php` (one 422 human text + bounded locale), NEW paired AR/EN language files, NEW seeded HTTP Feature test, this QA document. Rollback: revert only message and locale lookup, remove paired keys/test/doc; no migrations, state writes or data restoration necessary.

Same-account `COMMENTED` technical audit is not independent GitHub `APPROVED`. Exact-final-head Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/security/AR-EN, MySQL/Redis install/upgrade/recovery and terminal Required CI must all SUCCESS before SHA-guarded squash merge to `Foodex_V2` ONLY; leaf closes CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED with evidence, parent #2503 remains OPEN for final UAT. Never main, release, tags or production.
