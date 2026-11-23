# #4379 — current profile effective role projection

**Source:** P031 #2782, master #2503. **Integration:** Foodex_V2 only.

The existing Admin Profile route lists active global roles but did not exclude archived historical roles, while its store assignment section included inactive stores and archived/inactive store-scoped roles. The displayed heading and effective-permission chips imply these are current grants. The authoritative permission service already treats revoked roles as unavailable; this source defect is a read-side presentation mismatch.

**Changes:** `AdminProfileController::index` now filters the role summaries to active, unarchived global roles and current store assignments to active, unarchived store/both roles within active stores. Historic role_user/user_store_roles/role/store rows remain untouched. No model mutation, endpoint, redirect, new navigation, database migration or additional permission. Existing AR/EN views, RTL/LTR and responsive layout are preserved.

**Executed source evidence:** Scoped seeded HTTP regressions for English/Arabic, positive active B2B global role, archival with stale actor, positive B2C scoped role, disabled store and archived role negative projections, preserved DB relationship rows, audit-log nonmutation. Exact-head GitHub CI, static/security checks, final technical review and protected merge evidence must be recorded separately. Do not claim manual production UAT.

**Impact:** Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. Only existing ERP admin profile read projection is changed; no mobile API, app, dispatch, auth, fulfillment or notification behavior change. B2B Van-only / B2C Driver-only preserved. Parent P031 and master stay OPEN.

**Review risk:** MEDIUM (security-related authorization projection; not a server permission grant). Risks: accidentally hiding legitimate active role, archived role shown as current grant, losing persisted history, incorrectly allowing disabled store. Tests explicitly cover positive and negative states. Do not merge without actual final-head gates.
