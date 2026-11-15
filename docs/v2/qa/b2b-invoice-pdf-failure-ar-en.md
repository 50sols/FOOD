# V2 #3879 — Customer B2B invoice PDF failure HTTP503 AR/EN

**Integration:** `Foodex_V2` only. **Parents:** #2503, Customer B2B finance #2732/#2733 remain open for V2 consolidated acceptance. No main, live production, releases, tags or distribution.

## Business source and original failure
The existing authenticated `GET /api/v1/b2b/invoices/{invoice}/download` is owned by `B2bFinanceController::invoiceDownload`. After `approvedCustomer`, `ownedInvoiceQuery` enforces the authoritative B2B customer ID, null B2C FK, canonical B2B channel (or guarded legacy null), and Wholesale store ownership. The controller validates optional `locale=ar|en`, records `b2b.finance.invoice_downloaded` *before* rendering, invokes `InvoiceService::renderPdf`, and catches `RuntimeException`. The previous 503 message was fixed English, even for Arabic request/account locale. The distinct Customer canonical route #3772 was localized earlier; the Dashboard invoice route #3877 is another worker's scope. The completed B2B statement export #3851 explicitly excluded this separate PDF catch.

**Risk: HIGH** because the method sits on an authenticated financial document and tenant/privacy boundary, though this patch changes only error presentation and the tested renderer-invocation seam. The runtime exception's internals must never leave the server. Current English literal is preserved byte-for-byte via already merged `invoice_errors.pdf_generation_unavailable`.

## File-by-file technical/security review checklist

| File | Bounded source delta | Threats and rollback |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/B2bFinanceController.php` | In existing `invoiceDownload` only, dispatch `InvoiceService::renderPdf` with `app()->call` using the exact same Invoice and pre-existing validated PDF `$locale`, enabling deterministic container method-binding in tests. Catch the same `RuntimeException` and render existing `invoice_errors.pdf_generation_unavailable` in locale preference: validated query locale > supported saved User locale > supported Accept-Language > English. | Must not alter authorization, ownership/store/channel filter, HTTP 404/403/503, success PDF bytes/headers, invoice retrieval, business writes, idempotence or pre-render `b2b.finance.invoice_downloaded` audit; never expose PDF exception details. Revert the small catch/invocation delta to roll back without migration. |
| `backend/tests/Feature/V2B2bInvoicePdfUnavailableLocaleTest.php` | Real seeded B2B buyer+account+owned Wholesale invoice, genuine Sanctum HTTP route, test-only `bindMethod` throws a sentinel RuntimeException, AR/EN query vs user/header matrix incl unsupported saved locale, 503 exact message/no internal sentinel, financial/stock rows untouched, existing *one* download-attempt audit per failed render. Deny unauthenticated/unapproved/foreign/customer-channel invoice and bad query locale before invoking renderer. | No mocking of final service classes, no secrets in response, no weakened foreign-store/customer guards, no test-only production helper. |
| `docs/v2/qa/b2b-invoice-pdf-failure-ar-en.md` | Security/audit review, exact CI evidence and app impact register | Documentation only. |

Already existing paired language keys `backend/lang/{ar,en}/invoice_errors.php` must be **reused unchanged**; no duplicate translations or extra keys.

## Authorization, negative and positive acceptance

- Approved, authenticated B2B buyer and owned Wholesale invoice: simulated renderer failure returns existing HTTP503 JSON `message` in correct AR/EN, does not expose exception text, and does not mutate invoice, ledger, order, payment, stock or other business records. Preserve the pre-existing **single** `b2b.finance.invoice_downloaded` audit record per failure attempt (do not falsely claim no Audit write).
- No authenticated account: 401. Foreign invoice: 404. Pending/unapproved B2B account: 403. Contradictory B2C/channel data: 404. Unsupported explicit locale: 422 before renderer/audit. Any bypass is a release blocker.
- Existing `B2bFinanceTest` tests must continue to prove real successful PDF bytes/headers, B2B finance summary and authorized invoice routes. Existing #3851 statement-export tests remain unchanged and green.
- No new migrations or data transformations. Rollback is purely source-level via reviewed PR without unwinding financial documents.

## Surface / mobile classification

- **Backend / API:** B2B Customer invoice PDF 503 human-readable error selection only; same endpoint URL, status 503 and JSON response envelope, success binary PDF, account-store-channel authorization and audit.
- **Customer:** Existing B2B invoice download client may display the correctly localized server error. No Flutter, DTO, navigation, transport, user-visible success UI, app build or route change.
- **Driver:** NOT AFFECTED; no B2C DRIVER ONLY lifecycle or API change.
- **Van:** NOT AFFECTED; no B2B VAN ONLY dispatch/stock/workflow/Van API change.
- **Dashboard:** NOT AFFECTED; separate Dashboard invoice method #3877 is a different issue/worker.

## Release evidence register (final exact PR HEAD only)

- [ ] Focused seeded HTTP regression and existing B2B finance positive tests, full Laravel suite
- [ ] PHP syntax, canonical Pint, PHPStan, Composer security/OpenAPI, localization AR/EN
- [ ] MySQL/Redis real fresh-install/upgrade/recovery CI
- [ ] Repository Policy terminal SUCCESS, V2 Isolation terminal SUCCESS, FOODEX Required CI including child `required-ci-gate` SUCCESS on exact latest SHA
- [ ] Separate same-account file-by-file `TECHNICAL_REVIEW: PASS`, `REVIEW_RISK: HIGH` COMMENTED audit, never misrepresented as independent GitHub APPROVED
- [ ] SHA-guarded one-PR merge `Foodex_V2` ONLY, verified integration SHA and branch cleanup; close this leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only after source/CI integration; master #2503 remains open

No production, main, tag, release, APK or ZIP operations are authorized by this issue.
