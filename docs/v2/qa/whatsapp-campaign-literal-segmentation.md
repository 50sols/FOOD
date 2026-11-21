# V2 #3645 — WhatsApp Campaign literal recipient search

- Owner: original issue #3645; master acceptance #2503.
- Source: `CampaignSegmentationService::customers` in existing WhatsApp Campaign recipient-selection flow.
- Integration: **Foodex_V2 ONLY**. No main, production, tag, release, APK or cPanel deployment.

## Root cause, behavior and bounded fix

The previous `str_replace(['%', '_'], ['\\%', '\\_'], $search)` assumed a backslash escape for SQL `LIKE`. SQLite does not implicitly use that syntax; MySQL/MariaDB and SQLite cannot reliably agree without an explicit escape declaration. As a result, typed wildcard symbols could match incorrectly or fail to match literal customer text.

The three *fixed, non-user-controlled* fields (`pc.name`, `pc.phone`, `pc.email`) now use parameter-bound `LIKE ? ESCAPE '!'` and escape the marker `!`, the wildcard `%`, and single-character wildcard `_`. A backslash is literal. The user term remains a **substring** (not prefix) search. No dynamic SQL identifiers, permissions, tenant predicates or send behavior are changed.

## Authorization, tenant and data-integrity boundaries

This is an internal service used by the existing Campaigns workflow, not a new public HTTP route or permission gate. Caller authentication, marketing consent, test allowlist and final-send suppression remain authoritative and unchanged. Existing `origin_store_id` and `origin_channel` filters remain outside the nested OR grouping so a search cannot replace or bypass them; inactive or phone-less platform customers remain excluded. There are no writes, new tables, new UI controls or new localized strings.

Risk: **HIGH** — recipient selection/customer PII. Review each of the 3 scoped paths, compare before/after scope and query binding, inspect SQL portability and negative tests, verify end-to-end CI; this technical audit must not be misrepresented as an independent human approval.

## Verified-test plan

`backend/tests/Feature/V2WhatsAppCampaignLiteralSegmentationTest.php` uses `RefreshDatabase` and the production service with real platform customer/store fixtures. It covers literal percent, underscore, exclamation marker, backslash, email, phone, Arabic/English and normal lookup; false-positive exclusion, B2B/B2C and Retail store constraints, inactive exclusion and unchanged customer, consent and campaign row counts. Execute this test under SQLite plus full required Laravel/MySQL CI, Pint, PHPStan, security, AR/EN and exact-head GitHub policy gates. These are requirements, not claims of runs already passed.

## Mobile impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Dashboard/Backend | Affected | Internal existing campaign segmentation search only, no new route or UI |
| Customer Android | Not affected | No Customer API/DTO, screens, authentication, purchase or Flutter change |
| Driver Android | Not affected | No Driver API/DTO, dispatch, wallet or Flutter change |
| Van Android | Not affected | No Van API/DTO, routing/stock or Flutter change |

B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged. Manual campaign UAT is tracked by #2503 after this atomic leaf's code-only gates. `#2503` remains OPEN. Closure requires original PR merged exclusively to Foodex_V2 and source/CI/integration SHA evidence.