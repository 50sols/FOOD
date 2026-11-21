# #3877 — Dashboard invoice PDF failure: localized 503, fail closed

**Parent:** #2503 · **Target:** `Foodex_V2` only · **Risk:** HIGH (financial document HTTP handling).
**Scope:** `InvoiceController::download` error branch, new dedicated Laravel feature test, this QA record. No release/deployment authorized.

## Source defect and reuse
The authenticated Dashboard invoice download previously returned a fixed English HTTP 503 if the PDF renderer threw `RuntimeException`, even for Arabic Dashboard users and an explicitly Arabic invoice PDF download. The Customer API invoice route had already implemented bilingual behavior under #3772; its language keys `invoice_errors.pdf_generation_unavailable` in `backend/lang/{ar,en}` are reused unchanged here. No new translation file, new route, new sidebar item, or parallel PDF handler is introduced.

## Runtime contract and authoritative permissions
- Actor: authenticated Dashboard `User`, subject invoice identified by `admin.invoices.download`. The controller validates optional `locale=ar|en`, queries invoice, then calls the existing `authorizeInvoice` guard before starting the renderer.
- The original `finance.view` authorization and `OperationalTenantScope::allowedStoreIds` checks remain authoritative. Invoice's order provenance, store type and channel must agree; ambiguous, unscoped, foreign Retail or wrong-channel invoices return 404, even for privileged actors if their scope does not match.
- Only a caught PDF renderer `RuntimeException` becomes HTTP 503. The private exception message and stack are never included in response data; the localized error text comes solely from existing trusted translation keys. The HTTP status and default headers for successful PDF (`application/pdf`, attachment, private/no-store) remain unchanged.
- Failure text locale precedence: validated explicit PDF locale > supported authenticated user locale (`ar` or `en`) > supported HTTP Accept-Language > English fallback. This does **not** alter the existing successful PDF rendering locale or provide authorization through a locale choice.
- `app()->call` preserves the original rendering method while permitting an isolated deterministic renderer-failure injection in a real Laravel HTTP test. No PDF renderer implementation or error exception object is serialized.
- No write, audit log, invoice status/revision or payment activity occurs in this read-only download path.

## Automated acceptance
`backend/tests/Feature/V2AdminInvoicePdfUnavailableLocaleTest.php` seeds canonical references and exercises:
1. A deterministic failing renderer with unique private sentinel; exact Arabic/English HTTP 503 messages for explicitly overridden locale, supported account locale and browser-header fallback; the sentinel and exception type stay hidden.
2. Invoice state, counts and audit-log count remain unchanged after all failed downloads.
3. A B2B Dashboard actor cannot fetch a foreign B2C Retail invoice, including when an Arabic/English error locale is requested; authentic 404 occurs before PDF rendering.
4. Unsupported PDF query locale is rejected by Laravel validation (422 JSON), not silently downgraded.
5. Successful authorized download retains its `application/pdf`, attachment/private-cache contract and actual PDF content.

**Preflight commands (where local vendor/toolchain exist):**
```bash
cd backend
php -l app/Http/Controllers/Admin/InvoiceController.php
php -l tests/Feature/V2AdminInvoicePdfUnavailableLocaleTest.php
vendor/bin/pint --test app/Http/Controllers/Admin/InvoiceController.php tests/Feature/V2AdminInvoicePdfUnavailableLocaleTest.php
php artisan test --filter=V2AdminInvoicePdfUnavailableLocaleTest
php artisan test --filter=V2InvoiceReviewAuthorityTest
```
Then `bash ./scripts/worker-preflight.sh --ci-parity` from repo root and complete relevant **exact-final-head** GitHub Repository Policy, FOODEX V2 Isolation, Required CI terminal gate + all required child jobs. Do not infer green from a queued, failed, skipped-required or obsolete run.

## Backend, Dashboard, Customer / Driver / Van
| Surface | Impact |
| --- | --- |
| Backend | Dashboard invoice renderer error text only; unchanged invoice authorization, status, PDF success, database |
| Dashboard | Error message can now be Arabic or English; no new page, permission, menu or JS change |
| Customer | **Not affected** — separate `CustomerInvoiceController` API, DTOs and Flutter invoice flow untouched; pre-existing #3772 remains the source |
| Driver | **Not affected** — no driver API, wallet, dispatch, files or app source changes |
| Van | **Not affected** — no Van route, B2B dispatch, pricing, inventory or app source changes |

B2B = VAN ONLY, B2C = DRIVER ONLY, store/tenant financial scope and maker/checker remain unchanged. No mobile issue is necessary absent any mobile API or code delta.

## Risk review / failure modes for final PR audit
- **Authorization**: check that validation and `authorizeInvoice` still execute before calling renderer; the change cannot turn an invoice read into a global grant.
- **Disclosure**: catch only `RuntimeException`; use a static translation rather than untrusted exception detail. Unsupported/invalid locales must not become injection or fallback that changes access.
- **Data integrity**: no mutation, no settlement/post/reissue and no synthetic success on PDF failure.
- **Regression**: maintain legitimate bilingual PDF downloads and existing tests for store provenance and cross-channel 404.
- **Operational recovery**: a genuine unavailable renderer remains HTTP 503; no retry or mail/queue behavior added. No database migration or rollback needed; code rollback restores earlier English-only failure copy.
- **Independence**: same account audit is an engineering record, not an independent peer approval. Required GitHub protection and live acceptance are not waived.

**Acceptance status:** Implementation and tests must be verified on exact final PR SHA; keep this Issue OPEN until required CI is successful and the issue-scoped PR is merged only into `Foodex_V2`. Final manual/staging UAT tracked by #2503; no `main`, release, production or cPanel operation.
