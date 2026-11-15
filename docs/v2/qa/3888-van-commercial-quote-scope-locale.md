# V2 #3888 — Van commercial quote AR/EN scoped validation

## Source and placement
- Source: #2739 MOB-V03, #2503 V2, FOODEX localization contract; endpoint \`POST /api/v1/van/customers/{type}/{customer}/commercial/quote\`.
- Reuse existing authenticated Van commercial quote flow. No new navigation, route, response field, API method, permission or Dashboard screen.
- Original source defect: two \`ValidationException::withMessages\` user-facing errors were English literals, independent of the selected actor locale.

## Exact scope
- Only two HTTP422 validation messages are translated with paired keys in \`backend/lang/ar|en/van_commercial_quote_errors.php\`: ambiguous assigned visit stores without \`store_id\`, and absent canonical commercial-customer mapping.
- The English messages are byte-for-byte semantically unchanged; \`errors.store_id\` and \`errors.customer_id\` keys and statuses remain stable.
- Actor's persisted \`locale\` in \`ar|en\` is authoritative (safe \`ar\` fallback); \`Accept-Language\` cannot override the signed-in Van operator's selection.
- Existing B2B-only Van actor, visit/store association, foreign-store HTTP404, pricing, override approval, audit, discount and order/collection behavior unchanged.
- Customer: not affected — no Customer endpoint/Flutter changes.
- Driver: not affected — no Driver endpoint/Flutter changes; B2C=DRIVER ONLY unchanged.
- Van: affected only by localized displayed server validation error; existing #2739 owns broader Van app acceptance; unchanged client request/DTO/response keys and B2B=VAN ONLY.

## Automated acceptance and negative paths
\`V2VanCommercialQuoteScopeLocaleTest\` uses real authenticated Sanctum Van operator, seeded role and valid Van assignment, two B2B assigned visit stores and one foreign store. It checks in both actor locales with deliberately contradictory \`Accept-Language\`:
1. Omitted store for multi-store customer -> localized \`errors.store_id\` HTTP422.
2. Explicit authorized store but missing canonical customer mapping -> localized \`errors.customer_id\` HTTP422.
3. Single assigned store without \`store_id\` -> same localized unmapped-customer HTTP422 (no automatic pricing).
4. Foreign B2B store identifier -> HTTP404 with no store leak.
5. No orders, payments, audit logs, stock movements or visit records created by rejected quote requests.

## Delivery gates
- Risk classification: HIGH — auth-scoped Van endpoint error contract, reviewed with no permission or financial code changes.
- Run targeted Laravel \`php artisan test --filter=V2VanCommercialQuoteScopeLocaleTest\`, PHP syntax/Pint/PHPStan, AR/EN quality gate and exact-head Policy / V2 Isolation / Required CI including real MySQL/Redis deployment acceptance.
- A same-account technical review is an engineering audit, **not** independent peer approval.
- Close this atomic code leaf only after all tests and exact-head checks green, reviewed PR merged **only into Foodex_V2**, verified integration SHA; manual Van device/staging acceptance stays under #2739/#2503.
- Never merge into \`main\`, tag, release, publish APK or deploy.
