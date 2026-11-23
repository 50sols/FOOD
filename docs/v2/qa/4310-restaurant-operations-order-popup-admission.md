# V2 P031 / #4310 — Restaurant profile in Operations popup

## Security gap
#4301 protected canonical Retail Orders GET and #4305 protected generic Operations list GET; #4303/#4308 protected global search. The separate authenticated `admin.operations.orders.popup` GET calls `managedOrder(..., orders.view)`, which checks tenant/store/channel authorization but lacked Restaurant business-profile admission. Thus a Restaurant B2C_STORE_ADMIN could request a known order ID directly for `details`, `timeline` or `invoice` despite being excluded from Retail Orders workspace.

## Implementation and authority
`OrderOperationsController::popup` first validates allowed kinds and resolves the order with the established `managedOrder` tenant/channel/store check, then denies Restaurant-profile B2C_STORE_ADMIN before creating details, timeline or invoice payload. The same trusted SUPER_ADMIN exception as existing list endpoints remains. A mixed Restaurant+Retail user can still request an authorized Retail order; no foreign store permission is granted. Existing invoice-specific permission remains additional, not replaced.

## Real HTTP test
`V2RestaurantOperationsOrderPopupAdmissionTest` seeds Restaurant, Retail and foreign Retail stores and real B2C orders. In AR and EN, Restaurant-only and mixed store-admin direct Restaurant popup requests fail 404 for details/timeline/invoice. Unauthorized foreign Retail access fails 404. Retail-only and mixed authorized Retail details/timeline succeed, and explicit SUPER_ADMIN can read Restaurant details/timeline. Assert Orders, Payments, Invoices counts unchanged after GET. Existing P031 isolation regression tests remain.

## Acceptance
HIGH-risk tenant/authorization disclosure. File-level review must cover selected order scope, role/profile semantics, mixed users, Super Admin, direct kind URLs, invoice path and nonmutation, with exact last-head Policy, V2 Isolation and Required CI (including Laravel shards and MySQL/Redis) terminal SUCCESS before merge. GitHub protection rules remain authoritative; same-account engineering audit does not count as independent GitHub review. Source-complete only for #4310; parents #2782/#2503 stay open.

Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. This is Dashboard-only GET logic; no Flutter/API/dispatch/finance mutation, no main/release/production.
