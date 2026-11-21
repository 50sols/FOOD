# V2 #3711 / P276 — authorized API self-target Van suspension AR/EN

**Source / parent:** #2738, #2606, #2503; P276 / P136 Van-only execution contract. **Target:** `Foodex_V2` ONLY. This is a distinct, bounded localization flaw in the authenticated Van Management API. It does not reimplement security fix #3491/#3493, which already enforces transactional safe Van suspension in `VanRegistryService`.

## Verified defect and placement

Existing `VanRegistryController::suspend()`, `POST /api/v1/admin/field-operations/vans/{van}/suspend`, invokes `Gate::authorize('drivers.b2b.manage')`, validates the body, resolves target Van, and rejects source=target with a hardcoded English HTTP 422 `message`. Arabic authorized operators therefore see an English system error before the already-localized domain service runs. The canonical route and workflow are retained; no new Sidebar/page/permission module, form or lookup.

The controller now chooses the authenticated user's selected `ar` or `en` locale, default Arabic for unsupported values, and translates through the existing paired `van_assignment_guard.self_transfer_target` namespace. It preserves the same HTTP 422 `message` shape and stops before the domain operation or any mutation. Existing source/target locking, idempotency, finance, operational handoff, and permanent Van/Driver channel routing are unchanged.

## Risk and regression

- **HIGH: auth and Van custody adjacency.** The server-side `drivers.b2b.manage` Gate remains before body validation and error generation. A roleless authenticated actor must return 403 rather than leaking the self-target business validation message.
- **Real HTTP** `V2VanSuspensionSelfTargetLocalizationTest` runs with `RefreshDatabase`, `CoreReferenceSeeder`, authenticated `SUPER_ADMIN` users selected AR and EN, an authoritative Van row, self-target POST, exact HTTP 422 localized `message`, unchanged Van active status, unchanged VanAssignment count and audit count. A separate roleless actor receives 403; no Van/audit changes. Existing positive Van suspension regressions must remain green.
- **Customer:** NOT AFFECTED — no Customer API/Dart routes, scope, checkout, notifications or price contract.
- **Driver:** NOT AFFECTED — no Driver order/assignment/delivery/collection API or Dart code; B2C DRIVER ONLY preserved.
- **Van:** management-only control API text changed, not Van execution app API, payload machine keys, trip/visit/stock or Dart state logic; B2B VAN ONLY preserved. Browser/real-device acceptance for broader #2738/#2606 remains tracked by #2503.
- **No UI change:** existing AR RTL / EN LTR layout and navigation unchanged.

## Acceptance

Five issue-owned files: existing API controller, paired AR/EN Van guard catalogs, dedicated HTTP regression, this QA note. No mutation to `VanRegistryService`, `FieldOperationsController`, shared routing, data migrations, mobile source, CI workflow or release assets. File ownership checked against all current open Foodex_V2 PRs; no overlap.

Document same-account file-by-file `REVIEW_RISK: HIGH`, `TECHNICAL_REVIEW: PASS` audit as COMMENTED, not independent GitHub APPROVED. **Do not merge** until all Required Checks succeed on exact final HEAD: Repository Policy, V2 Isolation, backend lint/PHPStan/security/OpenAPI, AR/EN quality, Laravel tests, MySQL/Redis install/upgrade/recovery, and terminal required-ci-gate. Merge only to Foodex_V2; only close #3711 CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with tested source SHA, verified integration SHA, CI links. Final program and manual staging/device/UAT remain under #2503. No `main`, production, release, tags or APK publishing.
