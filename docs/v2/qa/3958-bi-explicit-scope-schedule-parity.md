# V2 #3958 — Existing BI store-scope and schedule HTTP parity

Status: **source fix proposed; acceptance gated by exact-head CI and reviewed PR**. This is an atomic P111/P312 follow-up on `Foodex_V2`, not a BI feature, new report or invented health certification.

## Source-authoritative paths and gap

| Surface | Existing source | Required boundary |
|---|---|---|
| BI GET report | `EnterpriseBiController::index/selection` → `ReportBuilderExecutor` → `ReportAccessResolver` | Explicit Store B or unlisted legal-entity report scope returns **403**, not silent Store A substitution. Absent scope may select the first authorized scope. |
| BI schedule POST/PATCH | `EnterpriseBiScheduleController` → `ScheduledReportManager` → `ReportAccessResolver` | Same active global role and Customer Service Dashboard-profile exclusion as BI GET; scope must appear within the effective actor `OperationalTenantScope::allowedStoreIds('reports.view')` for stores or visible active legal-entity association. |
| BI readiness | `EnsureErpOperationalReadiness` for `admin.erp.bi` + `admin.erp.bi.*` | Disabled, uncertified, unhealthy, or missing module remains 403; **no production attestations** supplied here. |
| Report authority | `config/enterprise_reporting.php` and existing `ReportAccessResolver` | Use only registered `orders.summary`, report permissions and `reports.read/reports.export` capability gates, no new report/model/capability. |

**Potential denied actions are authorization probes, never fabricated business data.** A role grant or foreign module entitlement alone cannot establish user Store B membership. Query parameters must not change the effective tenant/store; a valid explicit Store A succeeds and remains scoped.

## Regression matrix (new `V2BiExplicitScopeScheduleAuthorityTest`)

| Principal/scope | GET report | Schedule POST | Schedule PATCH |
|---|---|---|---|
| Active BI analyst with B2C Store A/B role grants, BI entitlement A only, A scope | 200, AR RTL and EN LTR | redirect, one A record only | unchanged existing canonical path |
| Same actor, explicit B scope with BI Store B DENY | 403 | 403, no B schedule | protected by effective scope guard |
| Assigned only Store A but entitlement row exists for unassigned Store B | 403 | 403, no B schedule | 403 for B-scoped row |
| Roleless | 403 | 403 | denied by same actor gate |
| BUSINESS_ANALYST global role inactive or user inactive | 403 | 403 | denied by active principal/session gate |
| Mixed BI + CUSTOMER_SERVICE_AGENT role | 403 | 403 | 403, existing schedule unchanged |

Existing `V2BiSidebarReadinessAuthorityTest`, `EnterpriseBiWorkbenchTest`, `V2BusinessAnalystReportingAuthorityTest` are maintained untouched under other scopes/worker fences.

## Technical review prerequisites

- **Risk HIGH:** cross-store BI read/export metadata and schedule persistence. Review both controller diffs for no new routes, no exports before explicit scope checks, no privilege expansion and no bypass of module readiness.
- Check SOD/finance read-only: BI report requests never mutate receipts, payments, reconciliations, GL or finance source ledgers; scheduled-report records are existing BI-only objects.
- Verify AR/EN RTL/LTR in authenticated GET and denied mutations; responsive visuals unchanged because no Blade/CSS edits.
- CI: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate with required security/AR-EN/backend jobs must be SUCCESS on **final exact HEAD** before any merger. Record runs and independent review on the original PR. **Do not mark Source Complete or TECHNICAL_REVIEW PASS until tested.**
- Isolation: no `main`, tag, release, production, or staging/installer changes; no auto-granted entitlement.
- Customer: **NO_IMPACT** — Dashboard BI HTTP only, no Customer API/model/Flutter changed.
- Driver: **NO_IMPACT** — no driver collections, dispatch or REST/Flutter endpoints changed.
- Van: **NO_IMPACT** — no Van deliveries, stock custody, routes or REST/Flutter endpoints changed. B2B VAN ONLY, B2C DRIVER ONLY preserved.

## Separate treasury owner fence

The independent Treasury discovery is documented at [#2845 audit](https://github.com/walidatiyaai2025-gif/FOOD/issues/2845#issuecomment-6928007470) and [#2632 coordination](https://github.com/walidatiyaai2025-gif/FOOD/issues/2632#issuecomment-6928009046). Open P302 PR #3942 owns `EnterpriseTreasuryController.php`; P135 PR #3065 owns existing Treasury/BI shared tests and readiness. No Treasury files changed in this Issue. Do not report Treasury test acceptance on the basis of this BI PR.
