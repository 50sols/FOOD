# V2 #2606 — P276 Van Trips: authorized B2B route activity

## Source and bounded implementation
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, source P276, V2 umbrella #2503, branch `Foodex_V2` exclusively.
- Domain: Fleet & Field Operations. Entity: canonical `van_visits` and route/Van metadata, not a new synthetic trip or finance ledger.
- Actor: an authenticated and authorized operator with both `drivers.b2b.view` and `customers.view` (existing HTTP guard), scoped to allowed Wholesale/B2B stores; no Retail/Driver bypass.
- Goal: discover B2B Van route activity and filter existing visit evidence by exact recorded route and lifecycle status.
- Workflow: daily operational read/review, 30 rows per page with query-preserved filters; read-only, no new approvals or settlement actions.
- Placement: **Reuse → Extend** the canonical Admin Fleet & Field Operations > Visits & Routes page. `PAGE_TAB` reached through the existing authorized Sidebar `field_ops_visits`, route `admin.field-operations.visits?trip_view=trips`, controller `FieldOperationsController::visits`, shared `admin.field-operations` renderer. Do not add a duplicate Sidebar destination.
- Navigation metadata: `domain=field_operations`, `role=B2B-authorized fleet`, `capability=Van route activity`, `navigation_owner=field_ops_visits`, `navigation_type=PAGE_TAB`, `parent_surface=Visits & Routes`, `permission=drivers.b2b.view + customers.view`, `back_path=admin.field-operations.visits`.
- Business fields: `trip_route` is a Lookup from the currently authorized canonical B2B visit route metadata, `trip_status` is a service lifecycle Enum, and no user-entered numeric Van/store/internal ID is introduced.

## Trust boundary and explicit limits
- B2B-only `VanVisit::query()` and existing server-side B2B store filters are applied **before** route/status filtering. Retail/B2C visits never enter the result or filter options, including for Super Admin. Routes use exact matches against the existing canonical metadata keys `route_key`, `route_code`, `route`. Live `fleet_current_locations.route_key` is explicitly excluded from the **trip** selector: a GPS-only key, even for an unassigned/Retail Van, is not evidence of an authorized B2B visit. The legacy **visits** route suggestions remain unchanged. A seeded HTTP regression checks that only recorded authorized B2B route options are displayed, with no database mutation.
- Van code labels are resolved for at most the 30 authorized records in the current page. A visit's `metadata.van_id` is only a reference hint, never independent authority: only active Vans with a currently effective, active assignment through an active warehouse in the operator's authorized B2B store scope may have their codes displayed. Expired, revoked, future or suspended assignments, inactive Vans or disabled warehouses never authorize the label, even if stale metadata still references the Van. An unassigned or foreign-store Van ID embedded in an otherwise visible visit renders the neutral missing-label marker rather than leaking its global code; regression coverage plants a forged unassigned ID while preserving the visible B2B route. Status values are AR/EN-localized, metadata escaped through Blade, route selection is server-filtered, pagination preserves filter query parameters.
- An operator-controlled free-text route reference is **not** an independently verified trip ID. This view never claims a trip start/close, route load, stock transfer, reconciliation, settlement, cash collection or journal. A separate authoritative Van trip session and verified close/settlement source must be proven before treating the broader P276/P277 workflow as accepted.
- Van detail, visits, dispatch, collection, inventory, orders and GL mutation paths remain unchanged; there is no new API or migration.

## Impact matrix
| Application | Impact | Evidence |
| --- | --- | --- |
| Customer | Not affected | Admin-only GET presentation of existing `van_visits`; no Customer REST/API/auth/notification/order change |
| Driver | Not affected | B2C remains DRIVER ONLY; query explicitly excludes `customer_type=b2c`, no Driver code/route change |
| Van | No Flutter/API contract change in this bounded delivery | Existing B2B Van visit and route metadata are read without mutations. Full trip lifecycle parity must be reviewed if a new canonical trip session is introduced |

## Acceptance and repeatable checks
- Focused test: `php artisan test --filter=V2VanTripActivityAuthorityTest` (AR/EN authenticated page, canonical page tab discoverability, B2C exclusion, exact route/status filters, denied Retail-only user).
- Confirm the existing `FieldOperationsAdminExposureTest`, `VanVisitControllerTest`, `FieldOperationsDashboardCompletionTest`, Sidebar parity, localization quality, and backend/static analysis tests.
- Inspect actual page from desktop/tablet/320–430px mobile with keyboard, focus order, RTL/LTR, tab/filters, empty state and server-side navigation. Shared Dashboard shell/table/filter primitives are reused; no screenshot CI is required.
- Mandatory exact-head GitHub `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate` (Laravel, Pint, PHPStan, security, AR/EN, MySQL/Redis acceptance and terminal required-ci-gate) must all succeed prior to merge.
- **P276 full acceptance stays OPEN** until authentic Van trip identity/operational lifecycle and all impacted Van application contracts are established and verified. This read-only phase must not be passed off as a full trip settlement implementation.

No merge to `main`; no tag, APK, release or production deployment is authorized.

## 2026-11-15 — P276 visit-to-Van identity provenance
- Existing trip label projection accepted `metadata.van_id` if that Van had **any** current authorized B2B assignment, even when the visit's `metadata.van_assignment_id` pointed to a different Van or operator. Such a row could misattribute a B2B visit despite having no valid visit-to-Van proof.
- The original authorized 30-row P276 tab now resolves only current effective B2B WMS-scoped assignments explicitly referenced by each visit; the recorded Van ID, assignment ID, actor user and (when present) B2B store must all agree. An absent/stale/inconsistent recorded assignment renders a neutral dash; metadata alone cannot substitute for an authoritative assignment. Existing active assignment and inactive/expired/disabled-warehouse guards remain.
- Authenticated seeded regression covers positive matching provenance, wrong Van ID despite both Vans having legitimate active in-scope assignments, and assignment operator mismatch; the view still shows the route without a forged Van label. Existing AR/EN, channel/store and authorization assertions retained. The change touches no Van Flutter/API or dispatch lifecycle.
- **Not source complete:** Recorded assignment metadata is not itself immutable trip identity, and P276 still lacks canonical trip session/closeout/stock custody/remittance/offline parity. Do not merge this source increment or close #2606 based on a green CI alone. Related #2602/#2604/#2607/#2588/#2739 preserve independent ownership.

## 2026-11-15 — P276 planned visit provenance write guard

- The existing Admin `storeVisit` POST previously accepted an active assignment even when its Van was inactive, its warehouse was disabled or belonged to Retail, or a caller supplied a different B2B store. A planned B2B visit could consequently be recorded with an unscoped/null or forged store while appearing tied to a legitimate Van.
- The original P276 branch now validates the assignment Van is active and the warehouse plus store are active Wholesale/B2B; the warehouse store must be authorized for the acting Admin and an explicit posted store must match it exactly. The bound operator account must be active. For an explicitly supplied store ID, persist the verified assignment warehouse store. When the caller omits store_id, preserve the existing null-store behavior: Van direct-sale eligibility depends on store_id, and populating it automatically would silently grant new sales authority without complete Van inventory custody. No additional routes, roles, database tables, mobile APIs, or financial transitions.
- The authenticated HTTP regression tests both existing permitted forms (explicit matching B2B store and omitted/null store) and negative inactive-Van, disabled-warehouse, different-Wholesale-store, Retail warehouse and inactive-operator cases with unchanged visit row count after each denial.
- This is a planning authorization fix only. It does not create immutable Van trip sessions, load/return/settlement custody, GL, Van app replay, or signed acceptance; therefore P276 remains **source incomplete**, PR DRAFT, Issue OPEN. Affected apps: Customer no contract change, Driver no contract change, Van API schema unchanged, existing null-store visits remain null, and explicitly scoped visits continue to return their verified B2B store (see VanVisitController::visitPayload/customers direct-sale eligibility). Full affected-app acceptance must still be reviewed alongside #2739.

## 2026-11-15 — P268 compatibility regression with P276 planning authorization

- The exact-head P276 backend full suite identified one real preexisting positive-test fixture mismatch: `V2VanVisitsAuthorizedSidebarTest::test_platform_wholesale_operator_can_create_b2b_visit_but_never_b2c_visit` created an active Van assignment with **no warehouse_id**, and then expected the Admin POST to succeed. Under the new P276 bound Warehouse/Wholesale provenance guard, that request must be rejected (HTTP 404), so the old fixture no longer represents a genuinely valid planned visit.
- The P268 positive fixture now creates a real, active Warehouse owned by its own B2B test store and assigns the Van to it. The test still checks the actual successful B2B visit and unchanged B2C rejection; no Controller/permission relaxations, no cross-tenant bypass, no new endpoint, migration, mobile contract, settlement or physical stock ledger. Inactive/unassigned/foreign cases remain denied by P276 negative tests.
- This regression compatibility repair is incremental. It does not constitute immutable trip identity, physical Van custody, offline replay or source-complete P276 acceptance. Original #2606 issue OPEN, PR #3311 DRAFT, `Foodex_V2` only.

## 2026-11-15 — isolated canonical trip-session identity source increment (not P276 source closure)

**Original Issue #2606 / original Draft PR #3311 / branch feat/2606-v2-authorized-van-trips, base Foodex_V2 ONLY.** This increment deliberately avoids shared Field Operations controller and Blade files overlapping active P274 #2604 / PR #3330.

- Added a data-preserving migration for van_trip_sessions and append-only van_trip_session_events. The UUID identity is bound to one active B2B assignment, Van, active Wholesale warehouse/store, active operator, initiating actor and immutable start key. The reserved_van_id UNIQUE slot denies multiple unreconciled trips even if a caller bypasses the domain service. Legacy visits/order/stock tables remain untouched.
- Added internal VanTripSessionService start/endOperationalTrip with Van and assignment row locks, effective-window validation, B2B warehouse and tenant scope for BOTH drivers.b2b.manage and customers.view, B2C-driver denial, strict replay identity matching, same-actor end and durable start/end event rows.
- FAIL CLOSED: operational ended state retains the unique reserved Van slot, does NOT mean stock custody release, independent maker/checker approval, finance/AR/GL settlement, legally certified title, or Van sale eligibility. No slot-release API until #2588/#2604/#2844 and #2602/#2607/#2739 are source-complete and verified.
- Focused tests cover same-key replay, forged cross-actor/assignment replay, inactive actor/Van, revoked assignment, disabled or Retail warehouse, malformed keys, zero stock movements, stable end-event replay, unique DB slot and blocked new trip after unverified end.
- Customer: unaffected runtime/API. Driver: B2C unaffected, no Driver fallback. Van: future trip-ID/offline consumer via #2739, NOT changed in this increment. Dashboard: no new route/UI; existing visit-based trip display is not a certified physical trip.
- GCC PHP 8.3.33/SQLite in-memory: real migrate:fresh succeeded with the new migration; focused test suite 7/45 PASS; final P276/P268/VanVisit regression group 29 tests/235 assertions PASS after the final permission/date fixes, PHP syntax 4/4 PASS, Pint 4/4 PASS and targeted PHPStan 0 errors. The local shared vendor is a junction to a peer checkout, so an uncommitted disposable test bootstrap corrected PSR-4 and APP_BASE_PATH; production Composer autoload and peer workspace were not modified.
- Source completion remains BLOCKED: real Van/stock/finance/visit/order producer-consumer integration, scoped Dashboard/Van runtime, AR/EN/accessibility, security/tenant/MySQL concurrency, exact-head Required CI and HIGH engineering review are still required. Keep original PR DRAFT and Issue OPEN. No main, release, tag, deployment or production.

## 2026-11-16 — P276 offline retry immutability hardening

- Scope: existing original #2606 Draft PR #3311 / branch feat/2606-v2-authorized-van-trips / Foodex_V2 only. No change to Dashboard/controller, HTTP routes, Customer/Driver/Van binaries, existing stock or accounting posting. This is an internal source contract and remains non-user-facing.
- Before: start() validated mutable assignment/Van status before consulting the immutable trip record. A delayed request retry from a legitimately created B2B Van trip could be rejected as a NEW request after assignment expiry/revocation or Van suspension, leaving the client unable to resolve its prior accepted outcome.
- Now: same assignment+start_key first resolves the recorded trip. Replay requires the SAME initiating actor plus current B2B STORE-scoped drivers.b2b.manage and customers.view authorization against the ORIGINAL stored store. A disabled account or store, or a different actor, remains denied; new keys still need a current authorized/active assignment and Van. No new row, movement, second stock debit or trip status transition occurs on retry.
- Database migration (already new/unmerged) also adds UNIQUE (van_assignment_id,start_key), protecting against duplicate trip identity after a mutable assignment Van reassignment. Existing UNIQUE(van_id,start_key) and reserved_van_id remain. This is not an update to any live already-installed table; the entire new migration remains unmerged and subject to final Setup/Update ZIP upgrade validation.
- GCC PHP 8.3.33 SQLite in-memory scoped regression: 30 tests / 245 assertions PASS, including new revoked/expired/suspended same-key replay, different actor, stale new-key, disabled account/store, and direct SQL uniqueness bypass attempt. No live MySQL simultaneous writer test was performed. Re-run exact-head Github CI after commit; this note does not claim a CI PASS.
- Still cannot close P276: authenticated accessible Dashboard and Van trip workflow, actual immutable cargo/returns #2588/#2604, legal title #2844, offline Van app #2739, full trip finance/settlement #2602/#2607, final security/recovery and signed upgrade/end-to-end provenance remain unimplemented or unverified. Keep PR DRAFT and issue OPEN; no main/release/tag/production.

## 2026-11-16 — P276 internal active trip custody-consumer identity contract (incremental)

- **Original Issue #2606, Draft PR #3311, Foodex_V2 only.** Added `VanTripSessionService::requireActiveCustodyContext(User $actor, int $assignmentId, string $publicId): VanTripSession` so subsequent P258/P274 movement writers can resolve the *persisted immutable trip source* in the same outer database transaction rather than accepting caller-forged `van_id`, `store_id`, `warehouse_id` or route metadata. This method does **not** create a physical inventory balance, authorize a load, transfer stock, release reservations, or settle finance.
- **Fail-closed predicates:** active actor with both scoped B2B `drivers.b2b.manage` and `customers.view` permissions; exact trip-to-assignment binding; active, unended trip retaining its unique reserved Van; currently effective active assignment; same active Van and warehouse; active B2B Wholesale store; active matching representative/driver; matching immutable first `started` event with original actor and start idempotency key. Lock acquisition follows assignment → Van → trip to align with `start()` and support transactional custody workflows. Legacy/revoked/expired/rebound or Retail context cannot authorize physical posting.
- **Negative verification:** the existing `V2VanTripSessionIdentityTest` now checks legitimate active resolution plus tampered start event, Retail actor, empty Van reservation, operationally ended trip, revoked/expired assignment, different operator and disabled warehouse. Each failure leaves trip/event counts and `stock_movements` unchanged. No Customer, Driver, Van Flutter/API or Dashboard routes/views were added; B2B=VAN ONLY, B2C=DRIVER ONLY.
- **Test provenance:** GCC PHP 8.3.33, `pdo_sqlite`, isolated `APP_BASE_PATH` set explicitly because external Composer vendor junction otherwise inferred an unrelated checkout; disposable local bootstrap required only for the original unmerged model/service. Focused 10 Laravel feature tests / 73 assertions PASS on exact locally modified source. No production/staging authorization inferred from SQLite; required MySQL/Redis hosted checks run on subsequent exact GitHub PR head. No test bootstrap, APP_KEY, or vendor files included in PR.
- **Still blocked for P276 closure:** authenticated HTTP + Van app trip lifecycle, real stock/title and accepted load/returns (#2588/#2604/#2844), order/visit membership, finance/AR/GL settlement and checker (#2602/#2607), durable Van offline replay (#2739), AR/EN screen/device and MySQL concurrency/recovery. This helper is *identity-only*, not an authorization to merge a source-incomplete Draft or declare a finished trip. Preserve all independent workers' original PRs; no `main`, tag, release, deployment or production action.

## 2026-11-16 — Fail closed on unaudited trip replay and operational end

The P276 internal Van trip identity service already verified the first immutable `started` event for `requireActiveCustodyContext()`, but not for `start()` idempotent replay or `endOperationalTrip()`. A corrupted/legacy row with an altered or missing start event could appear successfully replayed and then be operationally ended despite having no verifiable start provenance. A null/forged `reserved_van_id` had the same replay/end gap.

The original P276 service now calls one shared `assertImmutableTripStartEvidence()` at both replay decision points, before operational end (including same-key end replay), and when resolving active custody identity. It rejects a missing/mismatched original start event or a lost/mismatched unique Van reservation with HTTP 409 and performs no movement or finance posting. It does not block a valid same-actor, store-authorized retry solely because its original assignment has since expired or the Van was suspended: immutable history is preserved; no new trip is created. It does not release a Van slot after operational end.

The new feature regression proves pre-patch RED: replay returned an unverified success for a tampered event; after the fix it proves start replay and end deny both a tampered first event and erased reservation, then accept the restored authentic event and permit one operational end. Existing invalid actor/store, B2C isolation, expired assignment, anti-replay and zero-stock-movement tests remain. GCC isolated checkout, PHP 8.3.33 / SQLite in memory with a **local-only** source-correct test autoload override (the shared vendor directory otherwise resolves unrelated worktree classes): 33 adjacent P276/visit HTTP tests and 272 assertions PASS; no changes to Composer production autoload or peer checkouts. This check does not substitute for hosted MySQL/Redis CI.

**HIGH source risk and nonclosure:** this is canonical trip identity/integrity source behavior only. Full P276 still needs the authorized Dashboard/Van app trip journey and offline idempotent recording, visits/orders/load/return membership, legal-title-approved WMS cargo, finance/GL maker-checker settlement, AR/EN device/a11y and MySQL concurrency. Original Issue #2606 and Draft PR #3311 remain OPEN until all requirements are source-complete and exact-final-head CI and independent review pass. Customer, B2C Driver and Van Flutter/API contracts are unchanged by this bounded internal service correction. No new branch, `main`, release, tag or production.

## 2026-11-16 — P276 immutable operational end-event replay guard (Worker E)

**Original Issue #2606 / Draft PR #3311 / Foodex_V2 only.** On the existing
`VanTripSessionService::endOperationalTrip()`, an `ended` row with a matching
`end_key` and same actor previously returned success on retry without
reconciling the append-only `van_trip_session_events` sequence 2 event. A
missing or tampered end audit record could therefore appear as a verified
operational completion, despite the service's separate start-event guard.

**Source correction:** terminal idempotent retry now requires the original
`ended` event on the exact trip (sequence 2, kind `ended`, recorded
`ended_by_user_id`, matching immutable `end_key`), as well as a non-null
`ended_at`. Mismatched key/kind, missing event or missing completion time
return HTTP 409 before writes; a verified same-actor retry still returns
the same trip without a duplicate event. This path never performs stock,
warehouse, payment, AR/GL, reservation release or other posting.

**Real GCC red/green evidence:** original exact PR source HEAD
`28e7738d36387a0a8cc53fe12dfcbd50bd5ac039` plus new targeted
PHPUnit regression first **FAILED** because an altered end-event
idempotency key was accepted. After the service fix, the targeted test
passed 1/7 assertions; the full P276 immutable-trip suite passed
12 tests / 89 assertions with PHP 8.3.33 and an isolated SQLite
test database. The broader three-suite checkout run had 29 tests pass
and one unrelated `V2VanTripActivityAuthorityTest` failure
(HTTP 302 vs expected 404 in planned visit POST); this is NOT a full
local green claim and requires source/fixture diagnosis if hosted CI
reproduces it. PHP syntax and Pint --test passed on both changed PHP
paths after normalizing LF line endings. Temporary APP_KEY, isolated
autoload bootstrap and Composer vendor junction remained outside the
tracked package.

**HIGH source/security review boundary:** same-account technical review
cannot be independent approval. The two committed runtime/test files
and this QA note must be validated on the exact new GitHub HEAD by
Repository Policy, V2 Isolation, full Required CI and MySQL acceptance
before any integration decision.

**Source completion remains BLOCKED:** this proves only the immutable
end-event replay guard. Full authorized Van/Dashboard journey, trip
physical load/return legal-title stock authority (#2588/#2604/#2844),
Van offline app (#2739), trip payment and AR/GL settlement checker
(#2602/#2607), real end-to-end and AR/EN device acceptance are still
missing. Keep original PR DRAFT and #2606 OPEN; no main, release, tag,
production, branch duplication or ownership of Worker D's files.


## 2026-11-16 — Worker E P276 privileged-peer custody impersonation

Original #2606 / DRAFT #3311 branch `feat/2606-v2-authorized-van-trips` was reused at `79b56559fb873800b0970d5ef38ce92716d77f68` with the verified handoff lease. This increment addresses a **real source authorization defect** in the original P276 trip identity writer, not CI-only coverage.

**Problem, demonstrated RED:** `VanTripSessionService::requireActiveCustodyContext()` correctly enforced B2B store, assignment, active reservation and audit event, but accepted *another* active SUPER_ADMIN (not the immutable trip starter) because both had store scope. The service describes itself as a prerequisite to future physical custody posting, and there is no audited custody transfer/handoff authority. Different privileged actors cannot implicitly take over a trip's physical-custody identity. New feature test `test_unassigned_privileged_peer_cannot_impersonate_trip_starter_for_custody_writes` FAILED on the exact original source with `Expected an HTTP 409` (test assertion 2).

**Correction and negative guard:** After validating tenant permissions and the exact original assignment, the custody consumer now rejects an actor whose ID differs from the trip's immutable `started_by_user_id` with HTTP 409. Same-actor audited active custody remains allowed; no owner override, alternate assignment, stock transfer, payment, AR/GL posting, Van reservation release or user delegation is introduced. The future cross-user handoff must first provide a separate trusted immutable maker/checker custody-transfer contract.

**GCC real RED/GREEN:** Isolated original branch worktree, local copied production/development Composer dependencies and PHP 8.3.33 + pdo_sqlite. New negative test **RED** before service change; after fix targeted Van trip identity suite **13 tests/95 assertions PASS**. Related `V2VanTrip*` and `V2VanVisitsAuthorizedSidebar` suites **31 tests/242 assertions PASS** after supplying an ephemeral test-only APP_KEY and increased local PHP memory budget. No secrets or fixture environment settings committed. PHP syntax for both files PASS; Pint --test for both after LF-normalized working copies PASS, git diff --check PASS. The earlier first wide-run errors were local APP_KEY absence, not accepted as a green suite. See exact GitHub head workflows for definitive CI evidence.

**Review risk and scope:** HIGH (future physical stock authorization). Only original source `backend/app/Services/VanTripSessionService.php`, `backend/tests/Feature/V2VanTripSessionIdentityTest.php` and this append-only QA note are modified. No modifications to #2604 / PR #3330 / Worker D, #2844 stock title, WMS, finance, Customer/Driver/Van apps, navigation or public routes. Customer and Driver have no code/API change; Van has no live mobile/API change from this isolated service guard; wholesale VAN ONLY and retail DRIVER ONLY preserved.

**FULL P276 SOURCE STILL INCOMPLETE:** authenticated Dashboard + Van trip lifecycle UI/API, physical stock/legal title and loads #2588/#2604/#2844, offline replay #2739, maker-checker GL/settlement #2602/#2607, physical staging, Arabic/English end-to-end and exact-head final acceptance. Keep original #2606 OPEN and #3311 DRAFT; do not merge, close, sign/release/tag or deploy from this scoped guard.

## Persisted actor revocation guard (2026-11-16)
- `VanTripSessionService` now re-reads and locks the persisted active User row in its start, operational-end and active-custody transactions. A formerly active hydrated model cannot bypass a later account suspension.
- `V2VanTripSessionIdentityTest::test_persisted_user_suspension_revokes_cached_actor_before_trip_or_custody_mutation` denies three stale-model mutations (403), asserts zero new trip/event/stock writes, and positively resumes when authority is restored.
- Customer: no API/app change. Driver: no B2C dispatch/API change. Van: server-side safety guard for existing B2B custody identity; no new Flutter endpoint or payload. This is a bounded P276 security fix, not proof of final physical custody, offline app delivery or finance closeout; full source acceptance remains gated by original issue/dependencies.
