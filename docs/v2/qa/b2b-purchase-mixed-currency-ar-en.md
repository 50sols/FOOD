# V2 #3785 — B2B purchases mixed-currency 409 AR/EN

Integration branch: `Foodex_V2` ONLY. Parent #2503; Customer B2B reports parity #2733. No main, production release, tag or deployment.

## Contract
- `GET /api/v1/b2b/reports/purchases`, authenticated approved active Wholesale customer under original Sanctum and B2B account guards.
- Existing query restricts `orders.b2b_customer_id`, `channel=b2b`, noncancelled orders and optional requested `store_id` and dates. The SQL-currency distinct count checks selected data BEFORE any total/average/category aggregation.
- Two owned valid distinct currency values still abort HTTP 409. Only the human message is translated through paired `b2b_report_errors.mixed_currency_purchases` keys. Supported logged-in account locale ar/en overrides Accept-Language; otherwise use supported Accept-Language and safe en fallback.
- No change to currencies, amount sums, SQL scopes, cancellations, finance SoD, account authorization, status, JSON shape or valid single-currency response.

## HTTP source-to-test matrix
| Case | Evidence | Expected |
| --- | --- | --- |
| Approved owned B2B orders: KWD + EGP, account ar/header en | `B2bReportingTest::test_mixed_currency_purchases_use_bilingual_409_without_cross_customer_leak_or_writes` | HTTP 409; Arabic message; no order mutations |
| Same KWD + EGP, account en/header ar | same | HTTP 409; English message |
| Unsupported account locale fr with ar-KW then en-US header | same | Negotiated supported language |
| Foreign customer's USD order in same store | same | Not included in owned currency check |
| Owned orders both KWD, foreign USD unchanged | same | HTTP 200, count=2, sum=30, currency KWD |
| Existing positive price/currency/account and unauthorized tests | Existing `B2bReportingTest` | Preserve existing business response, B2B permissions and scoped data |

## Per-surface impact
- Backend/API: affected existing human-readable 409 only.
- Dashboard: not affected, no administrative report route or UI changed.
- Customer Flutter: existing B2B report failure copy localized, no changed field, DTO, endpoint, permissions or Flutter code. No UI placement change.
- Driver Flutter: not affected; no driver order or collection route impact.
- Van Flutter: not affected; B2B-only Van dispatch/business workflow unchanged.

## Review
`REVIEW_RISK: HIGH` due financial mixing and tenant/channel isolation. Engineering COMMENTED file-by-file audit must confirm hard 409 prevents cross-currency sums, scoped positive/no-write HTTP tests and unchanged role permissions. Same account technical review is NOT independent GitHub approval. Require exact final SHA Repository Policy, V2 Isolation, AR/EN, PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel and MySQL/Redis install/upgrade/recovery and terminal Required CI SUCCESS before guarded merge into `Foodex_V2`. Master #2503 remains OPEN for final manual/staging/UAT. No source completeness claim from partial CI.
