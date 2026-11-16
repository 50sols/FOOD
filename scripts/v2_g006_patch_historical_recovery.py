#!/usr/bin/env python3
"""CI-only G006 verification of interrupted *published* v1.0.125 recovery.

This changes a copied historical test harness in a temporary Git worktree.
It does not edit, repackage, or claim to fix the already-published ZIP or the
real Laravel runtime. Never invoke the fixture reconciler on production data.
"""

from pathlib import Path
import sys

ORIGINAL = "php artisan optimize:clear\nphp artisan migrate --force --no-interaction\nphp <<'PHP'\n"
REPLACEMENT = """php artisan optimize:clear
# The real v124 worktree owns the installed vendor/DB; stage this CI helper
# beside that worktree's backend before invoking it from backend cwd.
cp "$repo_root/scripts/v2_g006_reconcile_interrupted_125.php" "../scripts/"
# Published v125 can create the MySQL table before recording its migration.
# This exact historical failure MUST be observed, not silently ignored.
if php artisan migrate --force --no-interaction > "$workspace/g006-expected-first-failure.log" 2>&1; then
  echo 'Unexpected historical v125 interrupted-migration success' >&2
  exit 1
fi
grep -Fq 'SQLSTATE[42S01]' "$workspace/g006-expected-first-failure.log" || {
  cat "$workspace/g006-expected-first-failure.log" >&2
  echo 'Unexpected historical failure class; abort recovery test' >&2
  exit 1
}
grep -Fq 'order_customer_service_discounts' "$workspace/g006-expected-first-failure.log" || {
  echo 'Unexpected historical failure table; abort recovery test' >&2
  exit 1
}
# Negative: a missing unique constraint MUST prevent ledger reconciliation.
php -r '$db = new PDO("mysql:host=127.0.0.1;dbname=foodex_recovery_125", "root", "foodex_ci_only", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $db->exec("ALTER TABLE order_customer_service_discounts DROP INDEX order_cs_discount_revision");'
if php ../scripts/v2_g006_reconcile_interrupted_125.php --verify-only; then
  echo 'Unsafe acceptance: incomplete index structure passed reconciliation' >&2
  exit 1
fi
php -r '$db = new PDO("mysql:host=127.0.0.1;dbname=foodex_recovery_125", "root", "foodex_ci_only", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $db->exec("ALTER TABLE order_customer_service_discounts ADD UNIQUE INDEX order_cs_discount_revision (order_id, revision)");'
# Verify the complete published schema and preserved discount row first.
php ../scripts/v2_g006_reconcile_interrupted_125.php --verify-only
# This explicitly approved CI fixture transition records only fully validated
# historical DDL, with no production updater or published package modification.
php ../scripts/v2_g006_reconcile_interrupted_125.php --reconcile-ci-fixture
php artisan migrate --force --no-interaction
php ../scripts/v2_g006_reconcile_interrupted_125.php --verify-only
php <<'PHP'
"""


def patch_historical_fixture(path: Path) -> None:
    if path.is_symlink() or not path.is_file():
        raise ValueError("G006 historical test harness must be a regular file")
    original = path.read_text(encoding="utf-8")
    if original.count(ORIGINAL) != 1:
        raise ValueError("G006 canonical v125 harness migration boundary changed; fail closed")
    if original.count("php artisan migrate --force --no-interaction") != 3:
        raise ValueError("G006 canonical v125 harness migration count changed; fail closed")
    rewritten = original.replace(ORIGINAL, REPLACEMENT)
    with path.open("w", encoding="utf-8", newline="\n") as stream:
        stream.write(rewritten)


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        raise ValueError("Usage: v2_g006_patch_historical_recovery.py PATH_TO_COPIED_V125_CI_SCRIPT")
    patch_historical_fixture(Path(argv[1]))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv))
    except (OSError, ValueError) as error:
        print("G006 fixture patch rejected: " + str(error), file=sys.stderr)
        sys.exit(2)
