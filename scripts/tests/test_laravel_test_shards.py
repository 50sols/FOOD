"""Fail-closed full Laravel shard coverage and critical-path CI wiring."""
import importlib.util
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("laravel_test_shards", ROOT / "scripts/laravel-test-shards.py")
shards = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(shards)


class LaravelShardContractTest(unittest.TestCase):
    def test_actual_phpunit_files_are_exhaustive_and_unique(self):
        paths = shards.collect_tests(ROOT / "backend/phpunit.xml")
        self.assertGreater(len(paths), 100)
        groups = shards.distribute(paths, 4)
        self.assertEqual(paths, sorted(p for group in groups for p in group))
        self.assertEqual(len(paths), len(set(p for group in groups for p in group)))
        self.assertTrue(all(group for group in groups))
        self.assertEqual(groups, shards.distribute(paths, 4))

    def test_shard_xml_preserves_phpunit_configuration_and_selection(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "shard.xml"
            original = ROOT / "backend/phpunit.xml"
            paths = shards.distribute(shards.collect_tests(original), 4)[0]
            shards.write_shard(original, paths, output)
            source = ET.parse(original).getroot()
            generated = ET.parse(output).getroot()
            self.assertEqual(source.attrib, generated.attrib)
            self.assertEqual(ET.tostring(source.find("php")), ET.tostring(generated.find("php")))
            self.assertEqual(ET.tostring(source.find("source")), ET.tostring(generated.find("source")))
            self.assertEqual([node.text for node in generated.findall("./testsuites/testsuite/file")], paths)

    def test_missing_directory_or_unrecognized_suite_entry_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "phpunit.xml").write_text("<phpunit><testsuites><testsuite><directory>missing</directory></testsuite></testsuites></phpunit>")
            with self.assertRaises(ValueError):
                shards.collect_tests(root / "phpunit.xml")
            (root / "phpunit.xml").write_text("<phpunit><testsuites><testsuite><exclude>tests/Unit</exclude></testsuite></testsuites></phpunit>")
            with self.assertRaises(ValueError):
                shards.collect_tests(root / "phpunit.xml")
            with self.assertRaises(ValueError):
                shards.distribute(["tests/Unit/OnlyTest.php"], 4)

    def test_both_workflows_start_full_shards_before_fast_fail_and_keep_strict_aggregate(self):
        for name in ("backend-ci.yml", "v2-backend-fast-ci.yml"):
            with self.subTest(workflow=name):
                content = (ROOT / ".github/workflows" / name).read_text()
                self.assertIn("name: Backend fast-fail", content)
                self.assertIn("backend-shards:\n    name: Laravel test shard", content)
                self.assertIn("shard: [0, 1, 2, 3]", content)
                self.assertIn("backend-smoke:\n    name: Backend migrations seed and runtime", content)
                self.assertIn("backend:\n    name: Backend tests", content)
                self.assertIn("needs: [fast, backend-smoke, backend-shards]", content)
                self.assertNotIn("    needs: fast\n", content)
                self.assertIn("Run complete Laravel shard", content)
                self.assertIn("php artisan test --configuration=.phpunit-shard.xml", content)
                self.assertIn("Run Composer security audit", content)
                self.assertIn("Validate fresh migrations", content)
                self.assertIn("Validate deterministic seed data", content)
        full = (ROOT / ".github/workflows/backend-ci.yml").read_text()
        self.assertIn("name: MySQL Redis deployment acceptance", full)
        self.assertIn("vendor/bin/phpunit tests/Deployment", full)


if __name__ == "__main__":
    unittest.main()
