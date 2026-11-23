# V2 #4377 — Supplier code structured-input validation

## Ownership and intent
- Parent #2818 (P082 action authorization) / ERP #2707 / master #2503.
- Branch `fix/4377-v2-supplier-code-scalar-guard`, PR target `Foodex_V2` only.
- Changed source: `backend/app/Enterprise/Procurement/SupplierDirectory.php`.
- Regression: `backend/tests/Feature/EnterpriseSupplierFoundationTest.php`.
- Existing supplier literal prefix-search #3605 / merged PR #3607 is unaffected.

## Security boundary
`SupplierDirectory::validatedSupplier` previously cast any supplied `code` to string before validation; a nested array was coerced into the syntactically valid supplier code `ARRAY`, bypassing input type checks. Canonical uppercase/trim transformation now runs **only** for strings. Laravel's existing `string` rule rejects arrays, objects and numeric values. Authorizer/scope resolution still occurs before validation, and no schema, API, UI, permissions, auditing or mobile code is changed.

## Regression acceptance
`EnterpriseSupplierFoundationTest::test_structured_supplier_codes_are_rejected_without_supplier_or_audit_mutation`:
- valid lowercase string supplier code remains canonical uppercase on create;
- sequential and associative array codes, plus numeric codes, are rejected on both create and update;
- validation exceptions identify `code`;
- supplier record, supplier count and audit count remain unchanged after denial.

## Cross-client impact
Customer: no direct impact. Driver: no direct impact. Van: no direct impact. This is an existing Admin ERP supplier service input-validation fix only; no Flutter or public API payload contract changes.

## CI / merge acceptance
Required: exact HEAD Repository Policy, FOODEX V2 Isolation, FOODEX Required CI including Laravel/Pint/PHPStan/MySQL/Redis. Protected merge only after complete successful checks and any required independent review. No main/release/production promotion. Do not close until verified merged into `Foodex_V2`.
