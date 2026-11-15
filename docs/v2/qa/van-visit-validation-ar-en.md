# V2 #3712 — Van visit validation AR/EN

Source: P268 / #2598 Van Visits, #2738 Van lifecycle, V2 master #2503. Base and merge target: `Foodex_V2` only. No production/release/main.

## Source finding and fix
`VanVisitLifecycleService::transition` was returning four English-only `ValidationException` messages from already-authorized Dashboard and Van API paths. The four failures (illegal transition, missing authoritative order, missing no-order reason, inactive reason) now resolve paired `van_visit_guard` AR/EN keys in the selected Laravel request locale. Field keys (`status`, `order_id`, `no_order_reason_id`), validation exception/422 shape, transaction and lock order, legal status transitions, and no-write rejection semantics are unchanged. The existing customer/store/channel provenance guard and translation key are untouched. Transition messages intentionally do not expose raw internal status codes as display labels.

## Evidence and negative matrix
New `V2VanVisitValidationLocalizationTest` uses `RefreshDatabase`, actual `User`, `VanVisit` and `VanNoOrderReason` rows, and explicitly selects Arabic and English. For both locales, each of the four negative cases asserts exact translated field message and identical persisted visit record before/after. Positive active no-order-reason completion is checked; original `VanVisitLifecycleServiceTest` and existing HTTP authorization/cross-channel regression must also remain passing. No new audit/notification/push is performed in this service. Required CI checks and exact SHA must be cited in the PR; a proposed test is not a claimed passed result.

## Authorization and apps
- Dashboard: existing authenticated `FieldOperationsController` permission, B2B store and visit scope guards untouched; receives localized validation copy without UI/route/RTL markup modifications.
- Customer: no public API or Flutter model/UX change.
- Driver: no Driver API, Flutter or dispatch change; B2C DRIVER ONLY.
- Van: preexisting scoped `Api/V1/VanVisitController` still authorizes the visit and accepts identical status/order/reason payload; localized 422 text is resolved by the server's selected request locale; no new schema, DTO, endpoint, assignment or Flutter code. B2B VAN ONLY.

## Risk and acceptance
`REVIEW_RISK: MEDIUM` — user-facing error-copy change on sensitive visit state transitions. Review every changed path for accidental authorization or state-machine expansion; verify AR/EN parity and no data writes on rejection. A same-account COMMENTED engineering audit is not independent peer approval. Exact-final-head Repository Policy, FOODEX V2 Isolation, Laravel/Pint/PHPStan/security, localization and terminal Required CI must pass before merging. Manual staging/device UAT remains tracked under #2503.
