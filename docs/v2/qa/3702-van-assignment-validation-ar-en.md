# V2 #3702 — localized Van assignment validation, source and security trace

Parent #2738 (MOB-V02), master #2503; source P110/P136, existing V2 Van assignment and P082 AR/EN failure contract. Target **Foodex_V2 only**. This is a distinct atomic localization leaf: previously merged #3480/#3489/#3493/#3495 secured assignment dates, active Van and transfer/territory boundaries; this work does **not** replace or loosen those changes.

## Reuse and impact

- **Existing entry point:** Van assignment create/edit in canonical Field Operations, plus the pre-existing authenticated `/api/v1` management assignment route. No new UI/Sidebar/route/role/permission/entitlement, no human-facing input IDs or new fields.
- **Backend:** `VanRegistryService::assign` and `updateAssignment` keep current authorization, half-open overlap queries, active Van/Driver/territory checks, row locks, persistence and audit untouched. A new pure `validationMessage(User, key)` looks up existing paired `van_assignment_guard` catalogs using that actor's explicit Arabic or English locale, with Arabic fallback, rather than mutating process-wide locale.
- **API:** HTTP 422 and field keys `effective_until`, `assignment_type`, `status`, `assignment` and `territory_key` are unchanged. Only localized human-readable `errors[field][0]` text is corrected. No public execution, assignments read DTO, push, dispatch or runtime response shape change.
- **Dashboard:** existing invalid assignment failure wording is localized through the same service. No Blade/JavaScript/layout change, no extra navigation or UI visibility independent of route authority. Existing RTL/LTR layout is preserved.
- **Customer Flutter:** NOT AFFECTED — no Customer API, checkout, pricing, role or Flutter source change.
- **Driver Flutter:** NOT AFFECTED — no Driver delivery/assignment status or API/Flutter source change; B2C DRIVER ONLY unchanged.
- **Van Flutter:** management write validation text may be surfaced by an authorized management client, but Van app runtime reads/assignment DTO and dispatch flow are unchanged. No Flutter source or JSON machine-key/status changes; existing app HTTP client treats message as display text, not authorization. Van end-to-end runtime acceptance remains tracked by #2738. B2B VAN ONLY unchanged. If a Van Flutter consumer is later found comparing the former literal English message, that is a separate blocking client compatibility defect requiring correction before this leaf is accepted.

## Localized failures

1. Effective-until on/before effective-from — same `effective_until` key on create/update.
2. Assignment type other than primary/backup — same `assignment_type` key on create/update.
3. Status other than active/ended — same `status` key on update.
4. An overlapping active primary assignment for the same Van — same `assignment` key.
5. An overlapping active primary assignment for the same territory on another Van — same `territory_key` key.

`V2VanAssignmentValidationLocalizationTest` uses genuine `RefreshDatabase` Eloquent records and authoritative Service Territory rows. It verifies exact Arabic and English rejection text, failure field keys, no newly persisted assignments or audits, existing successful assignment record unchanged on rejected updates, and unsupported actor-locale fallback. It does **not** fabricate invalid historical stock/Driver records or mutate production data.

## Security and acceptance gates

No change to actor permission evaluation, cross-store authorization, assignment transaction/locking, finance ledger, Van offline behavior, migrations, seeders, runtime config, releases or production. This is HIGH review due to assignment-affecting service, even though mutation semantics are unchanged.

Before integration: validate the exact final PR head through Repository Policy, V2 Isolation and full Required CI, including Pint/PHPStan, Laravel tests, localization key parity, MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate`; record file-by-file technical review and current peer file ownership. An applicable Van regression that fails is a blocker, not an accepted skip. Keep this Issue OPEN until source and all checks pass, PR merged only into `Foodex_V2`, and closure evidence has source HEAD, integration SHA and CI links. Human/device/staging UAT remains under #2503; no `main`, releases, APK promotion or production.
