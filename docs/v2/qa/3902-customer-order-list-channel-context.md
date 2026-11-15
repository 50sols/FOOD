# V2 #3902 — split Customer order-list explicit channel integrity

Atomic issue: #3902. Parent: #2733 (MOB-C04 Customer orders), related #2731 (MOB-C02 split identity), master #2503. Integration base **Foodex_V2 only**.

## Source defect and original authority

The canonical authenticated `GET /api/v1/orders` (Retail/B2C) and `GET /api/v1/b2b/orders` (Wholesale/B2B) both use `OrderController::index`. Laravel already validates optional query `channel` as `b2b|b2c`. The PlatformCustomer unified-history branch correctly filters by requested channel. The legacy non-platform branch derives the authoritative channel from `CustomerDomainResolver` and filters `orders.channel` by that authoritative value, **but did not check whether the caller's optional channel disagreed**. A Retail caller could request `channel=b2b` and obtain Retail orders incorrectly labelled as a Wholesale context; the inverse also applied to Wholesale callers. This is a request-contract/identity boundary defect, not a demonstrated cross-store data leak.

Order details `OrderController::show` already assert the requested channel matches the selected order, returning 404. The list now has corresponding fail-closed behavior: if `channel` is explicitly supplied and contradicts the resolved split Customer domain, return HTTP 404. Missing or correct `channel` preserves the existing successful list, pagination, settlement and counts. The unified PlatformCustomer path is not changed.

**Important existing B2B retail purchasing scope:** Request `store_id` or `store` may mean the linked Retail business ordering as Wholesale. Do not filter `orders.store_id` by that value in `b2b/orders` and do not modify `CustomerDomainResolver` or its entitlement constraints. This fix only enforces the already validated explicit channel after authoritative domain resolution.

## Seeded HTTP contract

Extended `SplitCustomerDomainIsolationTest::test_b2b_and_b2c_customer_domains_cannot_cross_channels` with the existing two-store + two-identity fixtures, under actual Sanctum authentication:

- Legacy Retail buyer, `GET /api/v1/orders?store=<retail>&channel=b2c` → 200, `meta.scope=b2c`, one owned order.
- Same buyer, same endpoint with `channel=b2b` → 404; existing `/api/v1/b2b/orders` → 403.
- Legacy Wholesale buyer, `GET /api/v1/b2b/orders` and `...channel=b2b` → 200, `meta.scope=b2b`, one owned order.
- Wholesale buyer, `...channel=b2c` → 404, and a crafted Retail store selection on normal Retail endpoint remains 404.
- Final order row count unchanged, no update, dispatch, finance, payment, notification or audit write in this patch.

## Contract classification

| Surface | Impact and evidence |
| --- | --- |
| Backend/API | The preexisting authenticated Customer order-list GET now refuses a contradictory explicit `channel` instead of returning owned orders under the wrong requested domain. Response schema, successful data, filters/statuses and default behavior unchanged. |
| Customer app | Existing `HttpCustomerOrdersApi` normalizes requested channel and explicitly rejects mismatches against `CustomerOrderContext.normalizedChannel` before request; valid contextual calls remain identical. No Flutter source, UI string or schema change needed. |
| Driver | Not affected: no Driver endpoint, token, status, dispatch or Flutter change; B2C driver-only fulfillment unchanged. |
| Van | Not affected: no Van endpoint, token, direct sale, assignment or Flutter change; B2B Van-only fulfillment unchanged. |
| Dashboard/Finance | Not affected: no Admin controller, finance settlement or Server-Side Roles change. |

HIGH risk classification because Customer identity and request context are involved. Require separate file-by-file source review on the exact final PR head; backend Laravel/permissions/negative tests, security/static/OpenAPI and bilingual AR/EN gates, full MySQL/Redis installation/upgrade/recovery on the same exact head. No green claim before GitHub terminal results. Existing UI and translations are unchanged; cross-app Flutter jobs may be SKIPPED only if the workflow path detector authoritatively confirms no app changes.

Human/device/customer journey UAT is deferred to #2503, while #2733/#2731 remain OPEN for complete Customer identity/orders/invoice/notification parity. No release, tags, main merge, production deployment, cPanel or signed ZIP/APK promotion.
