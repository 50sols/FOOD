"""Negative and positive tests for the isolated G006 historical fixture patch."""

from pathlib import Path
import importlib.util
import tempfile
import unittest

MODULE = Path(__file__).resolve().parent.parent / "v2_g006_patch_historical_recovery.py"
SPEC = importlib.util.spec_from_file_location("g006_fixture_patch", MODULE)
assert SPEC and SPEC.loader
patcher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(patcher)


class HistoricalFixturePatchTest(unittest.TestCase):
    def test_exact_canonical_boundary_is_rewritten_once(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "historical.sh"
            script = (
                "#!/bin/bash\n"
                "php artisan migrate --force --no-interaction\n"
                + patcher.ORIGINAL
                + "php artisan migrate --force --no-interaction\n"
            )
            path.write_text(script, encoding="utf-8")
            patcher.patch_historical_fixture(path)
            result = path.read_text(encoding="utf-8")
            self.assertIn("SQLSTATE[42S01]", result)
            self.assertIn("--reconcile-ci-fixture", result)
            self.assertIn('cp "$repo_root/scripts/v2_g006_reconcile_interrupted_125.php" "../scripts/"', result)
            self.assertIn("DROP INDEX order_cs_discount_revision", result)
            self.assertIn("ADD UNIQUE INDEX order_cs_discount_revision", result)
            self.assertEqual(result.count("php artisan migrate --force --no-interaction"), 4)
            self.assertTrue(result.endswith("php artisan migrate --force --no-interaction\n"))

    def test_missing_boundary_does_not_write(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "historical.sh"
            original = "#!/bin/bash\necho altered\n"
            path.write_text(original, encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "boundary changed"):
                patcher.patch_historical_fixture(path)
            self.assertEqual(path.read_text(encoding="utf-8"), original)

    def test_wrong_migration_count_does_not_write(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "historical.sh"
            original = patcher.ORIGINAL
            path.write_text(original, encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "migration count"):
                patcher.patch_historical_fixture(path)
            self.assertEqual(path.read_text(encoding="utf-8"), original)

    def test_double_patch_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "historical.sh"
            original = "php artisan migrate --force --no-interaction\n" + patcher.ORIGINAL + "php artisan migrate --force --no-interaction\n"
            path.write_text(original, encoding="utf-8")
            patcher.patch_historical_fixture(path)
            once = path.read_text(encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "boundary changed"):
                patcher.patch_historical_fixture(path)
            self.assertEqual(path.read_text(encoding="utf-8"), once)


if __name__ == "__main__":
    unittest.main()
