# #4361 — archived wholesale role eligibility in B2B customer policy

- Atomic P031 authorization leaf under #2782 and V2 master #2503, target Foodex_V2 only.
- B2bCustomerPolicy::canAccessWholesale now rejects archived-at roles even when roles.is_active stays true. Existing user-identity revocation (#4353), global scope and configured channel role allowlist, plus permission checks are unchanged.
- Seeded DB tests: B2B_ADMIN and SUPER_ADMIN active and after persisted archiving; cross-customer read/update/delete denial, and active own-customer access remains independent of wholesale staff eligibility. ReflectionMethod only isolates the private role gate from the separate canonical User::hasPermission role check so the exact defect is directly regression-tested. No audit mutation.
- Scope HIGH, narrowly contained; no claim of atomic transaction revocation or global authorization security completeness. Requires exact-head Repository Policy, V2 Isolation and Required CI including Laravel shards/MySQL Redis; protected squash merge and verify closure. Parent #2782 remains OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter, main, production, release or tags.
