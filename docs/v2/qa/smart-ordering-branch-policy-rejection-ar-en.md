# V2 #3725 — Smart Ordering branch rejection AR/EN evidence

- Parent: #2503; target: `Foodex_V2` **only**; no `main`, production, releases or mobile publication.
- Source: `docs/quality/LOCALIZATION_CONTRACT.md`; `BranchPolicyService::scheduledFor` and `::deliveryZone`.
- REVIEW_RISK: **HIGH** (public checkout vicinity); review actual request/branch scope, condition ordering, exception status, write boundaries and locale selection. Engineering review from the same account is not an independent GitHub approval.

## Specific contract correction

| Trigger | Machine contract preserved | English text | Arabic text |
| --- | --- | --- | --- |
| Requested `scheduled_for` earlier than allowed branch-local tolerance | HTTP 422, `errors.scheduled_for[0]` | Scheduled time cannot be in the past. | لا يمكن تحديد موعد في الماضي. |
| Delivery coordinates not inside any active branch service zone | HTTP 409, `message` | Delivery address is outside this branch service area. | عنوان التوصيل خارج نطاق خدمة هذا الفرع. |

Both messages resolve through `smart_ordering.branch_policy.*` with paired `backend/lang/ar` and `backend/lang/en` catalogs. The branch zone query, distance formula/radius tolerance, timezone and two-minute past window are **unchanged**. No data schema, route, entitlement, business rule or response envelope change.

## Automated source acceptance

Existing real `SmartOrderingCoreTest` `RefreshDatabase` fixture supplies a scoped active Retail B2C restaurant, published revision, valid branch, inventory, active delivery zone and cart. New test `test_branch_policy_rejections_are_localized_for_arabic_and_english_without_checkout_writes` exercises checkout HTTP in both `en` and `ar`:
- 422 on an explicitly past pickup time; exact localized validation key and message;
- 409 on coordinates outside the active zone; exact localized response message;
- baseline `orders`, `payments`, `audit_logs` counts and original cart status unchanged after both rejections;
- prior `test_delivery_zone_fee_and_minimum_are_enforced` continues to prove valid in-zone checkout creates an order, and invalid minimum/outside zone are denied;
- existing `test_checkout_rejects_closed_branch_and_revalidates_operational_policy` continues to prove branch readiness is authoritative.

Test outcomes and CI links must be recorded on original issue/PR after **exact final HEAD** workflows complete. Source tests written does **not** imply they have passed.

## Surface impact classification

- Backend: only localized checkout rejections, no persistence or authorization change.
- Dashboard: no Dashboard admin route, UI, navigation or permissions altered.
- Customer: no Customer Flutter or Customer API route changed. Public QR Smart Ordering checkout is its separate web surface; its error **wording**, not status/shape, changes.
- Driver: no Driver Flutter or dispatch API change; B2C remains DRIVER ONLY.
- Van: no Van Flutter or Van API change; B2B remains VAN ONLY.
- AR/EN and RTL/LTR: payload text localized by request's trusted Laravel language context; existing browser layout and direction remain unchanged.

## Merge and closure gate

Review file-by-file five-path diff, ensure no overlapping lease, record `TECHNICAL_REVIEW` and exact-head test/run evidence. Merge only through this issue-scoped PR into `Foodex_V2` after every applicable required check reports **SUCCESS** on final SHA, then close leaf only as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with integration SHA. Staging/device/browser acceptance remains on parent #2503; parent stays OPEN.
