# V2 #4002 — System Inspector Backup Center link authority

**Scope:** UI-only existing Inspector navigation defect; FEATURE FREEZE. Parent #2750 (G009), #2751 (P006), #2746 (G005), prior merged #3985 (System Administration Health). Target `Foodex_V2` only.

## Placement and four-way screen/tab decision

| Field | Resolved contract |
| --- | --- |
| Domain / entity | Platform Operations / runtime health diagnostics |
| Actor / role | Active authorized platform inspector versus active Super Admin updater |
| Goal | Inspect health without falsely advertising privileged backup/restore/update |
| Workflow / frequency / density | Read-only diagnostic, frequent status view, high data density |
| Canonical screen | `/admin/inspector` / `admin.inspector.index`, `SystemInspectorController::index`, existing `admin.system-inspector` Blade |
| Navigation owner | Existing Administration Hub / Settings Center; `CONTEXT_ACTION` Backup Center inside Inspector only |
| Placement / pattern | **REUSE** canonical runtime panel, guard one existing link |
| Navigation impact | No Sidebar, route, tab, tool, new link, wizard or permissions |
| Responsive / accessibility | Existing 44px native anchor, keyboard focus and mobile composition preserved, no additional CSS/JS |
| Locale / direction | Existing `admin.system_update_surface.backup_center` translation in AR/RTL and EN/LTR |
| Authority / states | Inspector GET: `platform.manage`; Backup Center link: active SUPER_ADMIN AND effective `system.update`; unauthorized actor gets status but no link |
| Back path / deep link | Same Inspector route and existing Settings Center; direct updater URL independently authorized server-side |
| Application impact | Dashboard GET-only link gating; Customer, Driver, Van: no mobile API/Flutter/token/dispatch changes |

**G009 classification stays:** Inspector and Update/Installer routes = `SKIP_SECURITY/LOGIN/WIZARD`; System Health = `NO_TABS_SINGLE_FLOW`; Mobile Settings = `KEEP_EXISTING_TABS`. Splitting upgrade, backup, restore or installer confirmations into tabs would conceal security warnings, so it is explicitly rejected. No tab selection can elevate a role or perform a POST.

## Source-backed defect and precise fix

Before fix, `SystemInspectorController::platformAdmin` independently grants *read-only diagnostics* to any role with effective `platform.manage`, but `system-inspector.blade.php` unconditionally emitted its `admin.system-update.index` Backup Center link. That may send a platform diagnostics reader to a privileged 403. No evidence of a direct HTTP bypass; only a misleading navigation affordance.

The existing Inspector runtime panel keeps its system status badge and metrics for everyone entitled to inspect. The **single original Backup Center anchor** now appears only when `$user->is_active && $user->hasRole('SUPER_ADMIN')` and Blade `@can('system.update')` both succeed. `@can` uses existing Gate/permission policy; `hasRole` is a further restrictive check matching merged Settings Center and System Administration Health action projection. An unobtrusive `data-inspector-backup-center-link` attribute permits exact Blade DOM assertion without counting unrelated Sidebar links.

**Excluded owner paths:** #2746 original PR #3968 owns `SystemUpdateController.php`, signed ZIP/runtime, host readiness and actual backup/restore; no touching those paths, .env, packages or migrations. #3985 original PR #3986 already MERGED, no recreation. The current integration `SystemUpdateController` only checks Gate('system.update'); #2746 has unmerged stricter Super Admin HTTP rule. This UI cannot certify full HTTP signature/backup/installer acceptance.

## Tests / error and abuse cases

New `V2InspectorBackupLinkAuthorityTest` uses RefreshDatabase/CoreReferenceSeeder and real authenticated HTTP:
- Active custom role with `platform.manage` plus the independent Dashboard admission permission `settings.view` (not `system.update`): Inspector GET 200, runtime health visible and translated direction; **no** Inspector Backup Center link; direct updater GET and backup POST 403 in AR/EN. The separate Dashboard gate must not be bypassed by a test fixture.
- Active SUPER_ADMIN: status + original authorized Backup Center link in AR/EN without any POST.
- Active platform role with `system.update` inherited but explicit user GLOBAL DENY: Inspector remains 200, link absent; direct updater GET 403 in AR/EN.
- Inactive platform role holder and roleless actor: direct Inspector GET 403.
- All authorized/denied reads preserve `update_history`, `audit_logs`, role assignments and per-user override counts.

No stored inputs/Upload ZIP/Backup now/DB restore are executed by this UI work. No secret or internal ID/JSON is shown to a user. Cross-store and tenant contracts unaffected: only a platform control-plane link; B2B VAN ONLY / B2C DRIVER ONLY unchanged.

## Review and release gates

**REVIEW_RISK: HIGH** due to visible link to privileged updater, notwithstanding read-only UI mutation. **TECHNICAL_REVIEW: PENDING** actual final-head CI plus file-by-file source review. Check Repository Policy, V2 Isolation, complete Required CI (Laravel Feature, PHP/Pint/PHPStan/Composer security/OpenAPI, localization, MySQL/Redis), all SUCCESS on exact final SHA, and preserve original PR target `Foodex_V2`. A prior green CI run is not evidence for a newer SHA; skipped Flutter jobs are not claimed PASS.

**MANUAL_ACCEPTANCE:** `DEFERRED_TO_V2_FINAL`, including browser 320/390/768/1280 layouts, keyboard/focus and AR/EN RTL/LTR in staging. Issue #2746 and master #2503 stay open until true signed update + backup/recovery/installer and end-to-end gates pass.

No `main`, production, tag, release, package publication or deployment.
