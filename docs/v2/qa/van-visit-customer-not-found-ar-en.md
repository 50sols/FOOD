# V2 #3816 — Van visit missing-authoritative-customer AR/EN (isolated)

- **Scope / source:** #3816 leaf under #2738 / #2503, source P110/T115R04. Integration ONLY `Foodex_V2`. No main/release/tag/APK/cPanel/production.
- **Endpoint:** authenticated `POST /api/v1/van/visits`, Sanctum `app:van` via `EnsureVanRuntimeAccess`, `active.user`; mandatory B2B customer identity in authorized Van visit journey.
- **Risk:** HIGH — Van visit authorization/identity API error path. This patch changes only the human `errors.customer_id.0` translation selected on an already-denied request. The HTTP 422 status, field key, request shape, validation predicates, non-existence lookup, actor/Van assignment, store/channel guards, transaction/order/finance logic, idempotency and successful response contract remain unchanged.
- **Locales:** supported saved actor `locale` = `ar`/`en` takes precedence. For unsupported saved locale, Symfony supported Accept-Language negotiation picks `ar` or `en`; default `ar`. This mirrors FOODEX invoice error locale selection. The users table defines nonnullable `locale`, so the regression uses an unsupported persisted locale to exercise header fallback rather than storing SQL NULL.

## Exact five-path technical audit
1. `backend/app/Http/Controllers/Api/V1/VanVisitController.php`: one added supported-locale resolver before `assertCustomerExists`; translation injected **only** into the existing 422 customer-not-found branch. No other scope, return value, route, or persistence modification.
2. `backend/lang/ar/van_visit_customer_errors.php`: paired truthful Arabic wording; no English-only fallback in Arabic mode.
3. `backend/lang/en/van_visit_customer_errors.php`: preserves the existing literal **verbatim** for compatibility; same flattened key.
4. `backend/tests/Feature/VanVisitControllerTest.php`: real authenticated seeded `app:van` HTTP tests: saved AR with contrary EN header; saved EN with contrary AR header; unsupported saved locale with AR/EN negotiated fallback; HTTP 422 field path and no writes to visits, orders, invoices, payments, stock movements, audits. Positive B2B visit create must still return 201.
5. `docs/v2/qa/van-visit-customer-not-found-ar-en.md`: source, behavioral boundaries, security review and test/CI evidence ledger.

## Mobile & UI impact
- **Van — affected text only:** receives the same existing HTTP 422 JSON field key and shape with locale-specific message. Existing Flutter error parser and DTO/routes unchanged; no new screen or navigation; operator authentication and Van-only B2B scope unchanged.
- **Customer — not affected:** no Customer API, Flutter, navigation or routing change. B2C continues DRIVER ONLY.
- **Driver — not affected:** no Driver API, Flutter, navigation or dispatch change.
- **Dashboard — not affected:** no views, menus, permissions or admin routes modified.
- **Backend — affected:** one localized denial message; no data, auth or storage changes. No OpenAPI schema/HTTP-shape changes.

## Verification requirements
Focused test: `cd backend && php artisan test --filter=VanVisitControllerTest`.
Exact-head gates: Repository Policy, V2 Isolation, AR/EN localization, PHP syntax/Pint/PHPStan/security, Laravel tests, MySQL/Redis install/upgrade/recovery and terminal Required CI. **Do not merge unless every required latest-head check is SUCCESS.** The same-account file-by-file technical review is an engineering audit, *not* an independent GitHub approval. Final device/staging UAT remains #2738 / #2503.
