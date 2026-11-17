# V2 #3955 — WhatsApp Campaigns horizontal workspace tabs

Issue #3955; owner of canonical `/admin/whatsapp/campaigns`. Parent G009 #2750 and master #2503. Base `Foodex_V2` only. **FEATURE FREEZE: existing layout remediation, not business capability development.**

## Source-to-screen placement / related WhatsApp surfaces

| Existing surface | Actual user operation / source | Placement decision for this issue |
| --- | --- | --- |
| WhatsApp Inbox | `admin/whatsapp-inbox.blade.php`: conversation list + selected thread + customer context are simultaneous, live-message panes. Existing status filters and compose quick/note controls. | **KEEP_EXISTING_TABS** for status; **NO_TABS** between simultaneous conversation/thread/context. Preserve auto-scroll, quick replies, reply/notes, assignment and send logic. **No file change.** |
| Campaigns | `admin/whatsapp-campaigns/index.blade.php`: operations metrics, bounded campaign list, independent template registry, all under existing view-permission gate. | **CONVERT_TO_HORIZONTAL_TABS** for three independently revisitable siblings within same authorized canonical GET route; preserve old element anchors, URL query context, AR/EN, keyboard and mobile horizontal scrolling. |
| Campaign builder / audience sources | Same Campaigns file: create form's five ordered steps, existing/import/manual audience picker, preview eligibility/consent before submit; creates draft or explicitly send/schedule. | **NO_TABS_SINGLE_FLOW** for the five-step dependent process. Builder remains outside page panels and its existing `wa-create-campaign` and `wa-close-builder` handlers are unchanged; opening/closing workspace tabs does not reset form, `selectedIds`, `selectedStores`, `lastPreview`, or perform POST. Inner source picker remains existing UI. |
| Template registry | Same Campaigns file: independent active-template table, Meta template modal and sync button; template selection inside builder is a separate concern. | **PAGE_TAB** Templates for registry only; Meta create/sync, provider status, draft/send eligibility unchanged. Template creation remains modal. |
| Chatbot / bot settings | `admin/whatsapp-chatbot.blade.php` already exposes `cb-tabs`; separate POST publish/rollback and runtime policy controls. | **KEEP_EXISTING_TABS**; no bot flow, draft/publish or AI settings changes. |
| WhatsApp provider foundation/connections | `admin/whatsapp-foundation.blade.php`, `admin/whatsapp-connections.blade.php`: module lifecycle/readiness, credentials and real test send. | **WAIT_FOR** original distinct #3956; do not touch credential/activation/test-send or duplicate an owner. |
| Marketing and advertising | Existing Admin navigation destinations with their own authorization, not a new WhatsApp Sidebar family. | **KEEP_EXISTING_ROUTES**; no navigation tree or cross-domain tab. |
| Notifications / notification campaigns | `admin/notifications.blade.php` and `admin/notification-campaigns.blade.php` include draft/review/publish/schedule and list filters as separate workflows with explicit POST decisions. | **NO_TABS_SINGLE_FLOW** for approval/review; no change to publish or audience state. |
| Live ads | `admin/live-ads.blade.php` has create/filter/edit/toggle/delete with existing backend contracts. | **KEEP_EXISTING_ROUTE**, no tab/activation/publish change. |

Placement: **Reuse → Extend** existing `.foodex-tabs/.foodex-tab`, not a new UI system. Domain WhatsApp Campaigns, entity campaigns/templates, role view authorizer, goal review metrics/list/templates, page-tab only, no Sidebar/navigation changes. Existing direct links `#wa-campaign-list` still activate the list; new `#wa-template-registry` and `#wa-operations-title` activate siblings. The builder button remains a context action, not a workspace tab.

## Authority and context invariants

- Existing `WhatsAppCampaignController::index` checks `whatsapp.campaigns.view` and the existing WhatsApp capability middleware first; list visibility remains `CampaignReportService::assertVisible` per campaign; Store dropdown remains sourced from `OperationalTenantScope::allowedStoreIds`. **Controller, service, routes, permissions, responses, SQL, Sidebar, localization files unchanged**.
- Server initial panel selection reads only `workspace_tab` with strict scalar allowlist (`campaigns/templates/overview`). Missing, unknown, or array selection safely selects `campaigns` without casting arrays or affecting store scope. This is presentation-only, not permission authority.
- Three server-rendered regions exist for existing authorized GET; exactly one visible (other panels have native `hidden`). Existing `campaigns` and `templates` table metadata and report drillthrough are unmodified. JS tab click updates `aria-selected`, focus, `hidden` and **only** the `workspace_tab` URL query via `history.replaceState`; no navigation/reload, form reset, asynchronous fetch, send, consent, draft/publish, or store selection change. Query remains intact; hash deep links activate correct pane.
- Keyboard Home/End, ArrowLeft/ArrowRight reflect RTL direction; 44px focus-visible horizontal strip fits phone widths 320/360/390/412/430 and desktop without wrapping. Browser/manual acceptance still owned by G009 #2750; static and Laravel tests do not prove visual UAT.
- Existing `@if($canManage)` and `@if($canSend)` authorize create, import, preview, sync, send, schedule, pause, cancel and draft choices exactly as before. Tab selection does **not** authorize restricted actions. Enforced consent and template review remain in existing server paths.
- **No API/Flutter source diff**. Customer / Driver / Van independently **NO_DIRECT_IMPACT**; their notification/send/readback/dispatch contracts are not changed. B2B VAN ONLY and B2C DRIVER ONLY remain untouched.

## Test / risk audit

- Dedicated `V2WhatsAppCampaignWorkspaceTabsTest`: real authenticated GET AR/EN RTL/LTR, all three panels and link query retention, selected panel vs two hidden, builder + template form retained, malformed array/unknown fallback, roleless 403, inactive actor 403 (existing permission gate), disabled WhatsApp module 404, DB campaign/recipient/template/consent/message counts unchanged.
- Existing campaign send/consent tests remain authoritative: `WhatsAppCampaignTest`, `WhatsAppCampaignBuilderTest`, `V2CampaignsRuntimeNavigationContractTest`, `V2WhatsAppCampaignAudienceLiteralSearchTest`. Their success must be verified, not inferred.
- Scope file fence: one existing Blade + one new dedicated Feature test + this new QA record. Do not edit shared sidebar/nav, modules, middleware, sales/marketing ads, notifications, bot, controllers, secrets or global CSS.
- REVIEW_RISK: **HIGH** (message sending/consent-adjacent UI); TECHNICAL_REVIEW: **PENDING_EXACT_HEAD_CI** until final checked head and formal PR review. Verify Repository Policy, V2 Isolation, required-ci-gate, Laravel full suite, AR/EN, security, code style. Do not merge or close on pending/red/unknown checks.
- Defer integrated browser keyboard/viewport and staging/provider delivery UAT to #2750/#2503 without claiming completion. No `main`, production, tag, release or cPanel action.
