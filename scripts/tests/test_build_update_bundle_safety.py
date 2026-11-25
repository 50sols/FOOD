#!/usr/bin/env python3
"""Regression for G005 signed update build inputs: no secret or symlink leakage."""
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
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

    def test_replace_checked_file_with_secret_symlink_during_zip_open_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"safe-reviewed-css")
            secret = base / "private-publisher-key.txt"
            secret.write_bytes(b"DO-NOT-PACKAGE-PRIVATE-KEY")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"

            original_zip = zipfile.ZipFile
            swapped = False

            def swap_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.rename(source.with_suffix(".reviewed"))
                    source.symlink_to(secret)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed or linked"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped, "The attack must execute AFTER source preflight.")
            self.assertFalse(output.exists(), "Never publish a partially replaced ZIP.")
            self.assertEqual(secret.read_bytes(), b"DO-NOT-PACKAGE-PRIVATE-KEY")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_replace_checked_file_with_fifo_after_preflight_does_not_block(self) -> None:
        # A FIFO is NOT a symlink. Blocking O_RDONLY open would wait forever
        # for a writer before the source could be rejected by fstat.
        if not hasattr(os, "mkfifo"):
            self.skipTest("Named pipes are unavailable on this host")
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-style")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"
            original_zip = zipfile.ZipFile
            swapped = False

            def swap_for_fifo_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.rename(source.with_suffix(".reviewed"))
                    os.mkfifo(source)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_for_fifo_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed after preflight"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped, "FIFO replacement must happen after preflight.")
            self.assertFalse(output.exists(), "Do not publish an invalid ZIP.")
            self.assertEqual(source.with_suffix(".reviewed").read_bytes(), b"reviewed-style")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_replace_checked_parent_with_offtree_symlink_during_zip_open_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-asset")
            off_tree = base / "secret-parent"
            off_tree.mkdir()
            (off_tree / "app.css").write_bytes(b"SECRET-OFF-TREE")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"

            original_zip = zipfile.ZipFile
            swapped = False

            def swap_parent_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.parent.rename(source.parent.with_name("admin.reviewed"))
                    source.parent.symlink_to(off_tree, target_is_directory=True)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_parent_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed or linked"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped)
            self.assertFalse(output.exists())
            self.assertEqual((off_tree / "app.css").read_bytes(), b"SECRET-OFF-TREE")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_refuse_hardlinked_external_release_input_before_zip_creation(self) -> None:
        # A hard link passes pathlib is_symlink() and resolve() confinement.
        # It aliases the exact inode of a file outside the reviewed worktree.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            outside = base / "outside-signing-tree-secret.txt"
            outside.write_bytes(b"outside-secret-must-not-ship")
            try:
                os.link(outside, source)
            except OSError as exc:
                self.skipTest(f"Hard links unavailable: {exc}")

            self.assertFalse(source.is_symlink())
            self.assertTrue(source.is_file())
            self.assertEqual(source.resolve(), source)
            self.assertGreater(source.stat().st_nlink, 1)
            output = root / "dist/FOODEX-Update.zip"
            entry = "backend/public/assets/admin/app.css"
            with self.assertRaisesRegex(RuntimeError, "single-link regular file"):
                builder.build_zip(root, [(entry, entry)], output)
            self.assertFalse(output.exists())
            self.assertEqual(outside.read_bytes(), b"outside-secret-must-not-ship")

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
