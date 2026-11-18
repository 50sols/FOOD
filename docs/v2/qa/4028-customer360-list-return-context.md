# V2 G009 #4028 — Customer 360 directory ↔ record authorized context

## Source-backed defect and placement
- Initial source `Foodex_V2@1eacd4a925802e22abd36a67d4d8712b60496449`. Parent #2750 / #2503. Current V2 scope is **Laravel ERP / existing web Dashboard**; mobile Flutter work is deferred.
- Existing `GET /admin/customer-360` lists scoped PlatformCustomer records using seven validated filters, 25-row Laravel `withQueryString()` pagination. Its exact-record **Open 360** link previously omitted all filters/page, and the record's three Breadcrumb/Back links returned to an unfiltered directory. The loss is reproducible from the original Blade and `summaryRow()['url']` source, independent of JS.
- Canonical placement: **Reuse → Extend** already authorized `admin.customer-360.index` and `admin.customer-360.show`; `CONTEXT_ACTION` for directory→record/back. One record's `PAGE_TAB` bar already renders Finance including real B2B credit, Identity, Service timeline (history), Addresses, Stores, Orders, Invoices, Statement and conditional B2B Reports. **KEEP_EXISTING_TABS**; do not add fake Overview/History/Credit data or separate tabs.
- Customer groups remain a separate scoped management page with dependent create/membership/bulk forms; no artificial steps/tabs. B2B Account credit entries, finance ledger, statement rows, PDF/XLSX exports and customer-service attachments/sources remain authoritative existing routes and service scopes, not new datasets.

## Allowed source delta / tenant controls
| Source | Change |
| --- | --- |
| `Customer360Controller::index` | Prefix **only validated** directory `q,origin_channel,registration_source,active,origin_store_id,date_from,date_to` as `list_*`, add `list_page` only from Laravel paginator's currentPage >1, expose to view |
| `customer-360-index.blade.php` | Exact-record link carries these same `list_*` query values; no new route, Sidebar or extra authority |
| `Customer360Controller::show` | First authorize the exact PlatformCustomer through existing `findVisible`, then validate `list_*` using same scalar/value/date contracts. Before constructing Back URL, require `list_origin_store_id` to be within actor's `origin_store_ids`; reject foreign store HTTP 404. Strip `list_` prefixes from validated values only; fixed index route, **never a free-form external redirect** |
| `customer-360-show.blade.php` | Replace only the three existing directory Breadcrumb/Back destinations with server-validated `$backToListUrl`. Keep `list_*` as hidden GET inputs when applying Statement date range; no change to export links/formats or statement query |
| `V2Customer360DirectoryReturnContextTest.php` | New isolated Laravel HTTP test in AR/EN and RTL/LTR, literal `%_!` name search, correct record/link and Back filter context, direct `list_page` 3 + Orders/Invoices page and Statement range, foreign Store B/Customer 404, ignored `return_url`, GET no writes, existing tab/financial forms and PDF/XLSX URLs unchanged |

- Existing `orders_page` and `invoices_page` Laravel paginators use `withQueryString()->fragment('orders'/'invoices')`; native `pushState`/Back/Forward/RTL keyboard tab behavior is retained verbatim from merged #3976 PR3977 and #3998 PR3999. The new return context is namespaced to avoid colliding with those record pagination keys.
- Only authorized Customer 360 record paths are rendered; a visible Store A customer cannot be used to construct a Back link to foreign Store B. Current `list_*` fields never authorize source records, exports, credit changes, order/invoice actions or cross-tenant data. No new `support_access` or arbitrary `return_url` handling.
- **No POST changed.** Existing successful/error redirects for financial and customer-account mutations remain original controller/service behavior; this PR only preserves list scope through GET navigation. Source must not be represented as E2E financial/workflow acceptance.
- **No Direct Mobile Source Impact** independently for Customer, Driver and Van: changed files are two existing Laravel Blades, one Dashboard controller navigation context, one new test and this QA record; no Flutter, shared API, B2B VAN ONLY or B2C DRIVER ONLY behavior change. No mobile test-pass claim.

## Risk / tests / release governance
- `REVIEW_RISK: HIGH` due to customer privacy/tenant-bound navigation despite presentation-only intent; file-by-file source audit, positive + negative direct HTTP tests and AR/EN+RTL/LTR required. Verify no unrelated source edits, preserved strict legal owner/store/permission and no GET ledger/audit writes.
- Verify Laravel feature tests, PHP syntax/Pint/security, Repository Policy, FOODEX V2 Isolation and all **Required CI** checks on exact final PR SHA; actual MySQL/Redis acceptance and runtime browser viewport tests must be evidenced, not assumed. `TECHNICAL_REVIEW: PENDING` until these gates.
- Parent #2750 UX gate and #2503 master remain OPEN. Do not merge original PR if any required check is red/pending or if source/test scope is incomplete. No main, tags, releases, cPanel, production or Flutter work.
