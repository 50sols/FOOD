# V2 #3715 — Fulfillment actor guard AR/EN

Parent #2503; original B2B VAN ONLY/B2C DRIVER ONLY canonical policy #2859 is already integrated. Scope and PR base `Foodex_V2` only. No main, production or release.

## Existing contract and observed defect
`FulfillmentActorPolicy` is shared by server-side dispatch and routing services. Its `actorForChannel` and `assertOrderActor` paths already reject unsupported channels and mismatched executor types, but returned English-only validation errors. The patch changes the messages only. B2B always maps to `van`; B2C always maps to `driver`; case-normalization, manual-dispatch strict delegation, field keys `channel` and `assignee_type`, the existing Laravel ValidationException/422 response shape, and the fail-closed outcome are unchanged. Validation errors do not display raw caller-supplied actor or channel values.

## Verification / security matrix
`V2FulfillmentActorLocalizationTest` selects Arabic and English Laravel locales and verifies unknown/blank channel denial, B2B Driver denial, B2C Van denial, empty/unknown executor denial, and unchanged errors in manual dispatch, with exact localized field errors. Valid Van+B2B and Driver+B2C actors remain allowed, including existing whitespace/case normalization. The policy has no database writes or notifications. Existing dispatch/tenant/HTTP tests remain required in exact-head Required CI; this isolated source change does not grant access.

## Impact matrix
- Backend: server validation text for invalid dispatch/fulfillment choices only; no route, response field, business rule, schema, queue or permission delta
- Dashboard: receives translated messages only when existing authenticated operations return these validation exceptions, no UI/Sidebar/RTL markup change
- Customer: no app/API DTO/Flutter change; server remains fulfillment authority
- Driver: no valid B2C assignment flow change, no Flutter/API schema update; forbidden B2B assignment remains forbidden
- Van: no valid B2B execution flow change, no Flutter/API schema update; forbidden B2C execution remains forbidden

REVIEW_RISK: HIGH because the touched class enforces actor/channel security invariants. Require file-by-file same-account COMMENTED technical audit (not independent approval), exact-final-head Repository Policy, V2 Isolation, localization, backend Laravel/Pint/PHPStan/security, MySQL/Redis and terminal Required CI success. Manual staging/device/business acceptance remains tracked under #2503.
