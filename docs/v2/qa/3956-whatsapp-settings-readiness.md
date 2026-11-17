# V2 #3956 — WhatsApp Settings configuration/readiness acceptance

- Source: #3956 / UX G009 #2750 / #2503. **Foodex_V2 only; FEATURE FREEZE.**
- Existing owner: Administration Hub → WhatsApp settings and connections; canonical GET `/admin/settings/whatsapp` and `/admin/settings/whatsapp/connections`.
- Placement: **Reuse → Extend**. Domain Communications; entity global WhatsApp provider profile; actor `whatsapp.view` reader / `whatsapp.settings` editor; Super Admin for credential replacement, primary switch and emergency/lifecycle transitions. Navigation type `ADMIN_SETTINGS` + existing page-level tabs; no new URL path, sidebar entry, entitlement, permission, business feature or phone-message operation.
- Responsive/locale: existing FOODEX shell, `.foodex-tabs` single horizontal strip, native URL anchors with keyboard Tab/Enter and focus-visible, **only the server-selected pane is emitted in HTML** on either existing route, AR/EN and page dir RTL/LTR. Narrow mobile scroll is constrained to shared tab strip. No JS-generated authority; inactive pane data/forms are not emitted and panel selection does not grant POST authorization.
- UI state: page reads existing server `RuntimePolicy`, profile `status`, `health_status`, real validator timestamps and durable transport counters. An unvalidated Draft is not called Ready; no guessed provider connectivity. Credentials stay password inputs, are never printed as sample or re-rendered into form controls.

## User configuration map

| Value | Class | Existing source / operator guidance | Validation and safe default | Permission / readiness |
|---|---|---|---|---|
| Profile name, display phone, international phone | Required name/display, optional normalized | Operator's approved WhatsApp Business number; E.164 country-code format | Name 120, phone 32, normalized 24; normalized derives from displayed number when absent and server validates | `whatsapp.settings`; new record Draft |
| Provider type | Required, preconfigured default | Meta WhatsApp Cloud API; `meta_cloud` default | Server regex (letters/numbers/_/-), 40 char cap; actual registry supports Meta aliases only; unsupported provider fails during real provider call | Save is not certification |
| Phone Number ID, WhatsApp Business Account ID, App ID | External, optional | Meta WhatsApp Manager and Meta for Developers, **not internal FOODEX DB IDs** | Max 128 each; blank when Meta has not issued the value; do not invent values | Provider Test required for real readiness |
| Provider access token, webhook verify/signing secrets | External, optional at Draft creation | Authorized Meta app administrator; entered only as passwords | Max 10,000; blank does not rotate an existing encrypted secret; no secrets in help/release/artifacts | Existing Super Admin gate for credential replacement, `whatsapp.settings` on create |
| Provider/webhook advanced metadata | Preconfigured/internal | Existing stored structured metadata in authoritative connection profile | **No business-facing raw JSON editor**; omission on update preserves prior maps from the **transactionally locked record**. Legacy explicit JSON API validation/secret-key prohibition remains | Same manager audit/revalidation logic; no silent reset |
| Capability flags, lifecycle, emergency state, test numbers | Configured/preexisting | Existing WhatsApp module controls; `RuntimePolicy` and `StateManager` authoritative | Existing server enums, revision and confirm guards unchanged | `whatsapp.settings`; Super Admin on privileged controls; never activate just by choosing a tab |

### Server-side tab isolation and CI regression contract

- For `/admin/settings/whatsapp/connections`, `profiles`, `health`, and `create` now use **exclusive Blade `@if` branches**. Default/malformed/array `tab` shows Profiles only. Create is never selected for a viewer lacking `whatsapp.settings`; no hidden Create password form, profile list, or health pane exists in an inactive pane's response.
- For `/admin/settings/whatsapp`, only Readiness or Controls is returned, never both. An unavailable runtime forces Readiness and does not return control forms. Explicit View/Settings/Super Admin HTTP checks and mutation-side SoD still govern access; hiding an inactive panel alone is not authorization.
- HTTP feature tests assert response-HTML **absence**, not only CSS `hidden`, across EN/AR, invalid tabs, Create/Profiles/Health, module Readiness/Controls and unsafe input. Actual provider Test is still required, and its failure does not create a Ready status. The bilingual provider-test regression uses separate nonproduction phone numbers per locale so test records obey the canonical provider+normalized-number UNIQUE constraint, not a relaxed schema.

### Validation context and protected provider diagnostics (Device D continuation)

- Existing **Create** Save uses the original POST and same validator; rejection now redirects to `/admin/settings/whatsapp/connections?tab=create`, not a guessed referer or unrelated screen. Restore only string-valued public name/phone/provider fields, each bounded by its exact validator max; malformed arrays, provider/webhook metadata, credentials and webhook secrets are **never flashed into the session**.
- Existing **Edit** and **Replace Secret** failures return to the preexisting Profiles panel with validation errors but no `_old_input`; rejected secret rotation/metadata must not persist or change revision. Successful Create still lands on the saved Profiles panel and is **Draft**, not provider-certified.
- Existing **Test** connection action still invokes the actual provider validator and never blesses an untested Draft. A failed real **Send Test Message** now exposes only `whatsapp.connections.test_send_failed` in AR/EN and never interpolates raw provider/transport exceptions into HTML or flashed input. Existing ConnectionHealthRecorder retains sanitized diagnostics; success is not forged.
- New HTTP regression tests in `V2WhatsAppSettingsReadinessTabsTest` cover EN/AR invalid Create, safe tab retention and public-field retention, no flashed secrets/legacy JSON, failed credential rotation and legacy metadata rejection with zero mutation, and provider test failure with generic localized error and no delivery success.
- **Readiness:** externally verified WhatsApp Business phone ID/account/app, approved token, webhook ownership, queue/worker health, consent and authorized Meta live Test remain `EXTERNAL / NOT_READY` until real provider confirmation. No credentials, personal recipient, secret or signed trust key is seeded or invented.

## Security/QA evidence and limitations

- Existing controllers decide `whatsapp.view`, `whatsapp.settings`, active user, Super Admin; `ConnectionProfileManager` transaction/locking/secret masking/audit is preserved.
- Strict scalar tab allowlists mean malformed `tab[]` cannot select a privileged/create panel. Tab navigation changes **rendering only**, not server route/POST action, provider state, queue or credentials.
- Existing provider metadata is updated only when explicitly supplied by a trusted caller; omitted form fields do not clear metadata and the locked-row values win over stale snapshots. Existing explicit JSON validator rejects malformed payloads and manager rejects secret-looking metadata keys.
- Verification path in `backend/tests/Feature/V2WhatsAppSettingsReadinessTabsTest.php`: English/Arabic, LTR/RTL, valid/invalid/array tabs, no JSON editor, metadata persistence after save, true Draft/unvalidated state, unauthorized and inactive user denial. Existing `WhatsAppConnectionProfileTest` covers provider validation, encryption, health and audit.
- Customer App: **NO_DIRECT_IMPACT** — no public/mobile API contract, credentials, runtime customer UX or consent change.
- Driver App: **NO_DIRECT_IMPACT** — no B2C dispatch/message API, roles or Flutter change.
- Van App: **NO_DIRECT_IMPACT** — no B2B Van path, role or Flutter change.
- Risk: **HIGH** because profile configuration and credential-adjacent UX; independent runtime/browser verification (320/360/390/412/430px, screen-reader focus) still requires test evidence. This scoped PR does not certify Meta service access, webhook provisioning, ERP company/tax/stock-owner settings, or full V2 launch readiness.
- Source completeness and integration are **not claimed** until exact-final-head Required CI, Repository Policy, V2 Isolation and technical review are all green/passed and the original PR is merged exclusively into `Foodex_V2`.
