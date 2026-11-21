# V2 #3772 — Customer invoice PDF HTTP 503 AR/EN fallback

## Isolated scope

Target `Foodex_V2` ONLY. Source issue #3772; parent #2503 and Customer invoice parity #2733. No main, production, release, tag or live account mutation.

- Existing entry point: `GET /api/v1/invoices/{invoice}/download` through `CustomerInvoiceController::download`.
- Existing Sanctum/active-user, owned invoice query, store/channel context, and PDF response remain unchanged.
- Only `InvoiceService::renderPdf` `RuntimeException` fallback gains paired AR/EN error copy `invoice_errors.pdf_generation_unavailable` while keeping status HTTP 503.
- Error locale precedence: explicit *validated* `locale=ar|en` query, then supported authenticated `User.locale`, then negotiated `Accept-Language` `ar/en`, otherwise English. The existing PDF rendering locale argument and output headers are unchanged. PDF rendering is invoked through Laravel's container method call with the same arguments so a controlled failure can be injected into the HTTP test without removing the `final` class boundary.
- Internal exception details, server path, dependency names and runtime errors must never leak to the client. No new API field or response status. No invoice/account/tenant mutation.

## Exact source-to-test map

| Scenario | Test | Expected |
| --- | --- | --- |
| Valid owned B2C invoice, PDF renderer throws RuntimeException, explicit Arabic or English query | `V2CustomerInvoiceStoreContextTest::test_owned_pdf_failure_is_localized_and_does_not_reveal_runtime_details_or_mutate_invoices` (Laravel `bindMethod(InvoiceService::class.'@renderPdf', ...)`) | HTTP 503; exactly localized JSON message per validated query locale, regardless of account/header |
| No locale query, supported account ar/en disagreeing with Accept-Language | same | Account locale wins |
| Unsupported account locale with ar-KW / en-US | same | Supported negotiated message |
| Sensitive RuntimeException sentinel | same | No sentinel in HTTP response |
| Every failing request | same | No invoice status, channel, store or count changes |
| Successful PDF, correct store/channel, RTL PDF | Existing `V2CustomerInvoiceStoreContextTest::test_owned_invoice_show_and_pdf_support_valid_or_omitted_store_context` | 200 application/pdf; unmodified |
| Foreign customer, wrong store/channel, malformed context | Existing `V2CustomerInvoiceStoreContextTest` negative tests and `V2CustomerInvoiceProvenanceGuardTest` | 404; no invoice disclosure |

## Surface and mobile classification

- Backend/API: existing HTTP 503 human-readable message only; no route/DTO/authorization changes.
- Dashboard: NOT affected (no admin PDF endpoint or UI change).
- Customer: affected existing error copy only, with no Flutter screen, API schema, transport, auth or permission change. Contract remains HTTP 503 `message`.
- Driver: NOT affected — driver invoice route/flows unchanged.
- Van: NOT affected — B2B Van dispatch/order/collection paths unchanged.
- Manual mobile, responsive, accessibility and final staging business acceptance: tracked under existing master #2503 and invoice parity #2733, not misreported as verified by this code-only change.

## Required audit and gates

`REVIEW_RISK: HIGH` due authenticated invoice identity, tenant/store and data disclosure boundary. In a separate PR COMMENTED technical audit, review controller exception catch only, paired catalog, fail-closed method-binding test seam, no-write and denial/positive tests; this same-account audit is not an independent GitHub review. Required checks on the exact final PR HEAD: Repository Policy, FOODEX V2 Isolation, AR/EN, PHP syntax/Pint/PHPStan/Composer/OpenAPI, full Laravel and MySQL/Redis installer/upgrade/recovery, and terminal `required-ci-gate` SUCCESS. No merge/closure on pending, failed or skipped required checks; merge solely to `Foodex_V2` and retain master #2503 OPEN.
