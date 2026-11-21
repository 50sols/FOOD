# V2 #3716 — Store reviewer missing-secret readiness validation in AR/EN

## Scope / source
- Existing authorized Management Dashboard action: `POST /admin/settings/mobile/reviewer/{reviewer}/test` (`StoreSubmissionController::testReviewer`).
- Source: `docs/quality/LOCALIZATION_CONTRACT.md`; parent #2503. Integration `Foodex_V2` only.
- Existing #3705 / merged PR #3707 handles a separate reviewer upsert error; this issue targets the readiness test when the stored encrypted secret is missing.

## Before / after
- Before: authenticated Arabic operators received the English literal `Reviewer secret is not configured.` on HTTP 422.
- After: the same `errors.reviewer[0]` 422 field is localized through paired `mobile_settings.reviewer_test_secret_missing` keys in Arabic and English using the existing selected request/actor locale.
- All existing authorization (`mobile_settings.manage`), route model binding, request shape, 422 response structure and failure-before-write behavior remain unchanged. Missing secret is never surfaced in an error.

## Automated acceptance
- `php artisan test --filter=V2ReviewerReadinessSecretLocalizationTest`: real authenticated JSON POST for Arabic and English actor locale, exact field-level 422 wording, unchanged reviewer credentials/readiness/status/timestamps and unchanged audit count, plus unprivileged 403.
- Existing `StoreSubmissionReadinessTest` positive configured-secret readiness test remains the adjacent PASS-path regression.
- PHP/Pint/PHPStan, AR/EN localization parity, Repository Policy, V2 isolation and exact-final-HEAD Required CI (Laravel + MySQL/Redis where applicable) must pass before PR merge.
- Technical risk: medium due to protected credential-testing path; limited to validation copy and regression. File-by-file security audit required before merge; this is not independent GitHub peer review.

## Mobile compatibility matrix
| Surface | Impact |
| --- | --- |
| Dashboard | Existing settings action returns localized 422 error, with unchanged permissions, route and UI placement |
| Customer | No public API/DTO/Flutter change; reviewer account fixture is management-only |
| Driver | No public API/DTO/Flutter change |
| Van | No public API/DTO/Flutter change |

No schema, entitlement, financial, dispatch, app binary, `main`, release or production changes.
