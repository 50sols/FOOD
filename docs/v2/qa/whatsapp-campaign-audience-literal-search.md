# FOODEX V2 #3613 — literal, authorized WhatsApp Campaign audience picker

**Source:** P082 authorized View/Search; parent #2503, existing Campaign Builder #1438. **Integration:** `Foodex_V2` ONLY; never `main`, tags, releases, production, cPanel or APK publication.

## Source discrepancy and exact scope

`CampaignAudienceService::search()` accepted a validated free-text `q` and constructed a SQL `LIKE` pattern with implicit backslash escaping of percent/underscore. SQLite has no default SQL `LIKE` backslash escape, while MySQL/MariaDB may apply it differently. A user searching for literal `%`, `_`, `!`, or `\\` therefore received incorrect/no customer matches. This is a search-result accuracy bug **within the prior authorization boundary**, not evidence of SQL injection or tenant data exposure.

## Implementation

- **Reuse** Marketing & Communications → WhatsApp Campaign Builder → existing customer audience picker; route `GET /admin/whatsapp/campaigns/customers` (`admin.whatsapp-campaigns.customers`); no new menu/tab/form, no response payload change.
- Replace the implicit backslash escape with parameter-bound `LIKE ? ESCAPE '!'` on fixed `pc.name`, `pc.phone` and `pc.email` columns. Escape input `!`→`!!`, `%`→`!%`, `_`→`!_`; only the deliberate outer `%` are wildcards. A literal backslash is passed unchanged and remains literal under the explicit `!` escape.
- **Preserved:** `whatsapp.campaigns.view` endpoint permission; WhatsApp campaign capability middleware, 403/404; effective store scope `allowedStoreIds` and optional `assertStore`, channel and active/phone filters, name ordering and 50 cap, consent presentation and existing AR/EN/RTL/LTR responsive picker.
- No database schema/migration, MarketingConsent changes, campaign snapshot/send, inbound/replies, routes or notification changes.

## Executable acceptance

`backend/tests/Feature/V2WhatsAppCampaignAudienceLiteralSearchTest.php` uses `RefreshDatabase` with canonical roles/WhatsApp reference seeding and **real authenticated GET HTTP requests**:
- Positive Arabic text, ASCII email and historical phone values containing literal `%`/`_`/`!`; negative same-store lookalikes; ordinary English/phone and a backslash-containing name and search term.
- Super Admin target B2C store and authorized B2B Customer Service Supervisor; scoped store/role result visibility and channel exclusion; foreign B2C store 404, roleless direct request 403, WhatsApp emergency stop 404.
- No writes to `platform_customers` or `audit_logs` during authorized search.
- Required checks: PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel relevant/full tests, AR/EN, Repository Policy, V2 Isolation Gate, applicable MySQL/Redis, terminal exact-head Required CI Gate. Do not infer the CI outcome before inspecting Actions.

## App/runtime matrix

| Surface | Impact | Basis |
| --- | --- | --- |
| Backend | Affected | Existing internal campaign-audience search only |
| Dashboard | Affected | Existing picker lookup results, no UI/navigation modification |
| Customer | Not affected | No Customer public API/auth/DTO or Flutter files changed |
| Driver | Not affected | No Driver route/dispatch/Flutter changes; B2C = DRIVER ONLY |
| Van | Not affected | No Van route/stock/Flutter changes; B2B = VAN ONLY |

**Closure condition:** independent source acceptance if required, exact-final-head green required checks and child jobs, merge original issue-scoped PR with HEAD SHA guard exclusively into `Foodex_V2`, record source SHA, merge SHA and Actions links, then close #3613 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Staging/human UAT retained in #2503.