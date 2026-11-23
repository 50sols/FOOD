# FOODEX V2 / P031 #4349 — Historical organization ownership during deactivation

## Defect and root cause
The canonical `OrganizationHierarchyService::scopeForStore()` correctly returns `null` when the source organization, company, legal entity, business unit or store becomes inactive (#4318 and #4339). However, `assignStore()` previously used this **effective access read** to enforce a separate invariant: an existing store must not silently move between enterprise organizations. Once the original lineage became inactive, `scopeForStore()` returned `null`, so the writer could reassign the persisted store mapping to a different **active** organization without a cross-organization rejection.

## Atomic fix
Within the existing writer transaction and under its locked existing mapping, resolve the original organization ID directly through the persisted `enterprise_store_scopes → enterprise_business_units → enterprise_legal_entities → enterprise_companies` lineage **without filtering active state**, and compare with the current active destination organization ID. Refuse if the original organization cannot be resolved or differs. This changes *ownership validation only*, not the effective read or the target active-lineage guard. Preserve audit and mapping on rejection. A unit reassignment within the same organization remains supported and idempotent.

## Database-backed regression
`V2HistoricalOrganizationBoundaryAssignmentTest` seeds two independent organizations with active legal lineages and a B2B store, assigns to A, disables source A in the DB, verifies effective scope is `null`, attempts direct writer reassignment to active B and requires the existing cross-organization LogicException, unchanged persisted mapping and audit. Reactivation reveals unchanged source ownership. Independent positive test confirms an active second business unit under the same legal entity can reassign once and repeat idempotently without duplicate audit.

## Governance
HIGH tenant boundary and ERP organization ownership risk. Review source and regression on exact HEAD; require Repository Policy, FOODEX V2 Isolation and FOODEX Required CI including Laravel/MySQL Redis and localization to report terminal SUCCESS; protected SHA-guarded integration into `Foodex_V2` only then postmerge verification and closing atomic #4349. Parents #2782 and #2503 remain OPEN. MOBILE IMPACT: Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No main, release, production or Flutter changes.
