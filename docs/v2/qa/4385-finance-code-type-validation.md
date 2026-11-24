# V2 #4385 — Finance master code type validation

## Authority / scope
- Atomic P082 #2818, Accounting stage #2709, master #2503.
- Original branch `fix/4385-v2-finance-master-code-type-validation`; target `Foodex_V2` only.
- Exactly `backend/app/Enterprise/Finance/FinanceFoundationService.php`, `backend/tests/Feature/EnterpriseFinanceFoundationTest.php` and this evidence.
- Historical finance search #3666/#3668 already merged; no overlap with that literal LIKE patch.

## Defect and fix
`validatedAccount`, `validatedCostCenter` and `validatedDateRange` converted any provided code to a string before applying Laravel `required|string|regex`. In PHP, a nested array can become the syntactically valid code `ARRAY`. Normalize trim/uppercase **only when the original code is a string**, so native validation rejects invalid types. Both `createFiscalYear` and `createFiscalPeriod` use `validatedDateRange`. Authorization, finance module entitlement, scope, parent checks, date checks, audit and uniqueness remain unchanged.

## Regression contract
Focused `EnterpriseFinanceFoundationTest::test_finance_master_codes_reject_structured_values_without_creating_records_or_audits`:
- create legitimate lower-case/whitespace codes for account, cost center, fiscal year, fiscal period; assert canonical uppercase strings;
- reject sequential arrays, associative arrays, and numeric code inputs for all four creation operations with `ValidationException` on `code`;
- assert no records or audit entries added for any invalid attempt;
- existing server-scope, entitled access and authorization tests remain applicable.

## Mobile impact — Customer / Driver / Van
Customer: NO_DIRECT_IMPACT; no customer app/API contract change. Driver: NO_DIRECT_IMPACT; no dispatch/driver app change. Van: NO_DIRECT_IMPACT; no VAN fulfillment/app change. Backend ERP financial master creation only.

## CI / release
Must pass exact final HEAD Repository Policy, FOODEX V2 Isolation and Required CI (Laravel, security, localization, MySQL/Redis) before protected merge. No main, production, tags, or ZIP release. Close only atomic #4385 once merge and integrated source are verified.
