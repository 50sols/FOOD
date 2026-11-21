# V2 #3719 — AR/EN Dashboard Order status 409 guard

Source: docs/quality/LOCALIZATION_CONTRACT.md. Parent #2503. Integration **Foodex_V2 only**, never main/production/releases/APK/tags/cPanel.

## Existing behavior, corrected

The canonical authorized Dashboard POST /admin/operations/orders/{order}/status had two English-only 409 errors: cancellation of delivered/completed orders and attempts to set confirmed/preparing/ready manually. Both messages now use the existing actor-selected msg(ar,en) helper; the orders.manage scope, store/channel/tenant boundary, active Lookup validation, response codes, lifecycle logic, delegated OrderController and all data writes remain unchanged.

This is a bounded localization fix, not a new route/navigation or status machine. Dashboard Operations remains the discoverable UI, retaining AR/EN and RTL/LTR. Separate OrderController public/mobile API errors are explicitly out of scope.

## Tested contract

New authenticated RefreshDatabase/CoreReferenceSeeder Laravel HTTP test: AR/EN exact 409 on delivered->cancelled and pending->ready, foreign-store 404 and unauthorized 403. Rejections preserve orders, status history, notifications, audit and no Driver push. Existing positive pending->cancelled and status-machine tests stay intact.

Mobile impact individually: Customer unaffected (no API/Flutter change); Driver unaffected (no API/Flutter, B2C Driver-only maintained); Van unaffected (no Van API/Flutter, B2B Van-only maintained). No schema, DTO, route, permission, mobile binary or queue behavior modified.

**REVIEW_RISK HIGH** order lifecycle adjacency. Require documented same-account COMMENTED file-by-file engineering audit (not independent APPROVED), exact-head Repository Policy + V2 Isolation + AR/EN + Laravel/Pint/PHPStan/security + MySQL/Redis + terminal Required CI SUCCESS, then expected-SHA guarded Foodex_V2 squash. Leaf coding closure only after verified merge; #2503 remains open for final UAT.
