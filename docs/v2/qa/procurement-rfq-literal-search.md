# FOODEX V2 #3673 — Procurement RFQ literal scoped search (P082)

**Integration branch:** `Foodex_V2` only; parent #2818 (P082), Procurement Stage 3 #2707, master #2503. No main, release, tag, signed APK or production deployment.

## Reproduction and exact scope

The original `ProcurementCompletionManager::paginateRfqs` (current isolated V2 source at issue creation) validates `q` max 120 then removes operator-entered `%` and `_`, and performs implicit SQL `LIKE` on `rfqs.rfq_number`, `rfqs.title` and `requests.request_number`. An input consisting only of punctuation broadens the search, and embedded punctuation alters meaning. This is **filter integrity**, not a demonstrated injection or cross-store breach.

**Reuse → Extend:** Existing Procurement Workbench RFQs list, no parallel route/sidebar/UI. The change preserves:
- Procurement module enabled; `procurement.purchase_requests.view` service authorization; company/organization/store WHERE before nested OR.
- Original `status` validation/filter, most-recent ordering, 10–100 page bounds and existing Dashboard request/UI.
- Parameter-bound fixed identifier/description columns; no new SQL identifiers from input.
- RFQ/request numbers are literal **prefix** matches, title is a literal **substring** match. `!`, `%`, `_` become `!!`, `!%`, `!_`, respectively, using `LIKE ? ESCAPE '!'`, while backslash remains literal. Exactly one desired prefix `%` suffix and two desired title substring `%` anchors are used; empty q intentionally skips the search.
- All original create/publish/award, procurement workflow, money, approval, ledger, audit and schema code remains unchanged. No migrations or data mutation in read logic.

## Real regression

`backend/tests/Feature/EnterpriseProcurementCompletionTest.php::test_scoped_rfq_search_preserves_literal_prefix_and_title_punctuation_without_mutation`

- `RefreshDatabase`, real Procurement enabling, active store-scoped permission roles, genuine submitted Purchase Request → canonical createRfq fixture, and separate foreign Retail store.
- Synthetic uniquely named identifiers and title with literal leading/internal `%`, `_`, `!` and backslash, an ordinary lookalike decoy; asserts each fixed field's prefix/substring semantics, punctuation-only input does not broaden, and a foreign store with matching symbols is excluded.
- Status filtering, minimum/maximum pagination, unauthorized role without read permission receives HTTP 403 at canonical service boundary.
- Before/after invariants compare RFQ, purchase request, quote and audit log counts across reads. No financial/stock or source mutation.

## Risk-classified audit

`REVIEW_RISK: HIGH` (Procurement scoped search). Review exact three paths and run negative store/role tests, PHP syntax/Pint, static and Composer security checks, Laravel suite, AR/EN, MySQL/Redis acceptance and exact-final-HEAD Repository Policy, FOODEX V2 Isolation and Required CI terminal gate. A single-account `COMMENTED` review is a documented technical audit, **not** an independent GitHub `APPROVED` review.

## Impact and acceptance

| Surface | Result |
| --- | --- |
| Backend | **Affected:** canonical authorized RFQ search read service only |
| Dashboard | **Affected:** existing Procurement RFQ list filter; no new page, navigation, UI label or RTL/LTR layout; existing AR/EN accessibility behavior preserved |
| Customer Flutter | **Not affected:** no public API, contract, login, or order changes |
| Driver Flutter | **Not affected:** no dispatch, assignment, B2C delivery or mobile API change |
| Van Flutter | **Not affected:** no B2B Van inventory, route, visit or mobile API change |

B2B VAN ONLY and B2C DRIVER ONLY unchanged. No need for a linked mobile runtime Issue on this read-only Dashboard-only correction.

**Merge gates:** file fence `ProcurementCompletionManager.php` (paginateRfqs only), `EnterpriseProcurementCompletionTest.php` (one focused test), this document; zero overlap with 16 original open V2 PR file lists at claim. Current CI must be verified on final source SHA, then detailed file-by-file review, SHA-guarded merge **only into Foodex_V2** and CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED closure of this atomic leaf. Parent #2818/#2503 stay OPEN for broad matrices and human/staging acceptance.
