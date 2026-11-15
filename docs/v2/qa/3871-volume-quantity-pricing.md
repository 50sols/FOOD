# V2 #3871 — graduated quantity pricing (P228)

Parent: #2558 / P228 (actual DOCX paragraph P217), master #2503. Original #2558 PR #3241 was merged and retains canonical MOQ/order increment. This child supplies optional quantity-price breaks.

## Placement and commercial policy

- Domain: Catalog & Pricing; actor: authorized Wholesale pricing manager; workflow: repeated tier configuration. Canonical route: /admin/b2b/pricing-approvals from Wholesale dashboard / Sales Control. Reuse and extend the existing price rule workspace, not a separate Sidebar or route.

- Each quantity breakpoint is a base-unit quantity at which a new all-units price applies. Highest eligible breakpoint wins; not marginal pricing. Breaks are ordered/unique, > MOQ, increment-aligned, max three fractional digits, up to 12, unit price non-increasing. Empty means legacy flat rule.

- A nullable JSON field preserves old pricing defaults. Legacy management callers omitting breaks preserve existing list. Avoid dropping populated column on upgrade rollback without backed up data.

## Permission and app impacts

- Gate b2b.pricing.view/manage and OperationalTenantScope effective per-store DENY, authorized B2B product active store and pricing-mode safeguards remain authoritative. Validation precedes transactional audited upsert; invalid inputs cause no writes.

- B2bPriceResolver returns active customer-tier/store/product breaks. CommerceQuoteService evaluates quantity-aware base-unit price before quote/checkout totals and immutable order snapshots. Product API and Van B2B catalog expose optional additive quantity_price_breaks list; scalar price fields unchanged. B2B=VAN ONLY and B2C=DRIVER ONLY preserved.

- Customer: B2B catalogue gains additive break list, cart displays server quote totals, existing Flutter API fields unchanged. Driver: no impacts. Van: optional B2B catalogue list, authenticated Van quote/create still delegates to authoritative order service. Dashboard: AR/EN RTL/LTR responsive editor in current screen, no second navigation authority.

## Required evidence not yet complete

- Unit test: QuantityPriceBreaksTest positive boundaries and invalid threshold matrix.

- Feature test: V2VolumeQuantityPricingTest management API validation and no-write, B2B cart add/update, checkout financial snapshot, AR/EN Dashboard authorized/denied.

- Exact latest-head Repository Policy, V2 Isolation, Pint/PHPStan/security, localization, full Laravel and real MySQL/Redis install/upgrade/recovery, HIGH file-by-file technical audit, no overlapping peer changes must all pass.

- #2558 and #3871 remain OPEN while any source or CI gate fails. Human/staging UAT tracked under #2503. No main, tags, releases or production.

## Legacy upsert integrity regression (HIGH source audit)

A legacy authenticated management caller may omit `quantity_price_breaks` while updating the base unit price, minimum order quantity or ordering increment. Existing schedules remain stored, but the retained thresholds may become economically invalid or increment-incompatible. The issue branch now locks the existing pricing rule inside the audited write transaction and revalidates retained breaks against the submitted base terms. Incompatible requests return field-scoped 422 and leave the rule and audit unchanged. A compatible legacy edit still preserves the original schedule; an explicit empty array allows a deliberate reset to flat pricing. The new seeded HTTP regression covers price increase inversion, MOQ collision, increment mismatch, no-write/no-audit failures, unchanged success and explicit clearing. This is an additional invariant, not a new route, permission or mobile payload.
