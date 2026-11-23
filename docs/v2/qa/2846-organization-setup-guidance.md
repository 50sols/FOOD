# V2 #2846 — Administration Hub organization setup guidance (bounded P112 increment)

## Scope and source
- Existing issue #2846 P112 supports configuration readiness for delivery, subordinate to #2503. This is one bounded, **not full P112 acceptance**, change.
- Existing canonical surface: Administration Hub → Organization Administration; no Sidebar, page, module, domain, route, schema, seed or production setting added.
- Files: existing organization Controller, existing Blade, paired AR/EN language files, new `V2OrganizationSetupGuidanceTest`.
- Existing authority: platform.manage required for all GET/POST/PATCH paths; same `validatedRecord` server-side validator is reused by both Test Inputs and Save, including scoped unique business codes.
- Save invokes the existing audited `EnterpriseOrganizationAdministration` service; Test Inputs checks fields and permission, then redirects with a localized status **before** persistence or service mutations. It is **not** a full business/legal/tax/financial approval or a no-side-effect DB connector test.

## Configuration classification
- REQUIRED to create or update hierarchy records: business name (max 255) and code (max 100, Latin letter first, [A-Za-z0-9_-] thereafter, unique at the existing hierarchy scope).
- PRECONFIGURED behavior: new record active checkbox defaults selected, **not** evidence of approved company, tax or legal title; existing update maintains stored state.
- CRITICAL SEED/ADMIN PARITY (fixed on this original PR): original hierarchy migration creates system routing codes `foodex_platform` and `legacy_operations`, used by `ensureDefaultScopeForStore()` for newly created stores. Existing Administration Hub name changes are permitted and audited, but Save **and Test Inputs** now deny changing or deactivating the canonical default organization/business unit. The service enforces the same guard for non-HTTP callers. No migration, reseed, prior store reassignment, or fake real legal records. Other non-system organizational codes remain editable under existing unique/scope validation.
- EXTERNAL: actual legal company incorporation, tax registration, jurisdiction, legal stock title, finance maker/checker evidence and provenance from official records. Never seed fake certified details or show raw DB IDs/JSON.
- Under P112 #2745/#2746, host database/mail/queue/crypto/ZIP provisioning and actual installer/updater remain their *original* owner PRs. They are not edited here.
- Customer pricing/credit, suppliers, WMS title and Finance mapping remain owned by #2706/#2845/#2844/#2633 and linked original PRs. No overlapping edits.

## Acceptance and security
- The current page shows field purpose, source, safe sample, validation limits, default, permission, explicit UNVERIFIED readiness in AR/EN; same form Save/Test with no route duplication. A failed Save restores attempted values only to its create or edit form, and a successful Test preserves that form context.
- Server RBAC is checked before validating Test/Save, never inferred from hidden UI.
- Confirm positive Test returns localized status without inserts/updates/audit, and Save continues its existing behavior.
- Confirm negative unauthorized access, invalid / duplicate code, protected default routing code/deactivation denial on both Test/Save and direct service, proper locale direction and help. Positive name-edit and new-store default-scope regression must use disposable fixture only, without reseeding live data.
- Text inputs, action links, and buttons keep 44px minimum control height; the page retains keyboard focus indication and wrapped action buttons.
- Canonical organization/company/legal-entity/business-unit codes are normalized to lowercase **before** the existing scoped uniqueness rule runs, for identical Save and no-write Test Inputs behavior across SQLite and MySQL/MariaDB collations. PHPUnit tests deny mixed-case duplicates with zero audit writes and allow a new uppercase code that persists only in canonical lowercase. Neither workflow grants a new route or alters existing code ownership/scope.
- Test is a **field validation preview only**: hierarchy service can still reject an update for active-child/inactive-parent business constraints. Such a rejected Save must stay rejected, not become certified by a successful preview.
- Do not mark any company/tax/legal record READY merely because the hierarchy form was saved.
- Manual responsive verification: 320, 360, 390, 412, 430, 768, 1024, 1280+; AR RTL/EN LTR; keyboard focus and wrapped buttons.
- Customer: NO API/Flutter change; Driver: NO API/Flutter change (B2C driver only); Van: NO API/Flutter change (B2B van only). Only authenticated Dashboard hierarchy page and same protected mutations changed.

## Gates
- REVIEW_RISK: HIGH (organization/master-data and authorization-sensitive preview).
- SOURCE_COMPLETE: NO for full #2846 P112; signed authentic Setup/Update ZIP, MySQL clean-install and backup/recovery/cPanel external evidence remain separate required gates.
- TECHNICAL_REVIEW: PENDING exact-head tests/review.
- Required before merge: exact-final-HEAD Repository Policy, V2 Isolation, Required CI with backend/AR-EN/installer-upgrade as applicable; code security review; no file-ownership collision. Keep PR DRAFT while any gate fails.
- Base `Foodex_V2` only. No main, tag, release, publish, cPanel or production.

## 2026-11-23 — Whitespace-only business names validation hardening
The existing guided preview and Save handlers previously validated the raw `name` string and then trimmed it before persistence. The shared validator now trims string names **before** the `required` check, matching the canonical value that is actually stored. The Feature regression `test_setup_preview_and_save_reject_blank_names_without_writing_and_trim_valid_names` covers whitespace-only preview and Save denial with no organization/audit writes, and normalizes padded valid names on Save. This bounded fix does not certify signed Setup ZIP, Update ZIP, installer deployment, upgrades, backup or recovery; the P112 parent and this Issue remain source-incomplete, pending full exact-head CI and independent applicable review. No mobile/API/main/production change.

## 2026-11-23 — Preview/Save deactivation parity for active hierarchy
Guided `_setup_test` previously returned a success flash for deactivating an organization with active companies, a company with active legal entities or a legal entity with active business units, although actual Save rejected the operation. The shared service method `EnterpriseOrganizationAdministration::assertUpdateAllowed` now applies identical protected default-identity and active-descendant/assigned-store constraints before both Preview and Save, with the service enforcing them for non-HTTP callers too. The HTTP regression `test_setup_preview_rejects_deactivation_with_active_descendants_before_save` checks Preview and Save denial on all three hierarchy levels with no record or audit changes. Full P112 signed packages, installer/upgrade, backup and recovery are still pending; no claim of release readiness or CI success from this source-only checkpoint.

## 2026-11-23 — Inactive-parent child create preview/save parity
The guided `_setup_test` endpoints for Company, Legal Entity and Business Unit creation previously validated fields then announced success without checking whether the organization/parent/ancestor was active. Actual Save called the service and rejected inactive lineage. A shared `assertCreationParentActive` service preflight now guards both Preview and Save, preserving server-side enforcement for non-HTTP callers. New AR/EN HTTP regression `test_child_setup_preview_and_save_both_reject_inactive_parent_or_ancestor` verifies all three child tiers reject and do not persist records or audit writes. This scoped correction does not prove P112 signed Setup/Update ZIP, installer, full fresh install, upgrade, backup or recovery; #2846 must remain open until full source, independent review and final exact-head CI pass.
