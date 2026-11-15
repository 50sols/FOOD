# V2 #3689 — literal database translation group isolation

Parent acceptance: #2744 (AR/EN CI gate), #2818 (Admin UI action authority), master #2503. **Only integration target: Foodex_V2**. Never main, release/tag, APK, production or cPanel.

## Verified source defect

`DatabaseTranslationLoader::load($locale, $group, $namespace)` delegates to the standard Laravel base loader before overlaying database translations. The historical database selector was `where('key', 'like', $group.'.%')`. SQL `LIKE` treats an underscore or percent **within the group name** as a wildcard. For example `qa_literal_rules` unexpectedly also loads `qaXliteral_rules.leaked_first` and `qa_literalXrules.leaked_second`. A spoofed row then becomes an unexpected translated key after `Arr::set`; this can corrupt the intended Arabic/English translation group. It does NOT establish an SQL-injection or cross-tenant data-exposure exploit.

## Bounded solution

- Preserve the existing `base->load`, locale checks `ar|en`, namespace bypass, table-existence check, graceful exception fallback and nested `Arr::set` format.
- Escape literal `!` as `!!`, `%` as `!%`, `_` as `!_` in the group plus its delimiter dot, then append the *only* intentional suffix wildcard `%`.
- Use a single parameter-bound `WHERE key LIKE ? ESCAPE '!'` expression with no user-derived SQL identifier. Preserve translation columns, query response shape, database records and caching service definitions.

There are no migrations, writes, role changes, queue/finance/storage changes, authentication changes or API contract changes.

## Real test

`backend/tests/Feature/TranslationCenterTest.php::test_database_translation_loader_isolates_literal_group_names_in_arabic_and_english` uses `RefreshDatabase` and the actual application translation-loader binding with real rows:

- Underscored translation group with correct title and nested key and maliciously similar **foreign** groups substituting each underscore.
- Group with literal `%`, decoy with substituted arbitrary character.
- Group with literal `!` and a different double-bang group.
- Direct `load()` checks in English and Arabic, actual Laravel `__()` contract in both locales, excludes spoofed keys and confirms unchanged translation and audit row counts.

Focused test command: `cd backend && php artisan test --filter=test_database_translation_loader_isolates_literal_group_names_in_arabic_and_english`.

## Impact classification

| Surface | Effect |
| --- | --- |
| Laravel / Dashboard AR/EN | Correct group-scoped DB translation overlay; no change to code-language defaults or UI presentation |
| Public API / OpenAPI | No response contract or translation catalog API update |
| Customer Flutter | Not affected: no public app contract, locale bundle, rendering or auth change |
| Driver Flutter | Not affected: no app contract, dispatch, wallet or locale key change |
| Van Flutter | Not affected: no app contract, B2B routing, custody or locale key change |

AR/EN correctness improves; RTL/LTR and accessibility components are untouched. B2B VAN ONLY / B2C DRIVER ONLY policies unaffected.

## Delivery gate

Risk: **MEDIUM**, because group-scoping protects localized user-facing content, read-only. Three exclusive paths: `DatabaseTranslationLoader.php`, one new method in `TranslationCenterTest.php`, and this QA note. Reviewed live open V2 PR file fences before claim; no overlap.

Require same-account detailed file-by-file `COMMENTED` technical audit (not independent GitHub APPROVED), final-head Laravel, PHP syntax/Pint/PHPStan/security, AR/EN quality, MySQL/Redis install/upgrade/recovery, Repository Policy, FOODEX V2 Isolation and terminal Required CI/required-ci-gate SUCCESS. Then guarded squash merge to **Foodex_V2 only**, verify integration SHA and close this atomic source leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Parent #2744 and #2503 remain OPEN until final consolidated acceptance.

No production readiness claim is made from this narrowly scoped source correction.
