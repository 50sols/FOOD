# FOODEX V2 #3617 — WhatsApp Campaign recipients report / export literal search

**Authority:** P082 authorized View/Search, parent #2503; original WhatsApp Campaign Builder #1438 integrated. **Merge base Foodex_V2 only**, no main, release/tags, cPanel, production or APK publication.

## Independent source defect
Both `CampaignReportService::recipients` (50-row report pagination) and `CampaignReportService::exportRows` (CSV/XLSX export backing data) previously converted user query `q` into a SQL LIKE pattern using implicit backslash percent/underscore escaping. SQLite versus MySQL/MariaDB have different default behavior; literal `%` / `_` and a literal backslash can mismatch, resulting in report/export inconsistency. Existing `assertVisible` and store authorization remain intact; no cross-tenant exploit is claimed.

## Fix and placement
- **Reuse** Marketing & Communications → WhatsApp Campaigns → existing selected campaign Report page and its Context Export CSV/XLSX. No new page, Sidebar menu, permission, schema or UX.
- Parameter-bound `LIKE ? ESCAPE '!'` on three fixed columns: `pc.name`, `r.phone`, `pc.email` in BOTH paths. Escape input `!`→`!!`, `%`→`!%`, `_`→`!_`; use intentional outer `%` only. Literal backslash remains literal.
- Preserve `whatsapp.campaigns.view`, campaign `assertVisible`, store/channel/agent/consent/eligibility/status/time filters, 50/page ordering, original CSV/XLSX format and headers, existing AR/EN/RTL/LTR/responsive UI, no campaign/consent/reconciliation writes.

## Executable security and functional evidence
`backend/tests/Feature/V2WhatsAppCampaignReportLiteralSearchTest.php` exercises real authenticated HTTP report JSON and CSV endpoints with `RefreshDatabase`, seeded canonical roles/WhatsApp module:
- Positive Arabic/English and literal special character `%`/`_`/`!`, email and backslash; negative lookalikes, ordinary results and no-result search.
- Report `recipients.data`/total and CSV source agree on same filter, preserve targeted store and channel, never include other store.
- Roleless direct report/CSV 403, B2B supervisor denied B2C-only campaign, capability emergency stop 404. No audit or recipient mutations by authorized GET.
- Exact-head CI required: PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel full/appropriate MySQL/Redis, AR/EN, Repository Policy, V2 Isolation and terminal `required-ci-gate`. Skipped unrelated Flutter jobs must not be misrepresented as executed.

## Impact by product surface
| Surface | Effect | Contract |
| --- | --- | --- |
| Backend | Affected | Only report/export search query predicates |
| Dashboard | Affected | Same report/export pages and response schema; literal result accuracy |
| Customer | Not affected | No Customer public API, identity or Flutter change |
| Driver | Not affected | No Driver API, wallet/dispatch or Flutter change; B2C DRIVER ONLY |
| Van | Not affected | No Van API, fulfillment/stock or Flutter change; B2B VAN ONLY |

Close #3617 only after original issue-scoped PR is approved under applicable review policy, exact-final-head required checks pass and HEAD-SHA-guarded merge is verified into Foodex_V2. Record tested source SHA, integration SHA, Actions runs, deferred staging/human UAT under master #2503. Never claim production completion.