# V2 #3687 — literal scoped Live Tracking lookup prefixes

**Source issue:** #3687; related legacy #1635 and V2 master #2503.
**Integration:** Foodex_V2 ONLY. No main, tag, release, cPanel, APK, or deployment.

## Canonical placement and authority

- Domain: Driver/Van live dispatch tracking; **Reuse** canonical Dashboard
  route \`GET /admin/driver-live-tracking\`, visible Sidebar Live Tracking.
- Existing combobox endpoint: \`GET /admin/driver-live-tracking/lookups\`
  with \`kind=entity|driver|van|order|route\`.
- Actor: existing \`drivers.tracking.view\` permission and authoritative
  \`OperationalTenantScope\` per store/channel. Server rejects unauthorized
  roleless access with HTTP 403; selected unowned store results in 404.
- Navigation: unchanged Sidebar entry, parent Live Tracking page, existing
  lookup keyboard/combobox behavior and hidden ID fields.
- AR/EN, RTL/LTR, desktop/tablet/mobile-web responsive shell, focus handling,
  unchanged JSON shape and limits; no additional visible field or label.
- Existing exact \`#id\` order lookup still resolves by the displayed numeric ID.

## Verified problem and fix

Original \`likePrefix\` prepended backslashes before SQL LIKE \`%\` and \`_\`.
That is not portable between SQLite, MySQL SQL modes and explicit SQL ESCAPE
handling. A user typing punctuation in a Driver name, Van code/plate,
order number, route key or territory prefix could see missing or unrelated
**already-authorized** options. This is not SQL injection or proven new
cross-tenant exposure; the server had separate store/channel guards.

The original four lookups and the combined Entity picker now share one
literal-prefix policy:

- \`!\` is escaped as \`!!\`, \`%\` as \`!%\`, \`_\` as \`!_\`.
- A typed backslash stays literal. A single, intentional trailing \`%\`
  implements prefix matching (not arbitrary substring matching).
- All fixed, qualified name/email/code/number/territory columns use
  parameter-bound \`LIKE ? ESCAPE '!'\` inside their original authorized
  grouped WHERE expressions.
- Empty/whitespace query continues to return the existing bounded result.
- Order \`#id\` match, store/channel scope, sort and limits remain unchanged.

## Automated acceptance and data protection

\`DriverLiveTrackingDashboardTest::test_live_tracking_lookup_prefixes_preserve_literal_special_characters_and_store_channel_scope\`
uses real \`RefreshDatabase\` and \`CoreReferenceSeeder\`, creates scoped
B2C Driver/order and B2B Van/route records containing \`%\`, \`_\`, \`!\`
and backslash, plus a foreign Wholesale Van and route, and verifies each
literal prefix returns exactly its own record. It exercises the combined
entity picker, empty q, numeric order \`#id\`, Arabic response, roleless 403,
foreign-store exclusion and unchanged orders/location/audit row counts.
Existing Live Tracking page AR/EN, navigation and accessibility regressions
remain in their original test class. No schema or write-path changed.

## Risk-classified file audit and app impact

\`REVIEW_RISK: HIGH\` because Driver/Van location lookups may expose names,
emails, store identifiers and route labels. Scope, authorization and failure
mode must be reviewed file-by-file and on terminal exact-head CI before merge.

1. \`DriverLiveTrackingDashboardController.php\`: fixed parameter-bound LIKE
   expressions only, same auth and store/channel restrictions, existing
   result sizes, no new joins/metadata.
2. \`DriverLiveTrackingDashboardTest.php\`: positive and negative HTTP,
   locale, ownership and no-side-effect validation.
3. This QA document: canonical placement, abuse scenarios and traceability.

**Customer app:** no change; no public API, Flutter or customer navigation.
**Driver app:** no change; server-rendered Admin search only; dispatch status,
GPS and B2C fulfillment unchanged.
**Van app:** no change; no Van API/Flutter, route execution, or B2B fulfillment
change. B2B = VAN ONLY, B2C = DRIVER ONLY retained.

Independent human/staging visual UAT deferred under #2503 after a complete
source PR passes all exact-head required checks. Never claim success based
on draft PR creation alone.
