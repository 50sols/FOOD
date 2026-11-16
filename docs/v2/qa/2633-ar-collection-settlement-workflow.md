# V2 #2633 / P303 — AR settlement allocations within canonical Finance

## Placement authority

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P303, parent #2503.
- Domain: Finance / Treasury; entity: posted Enterprise AR document and its allocation; actor: entitled Finance AR maker and independent Finance poster.
- User goal: review and settle a selected posted receivable without creating a second ledger or mistaking cost allocations for receipts.
- Workflow type: exact-record context action, potentially daily, finance-sensitive; data density is medium with server-side pagination.
- Canonical route and renderer: `admin.erp.finance.index?tab=receivables` in `FinanceWorkbenchController` and `finance-workbench.blade.php`. **Reuse → Extend** (not new screen/sidebar). Parent existing ERP Finance workbench; navigation_type `CONTEXT_ACTION`. Row ellipsis opens exact-document settlement details.
- Shared UI: FOODEX 44px row ellipsis and portal, operational modal, finance table and 25-row settlement paginator with preserved query, AR/EN paired locale, RTL/LTR and responsive dashboard shell. The document list does not unboundedly load all allocations.
- Authoritative actions: `POST admin.erp.finance.receivables.settlements.store` → `AccountsReceivableService::createAllocation` (draft only). `POST admin.erp.finance.receivables.settlements.post` → `AccountsReceivableService::postAllocation(..., requiresVerifiedCollectionSource: true)` now **fails closed** after permission/SoD/tenant checks because no authenticated receipt source is persisted. The route remains under Admin ERP middleware and rate limiting. Canonical non-P303 AR service callers retain their previous posting contract; they must not be mislabeled as collection-backed settlement.
- Retail tenant isolation: canonical AR service now constrains document pagination, aging, create/draft/post/cancel/allocation paths to `context.storeId` for retail, even when multiple stores share a legal entity. The new Admin AR detail/POST endpoints also reject foreign-store IDs with 404 before mutation; this prevents direct-route bypass and preexisting legal-entity-wide listing of another store's AR. Wholesale retains its authorized enterprise legal-entity semantics.
- Permission and SoD: `finance.view` + `finance.ar.read` for browsing; `finance.manage` + `finance.ar.manage` for draft creation; `finance.post` + `finance.ar.post` for independent posting. Creator cannot post own allocation. Unentitled or module-disabled operations fail closed; source document must be posted; scope includes legal-entity identifiers; posting enforces posted outstanding maximum and Finance/GL approval. Account picker lists only active posting accounts of the selected legal entity. Business users do not type internal IDs.
- Audit: Draft writes create `enterprise.finance.ar_allocation_created`; unverified P303 post attempts never create `enterprise.finance.ar_allocation_posted` or a Finance journal. Legitimate pre-existing canonical AR posting retains its separate history. Retry uses stable form idempotency key; new business request generates new key.
- Failure and recovery: invalid foreign document returns 404; conflicting idempotency key or over-allocation returns validation error; failed posting leaves draft for authorized review. Database transaction and canonical journal state machine are unchanged. No manual rollback of posted journal is implied.
- UI states: selected document, no settlements, draft/unverified receipt, previously posted linked journal, disabled create when no posting account or no manage permission, missing entitlement 403. An AR/EN source-verification warning replaces the unsafe P303 Post form on a draft. A crafted direct POST is denied with `collection_source` validation and no financial mutation; page/list/pagination and language remain unchanged.
- No backend/mobile API contract, app navigation or fulfillment behavior changed.

## Explicit three-app impact decision

| App | Impact | Evidence |
| --- | --- | --- |
| Customer | No app or public API changes; customer invoice/ledger APIs untouched | Only backend Admin Finance routes / view / language / tests changed |
| Driver | No app/API changes; B2C DRIVER ONLY and Driver collection contracts untouched | No Driver/dispatch/collection table changes |
| Van | No app/API changes; B2B VAN ONLY and Van collection/remittance workflows untouched | No Van/dispatch/visit/stock table changes |

## Reproducible tests and release gates

`backend/tests/Feature/V2ReceivableSettlementWorkbenchTest.php`: seeded, legal-entity entitled Finance fixture; authorized AR/EN exact-record route and draft modal; draft create and retry idempotency; maker cannot post; independently authorized poster also cannot post a draft without verified receipt provenance; both-language warning and absence of unsafe Post control; forged direct HTTP attempts leave the draft and journal count unchanged; cross-store direct GET/POST denied including sibling stores sharing one legal entity; canonical AR list excludes sibling documents; over-allocation rejected without financial mutation. Reuse broader `EnterpriseAccountsReceivableFoundationTest` for GL approval, account and entitlement behavior and `FinanceWorkbenchTest` for general tab/projection/AR-EN/module-disabled cases.

Run: `php artisan test --filter=V2ReceivableSettlementWorkbenchTest`, `php artisan test --filter=EnterpriseAccountsReceivableFoundationTest`, plus full exact-head Repository Policy, V2 Isolation, localization and required-ci-gate (including MySQL/Redis upgrade and recovery). Browser/device tests for desktop/320-430px mobile, keyboard, RTL/LTR remain independent final evidence; no screenshot workflow is claimed.

## Parent-first AR draft/post lock ordering — incremental correction (2026-11-15)

Previously `createAllocation()` locked the parent AR document before reading/writing allocations, but `postAllocation()` locked the allocation row **before** its parent document. Concurrent MySQL create/post operations could acquire those rows in opposite orders, increasing deadlock risk.

Posting now resolves a scoped allocation's parent ID without a row lock, locks the authoritative tenant/store-scoped AR document first, and **re-reads and locks** the allocation under that document before making any posting, identity, idempotency or audit decision. Both paths follow document -> allocation lock order. The unlocked identity probe is not trusted for financial state.

`test_allocation_post_locks_parent_document_before_allocation_to_match_draft_order` asserts the three SQL read stages (allocation identity -> scoped document -> locked allocation read) and that unverified P303 posting leaves draft/journal unchanged. SQLite verifies query ordering; no two-connection MySQL contention claim is made. Existing Retail sibling-store isolation, accounting maker/checker, AR/EN and P303 unverified-receipt denial remain intact. Customer/Driver/Van app code and public API remain unchanged.

This is only a safe concurrency guard; **P303 source acceptance remains blocked** on a trusted immutable receipt-to-customer/order/invoice/AR link, atomic receipt consumption, currency/store limits, recovery and full staging tests. PR #3309 remains DRAFT; #2633 must stay OPEN.

## Source provenance checklist (P303, 2026-11-13)

The following are **unimplemented acceptance prerequisites**, not evidence of a verified settlement:

1. Identify an immutable authoritative receipt/collection transaction using a typed source key, **not** the operator's arbitrary `reference` text or an unchecked transaction ID.
2. Independently match the receipt's originating store/channel, customer or order, currency, posted status, and valid collection account to the posted AR document. A shared legal entity must never authorize cross-store Retail matching.
3. Enforce source amount availability across **all** allocations for that receipt (including concurrent requests), with database locking, idempotency and an auditable consumption/reversal strategy. Existing `enterprise_ar_allocations` has a nullable free-text `reference` but **no receipt foreign key or source uniqueness**.
4. Permit maker/checker posting only after the server verifies the above source in the same financial transaction; derive evidence for journal/audit links and safe retry/recovery. Never represent the existing `finance.ar.post` permission as sufficient receipt provenance.
5. Provide an authorized, scoped business-facing receipt lookup in Arabic/English (not raw IDs); verify cross-store, revoked/disabled-module, duplicate receipt, unposted receipt, overcollection, no-write-on-deny and app contract parity under MySQL CI.

**Interim scope evidence:** `V2ReceivableSettlementWorkbenchTest` asserts AR/EN unverified-reference and pending-verification warnings, no Post control, and a direct POST denial with zero new journal or posting audit. `AccountsReceivableService::postAllocation` uses a source-required gate only for the Admin P303 route; no receipt source has been invented. This is **fail-closed protection**, not an implementation or validation of receipt provenance. Keep PR #3309 DRAFT and Issue #2633 OPEN until the above transaction-level acceptance is implemented and exact-head Required CI is green; this work does not waive unrelated #3160 Enterprise Performance baseline verification.

## Source acceptance NOT completed by this PR

This is an **AR allocation workflow** against a posted receivable. An operator-typed `reference` does **not** cryptographically or referentially tie the allocation to an authoritative collected receipt, Van collection, Driver collection, bank statement or Treasury reconciliation. This PR does not wire independent `collection_transactions` / receipt IDs to Enterprise AR allocation and must not be advertised as complete P303 collection-linked settlement. Exact proof of collection source, allocation↔receipt uniqueness, over-collection controls across source ledgers, multi-store financial ownership and reconciliation/policy acceptance must be designed and tested before closing #2633, without duplicating P297 #2627 bank reconciliation or P288 aging #2618.

## 2026-11-15 — P303 AR draft transaction and idempotency hardening

The original P303 PR additionally serializes `AccountsReceivableService::createAllocation()` on the authoritative, scoped `enterprise_ar_documents` row through `DB::transaction(..., 3)` and `lockForUpdate()`. The same transaction includes the posted-document state check, canonical exact-document idempotency lookup/hash comparison, posted-outstanding balance decision, draft write and audit event. This closes the previous window in which simultaneous draft creation and idempotency lookups read and wrote independently. The database's existing `(ar_document_id, idempotency_key)` unique constraint is retained as a final defense, not substituted for the document lock.

The added `test_draft_allocation_checks_and_writes_share_a_document_transaction_with_safe_retries` regression inspects query-time Laravel transaction levels for document SELECT and allocation INSERT (and the MySQL `FOR UPDATE` clause), verifies an identical retry reuses the exact draft, and rejects an altered-amount replay with no second allocation, journal or creation audit. The test is executable with local SQLite; its MySQL-specific locking SQL assertion is conditional on a real MySQL test connection. This is a transaction-boundary assertion, **not** a claim that a real two-connection concurrency stress test was performed.

**Explicitly unchanged:** Drafts continue not to reserve future receipt funds, `outstandingAmount` includes only posted AR allocations, and the P303 Admin posting path continues to refuse any source lacking an authenticated immutable collection link. No manually entered reference is converted into trusted provenance. No endpoints, UI/locales, permissions, roles, mobile APIs, invoice/collection writers, financial schema, ZIP/release files or existing GL posting logic are modified. Existing Retail cross-store restrictions remain in the canonical document selector.

**Verified GCC local evidence (PHP 8.3.33, PHPUnit 11.5.57; isolated SQLite :memory:, CLI pdo_sqlite/sqlite3/gd; dummy test-only APP_KEY):** `V2ReceivableSettlementWorkbenchTest` 5 tests / 82 assertions PASS; `EnterpriseAccountsReceivableFoundationTest` 4 / 31 PASS; `FinanceWorkbenchTest` 5 / 117 PASS; total **14 tests / 230 assertions PASS**. PHP syntax both modified PHP files PASS, `pint --test` PASS for both files, targeted PHPStan analysis of both modified PHP files **NO ERRORS**, `git diff --check` PASS. The initial local test attempt used an incorrect shared Composer autoloader and is deliberately excluded from acceptance; tests were rerun after copying dependencies to the isolated checkout. GitHub exact-new-head Required CI and real concurrent two-connection MySQL stress tests remain unverified at this checkpoint.

**Full source acceptance still blocked:** immutable typed collection receipt identity and invoice/AR linkage, source balance locking and at-most-once consumption across all receipts/allocations, customer/store/channel/currency match, maker/checker with trusted receipt, bilingual verified receipt lookup, physical/browser UAT and exact-final-HEAD GitHub CI. Treat this increment as hardening only; preserve draft PR and open Issue until all are genuinely implemented and verified.

Maintain #2633 OPEN pending those requirements and final V2 audit. No main PR, production, tags or release artifacts authorized.

## 2026-11-15 — P303 block same-account receivable self-settlement, including legacy drafts

**Verified original-head defect:** `AccountsReceivableService::createAllocation` checked only that the settlement account existed and was postable in the legal entity, but allowed `settlement_account_id` to equal the AR document's own `receivable_account_id`. The manual canonical `postAllocation` flow would later debit and credit that same account and mark a receivable allocation posted despite no actual cash/counterparty consideration. An isolated GCC PHPUnit test against original branch SHA `36d60dfa8a8ed4d07803acb619b6030cf9db974a` confirmed that a same-account draft was **created rather than rejected**; focused regression failed as intended at line 160 with the explicit `An AR account must not self-settle its own balance` assertion.

**Fix:** check the account IDs against the authoritative, scoped, locked posted receivable document before draft write/audit, and again on the manual AR posting path to refuse historically persisted self-netting drafts before GL journal creation. Preserve separate P303 Admin receipt-source fail-closed posting gate, posted idempotent retries and original legal-entity/store permission/SoD checks. Both Arabic and English `finance_workbench.receivables.settlement_account_same_as_ar` translations show a specific denial message; validation field remains `settlement_account_id`. Legitimate separate Cash-account allocation drafts continue working and are not promoted to authenticated receipt evidence.

**Real GCC tests:** isolated Windows PHP 8.3.33, PHPUnit 11.5.57, SQLite in-memory with test-only APP_KEY. Before fix dedicated regression FAIL; after fix full `V2ReceivableSettlementWorkbenchTest` **7 tests/101 assertions PASS**. Relevant `EnterpriseAccountsReceivableFoundationTest` and `FinanceWorkbenchTest` plus P303 total **16 tests/249 assertions PASS**, including AR/EN HTTP denial, no allocation/journal/audit on same-account create, no journal on a crafted legacy draft post and legitimate cash-account acceptance. PHP syntax on changed source/tests/locales PASS. After line-ending normalization from Windows CRLF only, Laravel Pint `--test` **4 files PASS**, targeted PHPStan on AccountsReceivableService **NO ERRORS**, `git diff --check` PASS. Local PHPUnit result cache not added to Git.

**Impacts:** backend canonical AR service, P303 Finance Workbench error localization, direct HTTP regression coverage. No routes, Dashboard templates/screens, Customer, Driver or Van API/call-site changes; no migrations or database changes. Does not claim authenticated collected receipt, verified source balance, money movements or independent auditor approval.

**Full source acceptance still BLOCKED:** link each AR allocation to immutable verified collection transaction and store/customer/currency/amount authority, source at-most-once consumption under MySQL lock, actual maker/checker and reversal/refunds, bilingual receipt lookup, end-to-end browser/UAT and exact-head Required CI. Keep Issue #2633 OPEN, original PR #3309 DRAFT; Foodex_V2 only, no main/release/tag/production.

## 2026-11-15 — Worker E: immutable invoice-source identity (incremental, fail-closed)

This change introduces an optional, **typed** invoice-to-enterprise-AR identity on `enterprise_ar_documents.source_invoice_id` (nullable FK, unique per legal entity). Existing manual AR documents remain NULL, without a fabricated invoice association or backfill. The existing manual AR idempotency request hash remains byte-for-byte compatible when the new field is absent.

`AccountsReceivableService::createDraft` verifies an explicitly supplied source invoice inside the draft transaction while holding its row lock: issued/reissued and not voided, exact Store ID, exact canonical Customer ID, currency, and full amount. This rejects foreign-store invoices even when stores share a legal entity, foreign customers, currency/amount mismatch, voided or missing sources and repeated claims to one source. A second claim is stopped before inserting; the database unique key remains the final concurrent guard.

**Critical accounting fence:** invoice identity alone does not certify an enterprise GL handoff or establish that a collection receipt was not already booked. The linked AR document therefore cannot be posted via `post()`; the method checks the locked invoice then fails closed without creating an AR/revenue journal. Existing unlinked manual AR posting is unchanged. The Admin P303 collection-backed `postAllocation(..., true)` also remains denied until the authoritative receipt-to-invoice allocation and one-time consumption/reversal model is established. This prevents new double-recognition of revenue while allowing the source-lineage data model to be tested. The public Dashboard form is not extended to expose an uncertified posting action.

**Evidence:** `V2ReceivableSettlementWorkbenchTest::test_invoice_to_ar_source_link_requires_exact_store_customer_currency_amount_and_does_not_post` covers valid persisted identity, replay, duplicate source, foreign store/customer, currency/amount/status/void mismatches, missing invoice and no GL mutation; EN/AR error strings added. Legacy P303 RBAC/SoD and retail sibling-store tests are preserved. Customer, Driver and Van public API/Flutter sources unchanged.

**Still required before #2633 source acceptance:** canonical invoice-to-enterprise receivable bookkeeping attestation, historical invoice lineage acceptance/backfill policy, verified collection receipt and invoice allocation authority, transactionally unique available source amounts with consistent MySQL locks and reversible accounting, receipt selection in the bilingual Finance Workbench with maker/checker, real two-connection MySQL tests and browser UX. This is a **SOURCE_INCOMPLETE / SAFE_INCREMENT**, not a P303 source-complete PR, not evidence of signed release/production readiness. Keep original PR DRAFT/Issue OPEN until full acceptance and independent HIGH-risk review. No `main`, release or production.


## 2026-11-16 — typed collection allocation evidence (incremental, NOT GL posting)

The operational collector has one canonical, posted `collection_transactions` record
with one or more per-invoice `collection_allocations`. The independent Enterprise
AR ledger has historically recorded only editable settlement reference text.
An invoice-source FK alone cannot prove a receipt; an equal customer/store pair
does not prove the same collectible invoice or that its cash is available.

This P303 increment adds an **optional nullable** typed
`enterprise_ar_allocations.source_collection_allocation_id` FK to
`collection_allocations.id`, restricted on source deletion and uniquely
claimable across AR drafts. Existing historic manual allocation rows remain
NULL; there is **no** synthetic migration/backfill from free-text references.
Legacy idempotency request hashes remain byte-for-byte unchanged when the
optional source key is omitted.

When a service caller provides this source key during draft creation, the
existing scoped parent AR row lock and DB transaction now also verify and
lock the canonical collection allocation and its posted source transaction.
The server requires an explicit `source_invoice_id` on the AR document,
canonical invoice still issued/unvoided, exact same customer/store/currency
and invoice amount, the collected allocation's exact invoice and amount,
matching active store-scoped collection account and channel (Retail DRIVER,
Wholesale VAN), matching posted collection transaction and paid field-payment
identity, matching currency, source total not exceeding transaction or
payment, no posted reversal and no previous AR claim of this source.
A mismatched, duplicate, altered, foreign or reversed source is rejected
with AR/EN `source_collection_allocation_id` validation and no AR mutation.
The persisted FK plus database unique constraint is the last at-most-once
draft-claim defense after application checks. This does **not** consume or
re-post the operational payment, GL or customer receivable.

**Critical finance boundary:** A linked operational collection is still
NOT independently certified Finance cash/AR journal evidence. The source
invoice-linked AR document itself remains non-postable under the existing
separate revenue/AR accounting handoff guard. The P303 Admin
`postAllocation(...requiresVerifiedCollectionSource:true)` route remains
strictly fail-closed, including direct HTTP calls and already-posted manual
allocations, even when a draft has the new FK. No money journal can be
created by this new link. Do not loosen this without a comprehensive
cross-ledger exactly-once accounting integration, maker/checker and safe
void/reversal/reconciliation contract.

GCC focused PHPUnit regression:
`V2ReceivableSettlementWorkbenchTest` 11 tests / 148 assertions PASS on
PHP 8.3 with test-only SQLite PDO and isolated APP_KEY (including two new
source identity allow/deny paths). Existing neighboring Finance tests and
hosted exact-final-head CI must also pass after any commit. This local
SQLite run is **not** a claim of two-connection MySQL concurrency,
certified legal receipt ownership or finished P303 source.

Customer/Driver/Van evaluation: Admin-service draft identity and schema
only; no public API contract, mobile app binary or collection writer changed.
A Driver receipt is accepted only for a matching Retail store, Van receipt
only for matching Wholesale store, with those existing payloads unchanged.
No Mobile parity acceptance for broader Stage 2 is inferred.

**Remaining P303 source acceptance / NO MERGE / NO CLOSE:** independent
accounting-recognition authority and GL handoff for operational invoice and
receipt, transactional source consumption/reversal with real MySQL
concurrency, true business-facing bilingual authorized receipt search and
posting approval, browser accessibility, cross-channel accounting
reconciliation and all required final-head CI. Original PR #3309 remains
DRAFT and Issue #2633 OPEN. No main, tag, release or production action.

## 2026-11-16 — MySQL/MariaDB identifier-length migration CI rescue

**Original P303 PR #3309, exact previous head b0a147ca.** Direct GitHub Actions logs for Required CI #43775706684, job #133002681948 (MySQL Redis deployment acceptance), show SQLSTATE[42000] 1059: Laravel's auto-derived foreign-key name `enterprise_ar_allocations_source_collection_allocation_id_foreign` exceeded the MySQL/MariaDB 64-character identifier limit. The four affected deployment acceptance tests fail at migration bootstrap, so they provide no evidence about runtime AR posting. The required-ci-gate failure is a derivative of the failed MySQL job, **not** an acceptable test waiver.

**Fix:** short stable named FK `ear_alloc_coll_src_fk`, retaining `ON DELETE RESTRICT` and unique `ear_alloc_collection_source_unique` on the nullable source ID; down() drops the actual named FK before dropping unique index and column. Since MySQL DDL is nontransactional, up() uses schema introspection to tolerate partial earlier attempts that already added the nullable column/FK/index; it never rewrites existing AR entries or infers collection identity from free text. A new dedicated migration retry test calls up() twice on SQLite and confirms required column/unique key and identifier length. This does **not** establish MySQL transactional rollback of posted ledger data; it only mitigates the specifically observed schema-failure replay state.

**Validation:** GCC PHP 8.3 targeted migration retry 1 test / 4 assertions PASS; two-file Pint --test and both PHP syntax checks PASS. Full relevant P303 + Accounts Receivable + Finance Workbench test results and exact new HEAD MySQL CI must be recorded separately after re-run. Keep #2633/PR #3309 DRAFT and OPEN; draft-only receipt provenance does not complete certified cross-ledger posted collection accounting, MySQL concurrency, independent finance review or Stage 2 #2706 acceptance. No main, release, tag or production.


## 2026-11-16 — P303 operational receipt selection and source-identity visibility (bounded; NO GL activation)

**Original #2633 / Draft PR #3309 / Foodex_V2 ONLY.** Previous migration MySQL 1059 fix on original branch `041c3799779a27c3e16be18e30a8ea234c89c970` had fully green Repository Policy #43777555841, Isolation #43777555856 and Required CI #43777555860. The earlier owner explicitly allowed handoff on terminal green; no new V2 work branch or PR was created while 14 temporary V2 branches exceed the cap of 12.

The canonical `AccountsReceivableService::createAllocation` already validates an optional typed `source_collection_allocation_id` against a row-locked posted operational receipt and an exact invoice, but `FinanceWorkbenchController::storeReceivableSettlement` was discarding that input and its bilingual UI provided no way to select it. This made operational receipt-linked settlement drafts impossible through the Finance Workbench despite the existing verified server-side contract.

**This source change:** the authorized Finance Workbench supplies a bounded 50-option source selector only for a selected in-scope AR document that has a typed source invoice. The lookup joins posted `collection_allocations`/collection transactions/accounts and paid field-collection payments, limits invoice/store/currency/channel, excludes reversed and already claimed sources, and never exposes source candidates on unrelated AR documents. The authenticated controller now passes the selected typed identifier to the **existing canonical AR service**; that service remains the authoritative atomic source/amount/idempotency/role/tenant validator, not the UI query. Previously created source-linked allocation drafts display the linked receipt identity in the authorized settlement grid with an explicit non-certified status; a claimed source disappears from the selector. AR/EN labels and notices distinguish a typed **draft** source from independently approved posted cash.

**Real GCC red/green and adjacent verification:** isolated Windows PHP 8.3.33 / PHPUnit 11.5.57 / SQLite with ephemeral test-only APP_KEY and independently copied Composer vendor. New real Finance HTTP feature case creates two different posted operational receipt allocations, filters by the selected invoice, tests AR/EN list and linked draft display, verifies one typed source FK, idempotent replay and direct HTTP mismatch rejection, and asserts **no new GL journal**. Before implementation this case FAILED because the receipt field was absent. After the changes the targeted case passed **1 test/28 assertions**. The complete adjacent P303 + Finance Workbench + AR foundation selection passed **22 tests/328 assertions**. PHP lint passed for changed PHP files, targeted PHPStan of FinanceWorkbenchController had **zero errors**, Pint --test for four PHP files passed after consistent line-ending normalization, and git diff --check passed.

**Boundaries intentionally NOT relaxed:** P303 collection-backed `postAllocation(... requiresVerifiedCollectionSource: true)` still rejects all posting, even with the FK populated; no second cash, AR, or revenue journal was created, no existing accounting recognition/backfill was changed. The selector is a read-time convenience, **not an immutable Finance settlement certificate**; race/detachment checks remain in canonical createAllocation. Legal-entity and Retail sibling-store scope, Finance RBAC, module entitlement and maker/checker remain unchanged. No mobile payload or public API changed: Customer no direct change, Driver B2C collection receipt remains existing input, Van B2B receipt remains existing input.

**Full P303 not source complete:** finance-approved invoice-to-AR initial accounting recognition, independently certified posted receipt-to-AR one-time consumption without double-entry, general-ledger reconciliation, reversal/refund lifecycle, real MySQL concurrent posting, finance maker/checker approval and browser/device UAT are still required. Keep PR #3309 DRAFT and Issue #2633 OPEN pending those source and exact-new-HEAD CI acceptance criteria. No main, production, release or tag.


## 2026-11-16 — P303 stale-receipt idempotent replay guard (bounded source security fix)

**Scope:** Original #2633 / Draft PR #3309, `Foodex_V2` only. A valid collection allocation can be atomically linked to an AR settlement **draft**, but a receipt's later reversal, custody-account deactivation or currency/store change must not be silently recertified by replaying the old idempotency key. Before this change `AccountsReceivableService::createAllocation` checked the request hash and returned the prior allocation immediately, bypassing all typed-source validity checks. **RED GCC regression:** a previously accepted posted receipt was subsequently marked reversed; the identical request unexpectedly returned the stale draft as if the original evidence remained valid.

**Fix:** once an existing request passes exact idempotency-hash comparison, a non-null typed `source_collection_allocation_id` is revalidated **inside the same parent-document-locked DB transaction** by the original authoritative invoice/receipt/collection-account/payment checks. An optional original allocation ID excludes *only that allocation itself* from the unique-source duplicate-claim lookup while still rejecting any competing claim. A now-reversed transaction, disabled or foreign account, mismatched invoice, invalid payment or other source invalidation throws the same localized `source_collection_allocation_id` validation error; it does not mutate or delete the existing draft, consume another source, post Finance GL/cash, or alter an audit trail. Genuine retries with still-valid evidence return the original immutable draft ID and create no new audit record. The existing P303 post path remains fail-closed pending independently verified Finance cash/AR/GL recognition.

**Verification (real GCC, PHP 8.3.33, PHPUnit 11.5.57, local SQLite, ephemeral test-only APP_KEY):** new service + HTTP AR/EN regression reproduced original failure before patch and passed after fixing the source guard, **1 test/15 assertions PASS**. It verifies valid replay; invalid replay after reversal, direct HTTP AR/EN denial, inactive custody source denial, restored-valid replay, unchanged single draft ID, zero posted settlement audit, unchanged single Finance journal and no extra allocation. Complete adjacent `V2ReceivableSettlementWorkbenchTest`, `EnterpriseAccountsReceivableFoundationTest`, `FinanceWorkbenchTest` selection **23 tests/343 assertions PASS**. Targeted PHPStan AccountsReceivableService had zero errors; Pint --test both PHP files passed after canonical formatter. Compare tested file blob hashes and exact-final-HEAD GitHub CI separately; GCC SQLite cannot prove two-connection MySQL concurrency.

**Not P303 source complete:** verified accounting handoff from operational invoice into enterprise AR without duplicate GL, independently approved receipt consumption and exactly-once posting, reversal/refund reconciliation, real MySQL concurrency, full Customer/Driver/Van and Finance maker/checker acceptance remain unimplemented. Keep #2633 OPEN and #3309 DRAFT until source complete and required exact-head CI succeeds. No main, production, release or tag.

## 2026-11-16 — P303 direct-service default-false duplicate-cash posting bypass (Worker E)

**Security and accounting risk: HIGH.** The P303 Dashboard explicitly passes `requiresVerifiedCollectionSource: true` when posting a settlement, but `AccountsReceivableService::postAllocation` also has historical callers using its default `false` flag. Before this fix, a fully typed, still-valid `source_collection_allocation_id` draft could be sent to the default service call and POSTED to an **additional Cash/AR journal** using the manual settlement accounting path. The route-specific guard is not a service-layer invariant and cannot protect against every existing/future caller.

**Actual GCC RED/GREEN:** On the *original exact GitHub head* `f504509dcc81b46374df4192b897c8267d2979f7`, extend the existing finance-linked receipt test with a direct default-flag `postAllocation($poster, $posterContext, $draft->id)` call after a real `CollectionCustodyService::collect` and source-bound draft. Before the service fix, the test failed with **"Typed operational collection must not be re-posted as manual AR cash"**, proving that this default path actually returned successfully after posting. After the fix, it throws the localized `collection_source` ValidationException, preserves draft status/null journal and maintains exactly one preexisting Finance journal in the transaction. Focused test **1/19 assertions PASS**. Adjacent Finance/AR suites **23 tests / 347 assertions PASS**. Both changed PHP files pass PHP syntax and Pint after LF normalization.

**Canonical service repair:** once the original scoped document and allocation locks are held, a non-null persisted typed operational collection key now fails closed *unconditionally*, before the posted idempotency return or manual GL branch, regardless of the caller's `requiresVerifiedCollectionSource` flag. Legacy allocations without typed collection evidence keep their original separate manual-AR contract. The new check uses the existing localized AR/EN pending-verification error and does not change any routes, roles, entitlements, audit writes, source invoice recognition, Customer/Driver/Van APIs or physical collection postings. It does **not** claim that operational cash may now be posted a second time after approval.

**Still source-incomplete:** legal Finance accounting recognition for invoice-linked AR, independently approved operational receipt consumption, reversible/refund-safe GL reconciliation, two-connection MySQL contention tests, cross-app authority, and production-equivalent acceptance remain unsatisfied. Keep original #2633/#3309 DRAFT/OPEN; final exact-head CI and technical risk audit are required after any commit. This single guard is source security correctness, not complete P303 acceptance; no main, tags, releases or deployment.
