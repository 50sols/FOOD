# V2 #3833 — Customer address unsupported commerce domain AR/EN

## Source and scope
- **Issue:** #3833 under Customer #2731 and master #2503. **Integration Foodex_V2 ONLY**, no main, production, deployment, releases, APKs, or tags.
- **Existing route(s):** authenticated Customer /api/v1/profile/addresses on GET/POST and other address operations. The existing X-FOODEX-Customer-Domain guard already rejects unsupported values with HTTP400 JSON `message`. Valid B2B and B2C accepted as before.
- **Risk:** HIGH — Customer address/identity API. Translation-only change on a rejected header; no change to auth, persistence, store ownership, channel restrictions, validation predicate, JSON field shape, status or normal address and checkout behavior. B2B=VAN ONLY; B2C=DRIVER ONLY remain unchanged.

## Exact five-file technical audit
1. `backend/app/Http/Controllers/Api/V1/CustomerProfileController.php`: only existing `addressContext()` unsupported-channel abort text changed to a named localized message. The supported saved Customer `locale` (ar/en) wins over contrary Accept-Language; otherwise supported Accept-Language ar/en and safe default ar. No change to `CustomerAddressService`, `CustomerDomainResolver`, channel validation, address CRUD, or tenant ownership.
2. `backend/lang/ar/customer_address_domain_errors.php`: genuinely Arabic wording for a rejected channel.
3. `backend/lang/en/customer_address_domain_errors.php`: exact original English preserved. Same key as Arabic.
4. `backend/tests/Feature/PlatformCustomerAddressTest.php`: true authenticated platform Customer `GET/POST /profile/addresses` invalid `X-FOODEX-Customer-Domain`, persisted AR/EN with contrary Accept-Language and unsupported persisted locale fallback to AR/EN. HTTP400, precise JSON `message`, zero writes in addresses/audits/financial records/stock, and positive GET for valid b2c. Existing B2B/B2C and other-store negative paths retained.
5. `docs/v2/qa/customer-address-invalid-domain-ar-en.md`: scope, review, impact matrix, and acceptance.

## App, Dashboard and negative impact matrix
- **Customer: affected existing error text only.** The same HTTP400 `message` response is now bilingual; no Flutter source, error parser, DTO, request fields, login/token or navigation changes. No new visual screen.
- **Driver: no impact.** No Driver Flutter/API/dispatch or financial change.
- **Van: no impact.** No Van Flutter/API/assignment change.
- **Dashboard: no impact.** No route, screen, permission, master data or menu modification.
- **Backend: localized existing authenticated address error only.** No changes to transaction, tenant/channel identity authority, API shape, schema or OpenAPI schema.

## Verification and gate
Focused regression: `cd backend && php artisan test --filter=PlatformCustomerAddressTest`. Applicable PHP syntax, Pint, PHPStan, Composer security, OpenAPI, AR/EN, full Laravel and real MySQL/Redis installation/upgrade/recovery must all be green on **exact final HEAD**, together with Repository Policy, V2 Isolation and terminal Required CI. Same-account technical audit COMMENTED is not independent approval. NO merge or code-complete closure before exact-head terminal green. Final staging/manual business acceptance remains under parent #2731/#2503.
