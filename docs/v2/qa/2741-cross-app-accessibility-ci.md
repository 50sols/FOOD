# V2 #2741 — three-app bilingual accessibility CI evidence (MOB-ALL02)

**Integration:** Foodex_V2 only; parent #2503 / G008 #2749. This scoped change adds genuine Flutter widget interaction evidence. It does not authorize production distribution or certify the entire source requirement.

## Canonical placement and reuse

The existing Customer diagnostics route, Driver sign-in screen and Van sign-in screen are reused directly. No new route, menu, sign-in implementation, API, Dart localization catalogue, RBAC permission or session capability is introduced. Prior child #3373 / merged PR #3379 already tests bundled RTL text; this issue independently checks actual focusable control accessibility semantics and form interactions instead of repeating translated heading assertions.

| App | Existing surface | Direct regression |
| --- | --- | --- |
| Customer | `FoodexCustomerApp` diagnostics route | AR/EN localized note InputDecoration + accessible semantics, focus/editable state, export action semantic label and enabled status |
| Driver | `FoodexDriverApp` unauthenticated login | AR/EN email/password semantic labeling, focus/input and sign-in action label; no HTTP sign-in |
| Van | `VanLoginScreen` with fake repository | AR/EN email accessible hint, focus, password masking and required-email validation after tapping Sign in; fake repository throws on any attempted credential POST |

The tests use `tester.ensureSemantics()`, actual field semantics finders (not unrelated ancestor widgets), real app widgets and deterministic local inputs. They do not use real credentials, production HTTP, push, location or deployment. Existing AR/EN and LTR/RTL regression tests continue to run. The two localized variants must both pass.

## Source-level lifecycle defect found and corrected

The initial Customer test revealed an actual Flutter exception during teardown of a localized non-FOODEX diagnostic header: `_FoodexAnimatedHeaderTitleState` kept a lazy `late final AnimationController` accessed in `dispose()` even when its non-branded title path had never initialized it. This attempted `TickerMode` ancestor lookup on a deactivated Element. The minimal fix in `apps/customer_app/lib/features/storefront/storefront_design_system.dart` retains lazy animation creation **only for branded header text**, caches the controller once when needed, reads it from the existing animation builder, and uses null-aware disposal. Rendering, routing, font, AR/EN labels, role/store scope and network behavior remain unchanged. Running the new Customer widget tests through actual mount/focus/route teardown now passes both locales without the original exception. No Driver or Van runtime files were modified.

## CI integration

The existing `.github/workflows/v2-mobile-trio-ci.yml` exact-head Android matrix now runs, for EACH of Customer, Driver and Van, both `test/localization_test.dart` and `test/v2_accessibility_e2e_test.dart` **before** building validation APKs. A failed semantics/focus/locale interaction fails the app matrix and blocks the three-app provenance job; CI does not replace test failure with skipped build evidence.

Commands from each app root:

```bash
flutter pub get
flutter test test/localization_test.dart --reporter expanded
flutter test test/v2_accessibility_e2e_test.dart --reporter expanded
```

Local GCC Flutter focused accessibility tests were actually executed after correcting the semantics-handle test teardown: **Customer 2 PASS / Driver 2 PASS / Van 2 PASS (6 test cases total)**. The initial failing Customer header teardown and its fix were reproduced. The original localization suites were also run on GCC: **Customer 3 PASS, Driver 3 PASS, Van 2 PASS**; total **14 passing Flutter widget tests** across six suites. Focused `flutter analyze --no-pub` returned **No issues found** for the Customer source+new test and both Driver/Van new tests. These are verified local execution results; official exact-final-HEAD GitHub Policy, V2 Isolation, Required CI and three-app provenance outcomes still require independent checking before any merge claim.

## 2026-11-15 successor: focused RTL/LTR and password-input assertions

The original issue branch/PR is reused after the stale lease was released. The six AR/EN accessibility test cases now additionally inspect the effective widget `Directionality` at each entry-point field (Arabic RTL / English LTR). Driver and Van login cases exercise focus and actual synthetic password editing, verify the input remains obscured, and never submit credentials to a network endpoint. Customer diagnostics checks directionality at the note control. This is additive to the prior semantic-label and field-validation assertions; it does not alter runtime code, APIs, authentication, signing or product routes.

**Verification state:** These new assertions require fresh exact-final-HEAD Flutter CI and review. Prior 14 Flutter passes and green CI refer only to the older `e484e752a22c1144f875f0225870a5df692905cb` commit, not automatically to these changes. The remaining publisher-signed synchronized APK/provenance requirement under #2749 is **unfulfilled**: never describe CI-only APKs as trusted publisher-signed releases, and do not close #2741 based on this bounded test increment.

## 2026-11-15 successor: interactive touch-target regressions

The original three AR/EN widget suites now assert **actual rendered control geometry** rather than inferring accessibility from labels alone: Customer diagnostics note/export and Driver email/password/sign-in must each measure at least 44 logical pixels high; Van email/password/sign-in must each measure at least 48 logical pixels high. Assertions run for both locales on the same real widgets already exercised by focus, semantics and editing tests. A regression that shrinks a touch target now fails the existing exact-head Android trio gate before CI APKs are produced. No minimum-size claim is made solely from source styles.

**Verification:** This increment requires a new exact-final-HEAD Flutter/Repository Policy/V2 Isolation/Required CI/trio result. Prior success at `9dcec7cbe78177fe34b1b25a0676451d50178e47` does not certify the new assertions. No source/API route, authentication or production signing behavior was changed. Full screen-reader, text-scale, device journeys and authenticated publisher signatures remain outside this bounded increment and are NOT complete.

## Security and application impact

- **Backend/API:** no controller, route, permission, token, ledger, database or business state delta.
- **Dashboard:** no view, navigation, locale or authorization delta.
- **Customer:** diagnostics view tested plus lifecycle bug corrected for localized headers; B2B/B2C customer role, permissions, store context and API unchanged.
- **Driver:** login tested without network; B2C Driver-only dispatch unchanged.
- **Van:** login tested with a rejecting offline fake repository; B2B Van-only dispatch and consignment unchanged.
- **Privacy:** tests use synthetic email/note and never persist auth secrets.
- **Accessibility:** selected-locale field meaning, real focus/editing and actionable control labels are asserted. Broader screen-reader traversal, touch-target audits, high text-scale, physical device E2E and all screens remain unverified outside these entry-point tests.

## Acceptance remaining

The existing trio workflow produces **nonproduction/untrusted-signed Android validation APKs**, and its own provenance job truthfully identifies them as such; it is NOT evidence of authentic publisher-signed APKs. The master G008 release gate still needs a frozen exact V2 SHA, trusted publisher signatures and verified APK/provenance for all three apps, platform-wide accessible device/user journeys, integrated Backend/Flutter exact-head tests and Setup/Update ZIP acceptance. Until those facts are independently verified, keep **#2741 open** even if this scoped PR's exact-head CI is green.

No main merge, tags, releases, store submission, cPanel or production deployment.
