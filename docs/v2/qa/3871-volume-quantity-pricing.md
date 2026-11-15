# V2 #3871 / P228 — quantity-price break semantics and isolation

Parent: #2558. Prior issue PR #3241 implemented existing minimum and ordering increment; this independent follow-up adds optional price breaks without rewriting the merged PR.

**Commercial rule:** The highest threshold whose quantity <= the base-unit purchase quantity sets the *entire line's* unit price. Not marginal/per-step pricing. Ordered breakpoints must be > MOQ, align with ordering increment and have monotonically non-increasing prices. Empty means legacy unit_price exactly; all values have 3-decimal base-unit precision.

**Current boundary:** source branch only. Required next tasks: authorized management API + Dashboard editor, Checkout/cart and Van quantity-aware regression and live exact-head tests; do not merge or close child/parent while missing.

**Security:** B2B principal store/channel, explicit user DENY and existing B2bPricingMode remain authoritative. B2C Driver and B2B Van fulfillment rules are unchanged. Customer/Van applications receive no direct Flutter source changes in this first commit; full contract impact assessment pending.

**Deployment:** optional nullable DB column, no retroactive order rewrite. Rollback that drops the column destroys newly entered breaks; do not rely on automatic rollback of a populated production migration. Backup/restore must be verified in isolated CI before acceptance. No main or production actions.
