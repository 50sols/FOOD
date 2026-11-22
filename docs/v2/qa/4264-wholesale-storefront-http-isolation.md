# P031 — Wholesale Storefront Admin HTTP isolation (#4264)

- **Parent:** #2782; master #2503; exclusive integration `Foodex_V2`.
- **Scope:** isolated Laravel Admin `GET /admin/b2b/storefront` via `admin.b2b.module` (`module=storefront`, permission `settings.view`), not customer app/public storefront nor update APIs. Dedicated additive test; existing runtime and mobile untouched.
- **Authority:** `B2bWorkspaceController::MODULE_PERMISSIONS` and `WholesalePrincipal::storeId()` supply canonical access and store context; client query parameters must not elevate B2C scope.
- **Positive:** seeded `B2B_ADMIN` receives HTTP 200, view `storeIds` equals canonical Wholesale store, AR RTL and EN LTR.
- **Negative:** forged `channel=b2c&store_id=<Retail>` cannot replace Wholesale `storeIds`; real Retail-only `B2C_STORE_ADMIN` cannot enter via forged `channel=b2b`; authenticated roleless user receives 403.
- **Read-only:** counts for orders, payments, invoices and driver_assignments unchanged by Admin GET requests.
- **Impact:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. B2B Van-only / B2C Driver-only preserved; no API, Flutter, quote, dispatch, finance or customer-facing behavior changed.
- **Risk:** HIGH authorization regression; file-level technical review required; independent review if branch protection mandates it. A same-account COMMENT is not GitHub APPROVED.
- **Closure:** confirm Policy, V2 Isolation, full Required CI including Laravel and MySQL/Redis required-ci-gate terminal SUCCESS on final exact HEAD, review actual changed files, protected SHA-guarded merge PR to `Foodex_V2`, verify both files on integration SHA, close #4264 only; parent/master stay OPEN. No main, release, tag, production, APK.
