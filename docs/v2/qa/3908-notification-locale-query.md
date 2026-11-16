# #3908 — Authenticated notification read locale: scalar AR/EN contract

**Scope:** Atomic V2 notification read boundary under #2733 and #2503. **Target:** `Foodex_V2` only. **Risk:** HIGH (authenticated user-facing notification audience/IDOR read). No main merge, tag, release, distribution, cPanel or production action.

## Existing behavior and fault

`GET /api/v1/notifications` and `GET /api/v1/notifications/{notification}` already use the canonical `NotificationAudience::apply` to filter by recipient/channel/app and `NotificationController::payload` for localized output. The previous `NotificationController::locale` converted `Request::query('locale')` directly to string. An explicitly submitted `locale[]=en` or `locale[bad]=ar` is an array, not a locale scalar; PHP's array-to-string conversion can trigger Laravel error handling rather than a client-validation result. Unsupported explicit locales silently fell back to Arabic even though OpenAPI already specifies the optional scalar enum `ar|en`.

## Change and file-by-file review plan

| File | New behavior | Safety invariants |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/NotificationController.php` | No unsafe input cast. Absent locale defaults to supported saved user locale or Arabic; explicitly malformed type or unsupported value throws Laravel field-scoped 422 with localized message. | `show` checks `NotificationAudience` visibility **before** validating locale, preserving foreign-recipient 404. List audience, sort/page and read mutation unchanged. |
| `backend/tests/Feature/V2NotificationMalformedLocaleQueryTest.php` | Seeded actual Sanctum HTTP GET list/detail tests for Arabic, English, default and malformed query arrays/strings; foreign ID 401/404, zero notification/read-row writes. | No synthetic successful authorization, no bypassed route middleware or globally mocked audience. |
| `backend/lang/ar/notification_api_errors.php`, `backend/lang/en/notification_api_errors.php` | Exact paired localized text for `errors.locale`. | AR/EN catalog parity, no sensitive input reflection, no raw internal error details. |
| `docs/api/openapi.yaml` | Both notification GET endpoints document 422 and scalar enum behavior. | Existing 200/401/404, path, schema, read endpoint unaffected. |
| This QA document | Contract and evidence register. | No runtime authority. |

## Negative / positive acceptance

- Authorized Customer GET list/detail: default saved locale and explicit `locale=ar` / `locale=en` produce existing 200 schema with matching title, stable ordering and recipient scope.
- `locale[]=en`, `locale[bad]=ar`, `locale=fr` and empty `locale=` produce 422 and localized `errors.locale.0`, no Array-to-string exception or leak.
- A foreign notification ID yields 404 even if malicious caller supplies array-valued locale. Unauthenticated caller yields 401. No `notification_reads` or other notification write from GETs.
- Existing `V2NotificationStablePaginationTest` and `NotificationAudienceIsolationTest` must remain green.

## Three-app impact

**Customer:** Shared notifications read endpoint; valid scalar AR/EN requests preserve original payload and scope; only intentionally malformed requests receive 422. Existing Flutter customer notifications client is expected to remain compatible; no Flutter source changed. **Driver:** same shared GET contract, existing B2C Driver-only authorization unchanged and no assignment/state API changed. **Van:** same shared GET contract, B2B Van-only and inventory/visit behavior unchanged. App-specific read flows still require V2-wide runtime/device acceptance tracked by #2503; this narrow fix is not a claim that all three end-to-end notification journeys passed.

## Verification and closure ledger

- [ ] New focused HTTP test and existing notification regression suites in GitHub Required CI.
- [ ] PHP syntax, Pint, PHPStan, security/Composer/OpenAPI checks, AR/EN quality.
- [ ] Required real MySQL/Redis fresh-install, upgrade and recovery jobs (if configured for this diff).
- [ ] `Repository Policy`, `FOODEX V2 Isolation`, and terminal `FOODEX Required CI` success on exact final PR head.
- [ ] Recorded HIGH-risk file-by-file `TECHNICAL_REVIEW: PASS` audit, no unresolved security/functional findings.
- [ ] SHA-guarded merge of this one issue branch into `Foodex_V2` only and verified integration commit. Then and only then close #3908 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; leave parents #2733 and #2503 open.

All checkboxes are **unverified at source-commit time**; only actual terminal CI/log and GitHub merge evidence may mark them complete.
