"""Regression: installer changes must trigger the real G004 ZIP/MySQL acceptance.

A successful generic backend test cannot substitute for the test that unpacks
the shipped ZIP, validates its contents, and runs the 15-step Web Installer.
"""
from __future__ import annotations

import fnmatch
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/v2-setup-staging-acceptance.yml"


def configured_paths() -> list[str]:
    source = WORKFLOW.read_text(encoding="utf-8")
    section = source.split("    paths:\n", 1)[1].split("\npermissions:", 1)[0]
    return re.findall(r"^      - '([^']+)'$", section, re.MULTILINE)


class SetupCiTriggerContractTest(unittest.TestCase):
    def test_critical_installer_behavior_reaches_actual_zip_acceptance(self) -> None:
        patterns = configured_paths()
        for changed in (
            "backend/app/Domain/Installer/InstallerWorkflow.php",
            "backend/app/Http/Controllers/Installer/InstallerController.php",
            "backend/resources/views/install/index.blade.php",
            "backend/routes/web.php",
            "backend/lang/ar/installer.php",
            "backend/lang/en/installer.php",
            "backend/config/foodex.php",
            "backend/tests/Feature/InstallerWorkflowTest.php",
            "backend/database/migrations/2026_11_23_000000_test.php",
            "backend/composer.lock",
            "scripts/v2_setup_signature.php",
            "scripts/tests/test_v2_setup_signature.py",
            "scripts/tests/test_v2_setup_ci_coverage.py",
        ):
            with self.subTest(changed=changed):
                self.assertTrue(
                    any(fnmatch.fnmatchcase(changed, pattern) for pattern in patterns),
                    f"Uncovered Setup install/release change: {changed}",
                )

    def test_same_workflow_executes_its_trigger_regression(self) -> None:
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn(
            "python3 -m unittest discover -s scripts/tests -p 'test_v2_setup_ci_coverage.py'",
            source,
        )
        self.assertIn(
            "bash scripts/validate-fresh-setup.sh",
            source,
        )
        self.assertIn(
            "scripts/v2_setup_staging_verify.py",
            source,
        )


if __name__ == "__main__":
    unittest.main()
