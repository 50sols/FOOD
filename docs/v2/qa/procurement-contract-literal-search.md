# V2 #3677 — Literal authorized Procurement supplier-contract search (P082)

**Source:** #2818 P082, Procurement ERP Stage #2707; master #2503. **Only integration target `Foodex_V2`.** No V2 promotion to main, production, tags, releases or cPanel.

## Confirmed defect and exact edit
At issue claim the `ProcurementCompletionManager::paginateContracts` read service trimmed and **deleted** `%`/`_` before using implicit SQL LIKE for `contracts.contract_number`, `contracts.title`, `suppliers.code`. Symbol-only search silently turned into the full authorized list and punctuation inside a valid record changed match semantics.

Preserve `q` as a trimmed literal (validated max:120), escape `!` → `!!`, `%` → `!%`, `_` → `!_`, with backslash unchanged. Use fixed-column parameter-bound `LIKE ? ESCAPE '!'` for three columns. Contract number and supplier code use prefix matches, title uses substring matches. Explicit outer wildcards are the only match operators. The existing parenthesized OR stays below the original `organization_id`, `company_id`, `store_id`, and `status` AND conditions. Empty search intentionally keeps original unfiltered behavior.

**Reuse → Extend:** canonical Procurement Workbench Contracts list, not a new route, menu, screen or independent permission. `scope` still enforces procurement enabled and `procurement.purchase_orders.view` company scope. Original draft/active/terminated/expired and past-date status conditions, ordering and 10–100 pagination remain. No supplier/contract create, activation, financial journal, invoice, SoD, schema, migrations or stock write change.

## Real test
`backend/tests/Feature/EnterpriseProcurementCompletionTest.php::test_scoped_supplier_contract_search_preserves_literal_numbers_titles_and_supplier_codes`

- `RefreshDatabase` real module enablement, authorized Store actor, SupplierDirectory::create → ProcurementCompletionManager::createContract, and foreign Retail actor/store. Only **synthetic** generated contract reference and supplier code fixtures are replaced with punctuation-bearing legacy references to test read filters.
- Positive/negative literal prefix/substring tests with `%`, `_`, `!`, backslash and decoys across all three columns, including foreign-store matching symbols not shown.
- Status `draft`, `active`, `terminated`, computed `expired` + date cutoff, minimum/maximum pagination, roleless HTTP 403, unchanged supplier/contract/audit row counts.
- Full Laravel/PHP/Pint/PHPStan/security, MySQL/Redis deployment acceptance, Enterprise Performance, AR/EN, Policy/V2 Isolation and terminal Required CI **must pass on final SHA**, without CI waiver.

## Surface and security impact
| Surface | Impact |
| --- | --- |
| Backend | **Affected:** scoped Procurement contract listing read filter only |
| Dashboard | **Affected:** existing Contracts list search result accuracy; existing AR/EN, RTL/LTR and responsive layout unchanged |
| Customer | **Not affected:** no app code, public API, checkout or identity flow changed |
| Driver | **Not affected:** no B2C assignment/delivery API or Flutter change |
| Van | **Not affected:** no B2B stock, custody, dispatch, route or Flutter change |

B2B VAN ONLY and B2C DRIVER ONLY unchanged. No data migration, live account mutation or new mobile issue necessary for this Dashboard-only read correction.

`REVIEW_RISK: HIGH` (Procurement tenant/contract reads); require documented detailed same-account `COMMENTED` technical audit (not independent approved), negative permission/data-scope tests, source diff and all final-head CI green. Original #3673 RFQ leaf PR #3674 previously merged exclusively to Foodex_V2, branch deleted; 14 currently open PR changed-path lists checked and this new three-path fence does not overlap an active worker. Close this atomic leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only after SHA-guard merge to Foodex_V2 verified by GitHub; keep #2818 and #2503 OPEN for full source/business acceptance.
