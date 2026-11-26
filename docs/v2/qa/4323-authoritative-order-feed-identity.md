# V2 P031 / #4323 — authoritative customer PII in Operations new-order feed

## Threat and boundary
`OrderOperationsController::newOrderFeed` is an authenticated Dashboard-only GET that serializes customer name/phone in new-order toast results. It already requires a published notification addressed to the actor, source=`customer_app`, canonical `orders.view` store/channel admission, Restaurant-profile exclusion and a matching persisted order. However historical `notification.data.customer_name` / `customer_phone` may be stale or misassociated with a different customer; the previous behavior rendered that payload whenever `customer_name` was nonempty. The authentic source is the actual `orders.b2b_customer_id` or `orders.b2c_customer_id` record.

## Narrow fix
After existing tenant and persisted order checks, read `name` and `phone` only from the order-linked B2B/B2C customer record. Never fall back to notification-supplied PII (including when the canonical field is blank or missing). Null/null is the safe fail-closed display. Preserve the existing cursor, notification limit, store/channel/profile scope, valid order metadata, Super Admin path, read-only contract and existing routing.

## Tests
`V2OperationsNewOrderFeedCustomerIdentityTest` seeds Retail and foreign stores, real customer IDs, real orders, authenticated Retail store-admin and Super Admin, and published notification payloads deliberately containing a foreign customer's name/phone. AR and EN GET must show only authoritative linked name/phone for both valid actors, exclude foreign store entry even when notification belongs to actor, retain cursor, and never write to orders, notifications or payments. Clearing authoritative name/phone must not cause cached notification fields to reappear.

Security review HIGH (PII integrity and isolation). Full exact final SHA Repository Policy, V2 Isolation, Required CI with four Laravel shards/MySQL Redis, file-level review and guarded GitHub merge required; no merge/closure before real success. Atomic #4323 only; parent #2782 and master #2503 remain OPEN.

MOBILE IMPACT — Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Dashboard-only GET; no API/Flutter/dispatch/production/main or release changes.
