# V2 #3976 — Customer 360 scoped tab-pagination context

Parent: UX G009 #2750; V2 master #2503. PR #3977 targets **Foodex_V2 only**. FEATURE FREEZE: defect-only, no new screen/sidebar/API/permission/tenant/ledger.

## Source and placement

At `Foodex_V2@557d928f534a7ffb3d5609a36f980a76a12c1be9`, the canonical `/admin/customer-360/{platformCustomer}` Record 360 is implemented in `Customer360Controller::show` and `admin/customer-360-show.blade.php`. The authoritative UX matrix `docs/v2/qa/2750-screen-horizontal-tabs-classification.md` classifies this record page `KEEP_EXISTING_TABS`; the customer index and customer groups management are `NO_TABS_SINGLE_FLOW`.

Existing authenticated panels: **Finance** (credit snapshot/edit only where previously allowed), **Identity**, **Service** (bounded timeline/history), **Addresses**, **Retail stores**, **Orders**, **Invoices**, **Statement**, and conditional **B2B Reports**. This change does **not** invent a separate Overview, History or Credit authority/tab, nor grant reports to another persona.

Placement: Customer Accounts domain, existing `PAGE_TAB` record context; existing authorized Accounts/Customer 360 sidebar entry, controller, Blade, CSS and JS. Desktop/mobile and RTL/LTR retain existing horizontal tab rail, native keyboard and focus behavior. No new CSS, JavaScript, translation, layout or route.

## Reproduced source defect / bounded correction

Orders and Invoices independently paginate with `orders_page` and `invoices_page`, already using Laravel `withQueryString()`. Their rendered `links()` called paginator URLs without `#orders`/`#invoices`; browser navigation reloaded with the default Finance tab selected by existing tab JS, hiding the paged result.

Add Laravel `fragment('orders')` and `fragment('invoices')` to the two existing paginators. The URL path, existing query parameters, exact platform customer ID, and their authorized query scopes remain unchanged; no raw client tab or hash is ever used as permission. No other controller method, service or mutation is changed.

## Evidence and risk controls

Issue-scoped new HTTP regression: `backend/tests/Feature/V2Customer360TabPaginationContextTest.php`.
- Positive Store A: authenticated Retail Store Admin, customer detail existing route, AR/RTL and EN/LTR, existing tab buttons, and two independent paginator URLs preserving both page parameters plus both statement-date query parameters and the correct tab fragment.
- Negative Store B: foreign PlatformCustomer detail remains 404 even with forged pagination and tab fragment.
- No new financial record, role, scope, auth condition, statement/export route, invoice download, customer-group mutation or attachment state.

REVIEW_RISK: **LOW** for the two paginator URL-fragment append calls; HTTP test exercises existing tenant-denial boundary. If any behavior beyond link presentation changes, reclassify to HIGH. Source review must compare exact diff and ensure tenant filtering is untouched.

## Cross-surface impact

MOBILE IMPACT — Customer / Driver / Van: **NO_IMPACT** for all three apps: source change is Dashboard HTML paginator URLs only; no `api.php`, Flutter or server business-state/contract modifications. Wholesale VAN ONLY / Retail DRIVER ONLY remain unchanged.

## Acceptance state

Await **actual** exact-final-HEAD Required CI/Repository Policy/V2 Isolation and issue-scoped tests. No automated green result, browser screenshot, UAT or independent human review is claimed in this document. Any required UI runtime/keyboard/mobile visual audit not evidenced remains pending and must be treated as such. Only after tests and `TECHNICAL_REVIEW: PASS` may PR merge into Foodex_V2 and leaf close as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; remaining manual acceptance belongs to #2503.
