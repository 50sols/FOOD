# Stage 2 / #2706 — Wholesale price-tier setting readiness

**Original work:** existing Stage2 #2706, issue-scoped `feat/2706-v2-b2b-pricing-setup-readiness`, draft PR base `Foodex_V2`. This is an incremental settings acceptance, not all Stage2 Source Complete.

## Placement and scope

- Domain: Commercial B2B; entity: Wholesale pricing-tier mode; actor: authorized active B2B settings operator; goal: configure the existing tier-versus-product-base-price choice.
- Workflow: low-frequency Settings/Master Data; canonical `/admin/b2b/settings` and existing protected `PUT /admin/b2b/settings/pricing-tiers`.
- Choice: REUSE the current setting and existing server route; EXTEND inline help/status and a read-only Test action. No Sidebar, route, new feature, new underlying setting, raw identifier/JSON, self-review or pricing approval. Layout and shared FOODEX buttons reused; bilingual AR/RTL + EN/LTR and page responsive form wrap remain.
- Server authority: `settings.manage` checked through `OperationalTenantScope::assertStore` for main B2B principal, after Boolean/action validation. Retail-denied; successful Test cannot write `settings` or audit.
- Source/default: `B2bPricingMode::tiersEnabled` defaults false when missing, using product base price. A tier-enabled store with zero active `b2b_price_rules` is ACTION_REQUIRED; with any active rule is PARTIAL only (other accounts/products may lack approved price). Base mode is BASE_PRICE, not source-certified ERP READY.

## UI help contract

Purpose, where actual Finance/Commercial price agreement comes from, clearly **illustrative nonproduction** example, safe default, required Boolean and allowed Test/Save validation, role/store scope, Test limitations and recorded readiness state are visible inside the current Wholesale Settings section, with `backend/lang/ar,b2b_pricing_setup.php` / `en` dictionary. The existing saved mode remains authoritative. No cost/margin or credit/law values are invented.

## Test and security acceptance

- Positive: AR/EN authenticated GET shows bilingual guidance and direction, safe default readiness; authorized Save continues storing the original Boolean and same audit event.
- Negative: authorized Test for unconfigured active tiers reports missing rules and leaves settings and audit untouched; authorized Test for base-price mode does not modify a previously saved mode; unsupported action and malformed Boolean fail Laravel validation; B2C only operator cannot Test or Save B2B mode.
- The Test checks only active tier-rule **existence for this store**, not all active customers, product coverage, commercial margin, legal title/COGS, discount approval, credit limits, GL or module certification.
- High risk: pricing control plane. Customer, Driver, Van each NO DIRECT source/API/Flutter changes; existing B2B Save's historical runtime behavior preserved. B2B VAN ONLY and B2C DRIVER ONLY unaffected. P110 #2844 legal ownership, P229 #2559 margin rule activation, P129 #2853 credit/discount maker-checker and mobile parity are independent blocked dependencies.
- Before integration: full exact-final-head Policy, V2 Isolation, Required CI, Laravel/AR-EN/tenant/negative, review HIGH and actual nonoverlapping worker ownership. No main, tag, release, production, cPanel or Stage2 issue closure from this partial increment.

## #2706 customer Master Data extension — reuse the original client create flow

- **Placement:** Commercial/Wholesale → existing `/admin/b2b/clients`; role `b2b.accounts.manage`; `ADMIN_SETTINGS` / Master Data existing workflow with no Sidebar, route, JSON or raw IDs. Original `POST /admin/b2b/clients` reused. Source identity fields and rules from `B2bAccountController::store`; `B2bCustomerService` and `PlatformCustomerService` own persisted account identity.
- **Required+external:** legal/approved company display name, authorized contact, unique email, temporary passphrase (>=8 chars). **Optional+external:** phone <=50 and tax registration <=100, only if actually applicable; no invented tax status. **Preconfigured:** new account is `pending` and linked login inactive, no automatic credit, pricing-tier assignment or sales approval. There is no safe default for legal identity, password or credit.
- **Help AR/EN:** purpose of customer registration, how to obtain verified company/contact details, fictional example only, required/optional/validation, status `Needs approval`, `b2b.accounts.manage` role requirements. Structured, labelled input controls and native keyboard focus; no passwords echoed or raw identifier/JSON exposed. Reuse existing FOODEX cards/button tokens.
- **Save** remains original B2bAccountController Gate/transaction + audit `b2b.account.created` with user inactive and account `pending`. The wrapper accepts only `setup_action=save|test`; absent action keeps historic Save behavior. **Test** validates against the SAME required/optional field rules with email uniqueness but does not call `B2bAccountController::store`, create a user/customer/account, issue credit, grant role, or write audit; Save independently revalidates to handle races. Test is not approval or verified legal identity.
- **Acceptance tests** in existing `B2bAdminWorkspaceTest`: AR/EN RTL/LTR role-only GET, read-only Test and stable record/audit counts, Save pending+inactive and original audit, invalid email/passphrase/action negative, B2C direct POST denial. Cross-store/tenant from canonical existing B2B principal unchanged. No new API route or Flutter code.
- **Impact:** Customer NO_DIRECT_API_CHANGE (a saved `pending` account stays inactive until independent approval); Driver NO_DIRECT_RUNTIME_CHANGE; Van NO_DIRECT_RUNTIME_CHANGE. B2B VAN ONLY / B2C DRIVER ONLY unaffected. This does not grant new credit/pricing approval, Finance SoD, supplier master or certified ERP setup readiness.
- **Outstanding:** all other real company/tax/GL/credit/supplier/stock-title/profit/mail/queue/integration settings are owned by existing org/Finance/Procurement/WMS/Delivery pages and separate original issues; do not clone their workflows into this form. #2706 Stage2 FULL_SOURCE_COMPLETE=NO, PR stays Draft; #2503 final UAT and #2745/#2746 final ZIP gates unchanged.
