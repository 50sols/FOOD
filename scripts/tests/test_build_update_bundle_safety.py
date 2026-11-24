#!/usr/bin/env python3
"""Regression for G005 signed update build inputs: no secret or symlink leakage."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile


_SCRIPT = Path(__file__).resolve().parents[1] / "build-update-bundle.py"
_SPEC = importlib.util.spec_from_file_location("foodex_update_bundle_security", _SCRIPT)
assert _SPEC is not None and _SPEC.loader is not None
builder = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(builder)


class BuildUpdateBundleSourceSafetyTest(unittest.TestCase):
    def test_canonical_source_and_example_template_remain_valid(self) -> None:
        for name in (
            "VERSION",
            "backend/config/foodex.php",
            "backend/.env.example",
            "backend/.env.testing.example",
            "backend/public/index.php",
            "backend/public/.htaccess",
            "backend/public/assets/admin/app.css",
            "assets/admin/app.css",
        ):
            with self.subTest(name=name):
                self.assertEqual(builder.safe_path(name), name)

    def test_refuse_protected_secret_aliases_and_nonportable_members(self) -> None:
        invalid = (
            "backend/.env",
            "backend/.env.local",
            "backend/public/.ENV.production",
            "backend/config/.env.prod",
            "backend/public/assets/.env.staging",
            "backend/public/assets/app.php",
            "backend/public/preview/dropper.php5.jpg",
            "preview/.htaccess",
            "backend/public/assets/.user.ini",
            "backend/public/assets/web.config",
            "backend/VENDOR/package.php",
            "backend/Storage/data.json",
            "backend/.GIT/config",
            "backend/public/assets/con",
            "backend/public/assets/trailing.",
            "backend/public/assets/../secrets.json",
            "backend/public/assets/./secret.json",
            "backend//public/app.css",
            "backend\\public\\assets\\app.css",
            "/backend/public/app.css",
            "backend/public/assets/تجربة.png",
            "backend/public/assets/file\tname",
        )
        for name in invalid:
            with self.subTest(name=name), self.assertRaises(RuntimeError):
                builder.safe_path(name)

    def test_regular_files_create_the_expected_deterministic_zip(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"body { color: green; }")
            output = root / "dist" / "FOODEX-Update.zip"
            entries = [
                ("assets/admin/app.css", "backend/public/assets/admin/app.css"),
                ("backend/public/assets/admin/app.css", "backend/public/assets/admin/app.css"),
            ]
            builder.build_zip(root, entries, output)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(archive.namelist(), [entry[0] for entry in entries])
                for name, _ in entries:
                    self.assertEqual(archive.read(name), source.read_bytes())

    def test_bad_archive_alias_fails_before_writing_output(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_text("ok", encoding="utf-8")
            output = root / "dist/FOODEX-Update.zip"
            entries = [
                ("backend/public/assets/admin/app.css", "backend/public/assets/admin/app.css"),
                ("backend/public/assets/admin/APP.CSS", "backend/public/assets/admin/APP.CSS"),
            ]
            with self.assertRaisesRegex(RuntimeError, "Ambiguous"):
                builder.build_zip(root, entries, output)
            self.assertFalse(output.exists())

    def test_refuse_linked_release_file_before_zip_creation(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            path = root / "backend/public/assets/admin/app.css"
            path.parent.mkdir(parents=True)
            secret = base / "unrelated-secret.txt"
            secret.write_text("not distributable", encoding="utf-8")
            try:
                path.symlink_to(secret)
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic link creation unavailable on this host")
            output = root / "dist/FOODEX-Update.zip"
            with self.assertRaisesRegex(RuntimeError, "symbolic links"):
                builder.build_zip(root, [(path.relative_to(root).as_posix(), path.relative_to(root).as_posix())], output)
            self.assertFalse(output.exists())

    def test_refuse_linked_parent_directory_before_zip_creation(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            root.mkdir()
            off_tree = base / "unrelated-runtime"
            off_tree.mkdir()
            (off_tree / "payload.css").write_text("not distributable", encoding="utf-8")
            directory = root / "backend/public/assets"
            directory.parent.mkdir(parents=True)
            try:
                directory.symlink_to(off_tree, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic link creation unavailable on this host")
            output = root / "dist/FOODEX-Update.zip"
            source = "backend/public/assets/payload.css"
            with self.assertRaisesRegex(RuntimeError, "symbolic links"):
                builder.build_zip(root, [(source, source)], output)
            self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
