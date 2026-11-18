from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from v2_g006_patch_continuous_127 import patch_continuous_127


ORIGINAL = (
    b"#!/usr/bin/env bash\n"
    b"cat > .env <<'ENV'\n"
    b"DB_CONNECTION=mysql\n"
    b"DB_DATABASE=foodex_upgrade_127\n"
    b"DB_USERNAME=root\n"
    b"ENV\n"
)
PATCHED = ORIGINAL.replace(
    b"DB_DATABASE=foodex_upgrade_127",
    b"DB_DATABASE=foodex_recovery_125",
)


class ContinuousV127PatchTests(unittest.TestCase):
    def test_exact_historical_database_setting_is_rewritten_once(self):
        variants = [
            (ORIGINAL, PATCHED),
            (
                ORIGINAL.replace(bytes((10,)), bytes((13, 10))),
                PATCHED.replace(bytes((10,)), bytes((13, 10))),
            ),
        ]
        for original, expected in variants:
            with self.subTest(line_endings="CRLF" if bytes((13, 10)) in original else "LF"):
                with tempfile.TemporaryDirectory() as directory:
                    target = Path(directory) / "ci-127-upgrade.sh"
                    target.write_bytes(original)

                    patch_continuous_127(target)

                    self.assertEqual(target.read_bytes(), expected)
                    self.assertEqual(target.read_bytes().count(b"DB_DATABASE="), 1)

    def test_rejects_unexpected_database_without_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "ci-127-upgrade.sh"
            original = ORIGINAL.replace(
                b"foodex_upgrade_127", b"foodex_production"
            )
            target.write_bytes(original)

            with self.assertRaisesRegex(ValueError, "database target changed"):
                patch_continuous_127(target)

            self.assertEqual(target.read_bytes(), original)

    def test_rejects_duplicate_database_settings_without_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "ci-127-upgrade.sh"
            original = ORIGINAL + b"DB_DATABASE=foodex_upgrade_127\n"
            target.write_bytes(original)

            with self.assertRaisesRegex(ValueError, "database target changed"):
                patch_continuous_127(target)

            self.assertEqual(target.read_bytes(), original)

    def test_rejects_already_patched_file_without_mutation(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "ci-127-upgrade.sh"
            target.write_bytes(PATCHED)

            with self.assertRaisesRegex(ValueError, "database target changed"):
                patch_continuous_127(target)

            self.assertEqual(target.read_bytes(), PATCHED)

    def test_rejects_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "regular file"):
                patch_continuous_127(Path(directory))

    def test_rejects_symlinked_harness(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            real = root / "real.sh"
            link = root / "linked.sh"
            real.write_bytes(ORIGINAL)
            try:
                link.symlink_to(real)
            except (OSError, NotImplementedError):
                self.skipTest("symlink creation is not available on this host")

            with self.assertRaisesRegex(ValueError, "regular file"):
                patch_continuous_127(link)

            self.assertEqual(real.read_bytes(), ORIGINAL)

    def test_rejects_symlinked_parent(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            real_parent = root / "real"
            real_parent.mkdir()
            real = real_parent / "ci-127-upgrade.sh"
            real.write_bytes(ORIGINAL)
            linked_parent = root / "linked"
            try:
                linked_parent.symlink_to(real_parent, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("directory symlink creation is not available on this host")
            link = linked_parent / "ci-127-upgrade.sh"

            with self.assertRaisesRegex(ValueError, "symlinked parent"):
                patch_continuous_127(link)

            self.assertEqual(real.read_bytes(), ORIGINAL)


if __name__ == "__main__":
    unittest.main()
