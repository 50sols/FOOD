# V2 #3914 — Sidebar storage-denial resilience

## Canonical placement and ownership
- Parent: Sidebar UX gate #2750; master #2503. Scope is an atomic, source-complete Dashboard Sidebar usability fix, **not** closure of the 177-node program.
- Domain: Administration → navigation. User goal: reach already-authorized business links from desktop or mobile even when browser preferences cannot be stored.
- Existing route/controller/navigation owner: all existing Dashboard routes through `AdminNavigation`; canonical renderer `admin._sidebar`. **REUSE** the existing inline Sidebar script and mobile drawer; no new route, permissions, sidebar item, tenant context or module flag.
- Navigation type: SIDEBAR (existing). Desktop: group toggle/search; mobile 320/360/390/412/430: 44px launcher, off-canvas modal, Escape/backdrop, focus management; 768/1024/tablet: existing viewport sync; AR/RTL and EN/LTR use the same script and current localization keys, no translations changed.
- Security: authorized links are always generated server-side. Storage cannot grant access or remove the server gate. Denied browser Storage falls back to nonpersistent UI preferences (not to unknown links); normal per-user preference persistence retained.

## Before → after
- Before: direct initial `localStorage.getItem(stateKey)` throws SecurityError in privacy/embedded browsers and aborts Sidebar JavaScript before the mobile launcher and search/keyboard listeners are registered. Desktop `localStorage.setItem` also throws on a user toggle.
- After: `readPreference` and `writePreference` catch denied property getters, blocked reads and denied/quota-full writes. All preference operations use the helpers, preserving the same preference names and values; no new storage location.
- Group state malformed JSON still falls back to default visibility. Existing aria-modal, Escape, search and per-user preferences are unchanged when storage is available.

## Executable regression and failure cases
- `node backend/tests/Fixtures/sidebar-storage-denied.cjs`: executes the actual Blade inline JS (compiled with inert locale/user fixture substitutions) in Node `vm` and checks:
  1. Denied `localStorage` property getter: Sidebar initializes, desktop toggle/search event handlers operate.
  2. Denied `getItem`/`setItem` methods: same behavior; search empty-state is functional.
  3. Mobile: launcher opens dialog with aria-modal and focus on search; Escape closes it and restores prior focus even when Storage throws.
  4. Normal Storage: same per-user expanded state is persisted and restored on simulated reload.
- `php artisan test --filter=V2SidebarStorageDeniedResilienceTest` invokes the executable Node regression as part of Laravel CI; Node is a test-only runner dependency and not required to serve the Dashboard in production.
- Required exact-head GitHub Repository Policy, V2 Isolation and FOODEX Required CI must be terminal green, including AR/EN and Laravel tests, before merge. Browser-on-device visual confirmation of 320–1280px, RTL/LTR and real assistive technology remains under #2750/#2503; not substituted by the Node behavioral regression.

## Impact and review
- Dashboard: presentation-only preference fallback; existing route and server authorization unchanged.
- Customer Flutter/API: NOT AFFECTED; no API or mobile client source changed.
- Driver Flutter/API: NOT AFFECTED; no Driver source, API or dispatch changed.
- Van Flutter/API: NOT AFFECTED; B2B VAN ONLY and B2C DRIVER ONLY stay authoritative.
- Risk: LOW if diff remains this presentation-only helper and regression files; no business mutation, money, permission, audit, ZIP, schema, or external API change.
- Failure recovery: reverting this isolated frontend helper restores prior behavior; user data, `.env`, uploads and finance state never modified. No migration or release.
- No `main` merge, tag, production, release, cPanel changes.
