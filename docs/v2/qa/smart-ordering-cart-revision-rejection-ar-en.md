# V2 #3727 — Public Smart Ordering cart revision rejection, Arabic and English

Integration target: `Foodex_V2` only. Parent #2503. REVIEW_RISK: HIGH (public cart boundary). No production, `main`, release, cPanel or APK publish.

## Contract and file-scoped audit
- `backend/app/Domain/SmartOrdering/CartService.php`: only two human-facing HTTP 409 messages changed to Laravel locale-resolved keys. Branch/publication validation order, hash equality, acceptance, auth/entitlements, response shape, data and side effects remain unchanged.
- `backend/lang/ar/smart_ordering_cart.php` and `backend/lang/en/smart_ordering_cart.php`: matching key sets, native Arabic vs exact original English meaning.
- `backend/tests/Feature/V2SmartOrderingCartRevisionLocalizationTest.php`: real seeded B2C restaurant site with ordering entitlement and active branch; AR and EN public HTTP 409 for missing published revision and stale revision, exact JSON message and no cart, item, analytics, order-context or order writes; published matching revision positive 201 and revision pinning.
- This QA file: acceptance and cross-app impact, no test success asserted before verified CI.

## Explicit impact and negative matrix
| Surface | Impact |
|---|---|
| Public restaurant QR Smart Ordering cart API | Localized human-readable 409 message only. HTTP status, JSON `message`, branch/site, revision and no-write guarantees retained |
| Dashboard | Not affected; no routes, navigation, actions, role or entitlement changes |
| Customer Flutter | Not affected; no Flutter code or Customer app endpoint/DTO change |
| Driver Flutter | Not affected; no Flutter code or Driver endpoint/DTO change |
| Van Flutter | Not affected; no Flutter code or Van endpoint/DTO change |
| Financial / fulfillment | No checkout settlement or dispatch mutation; B2B VAN ONLY and B2C DRIVER ONLY unchanged |

## Required verification
`cd backend && php artisan test --filter=V2SmartOrderingCartRevisionLocalizationTest` plus existing `SmartOrderingCoreTest`; Pint, PHP syntax and static analysis, AR/EN parity, security, MySQL/Redis acceptance and Repository Policy, V2 Isolation and terminal Required CI **at exact final PR head**. Record actual run URLs, technical review and result in PR before any merge. Human/device UAT remains under #2503 after coding completeness; do not claim external acceptance from this test alone.
