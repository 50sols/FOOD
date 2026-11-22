from __future__ import annotations
import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "v2-deployment-risk.py"
spec = importlib.util.spec_from_file_location("v2_deployment_risk", SCRIPT)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

def plan(paths=None, **kwargs):
    p = {
        "branch": "feat/9876-v2-catalog-cleanup",
        "changed_files": paths if paths is not None else ["backend/app/Services/ProductCatalogService.php"],
        "unclassified_paths": [],
        "backend_dashboard_only": True,
        "areas": {"backend": True, "dashboard_ui": True},
    }
    p.update(kwargs)
    return p

class V2DeploymentRiskTest(unittest.TestCase):
    def test_routine_backend_has_fast_full_laravel(self):
        fast, reason = module.decision(plan(), "Foodex_V2")
        self.assertTrue(fast, reason)

    def test_non_v2_and_unknown_fail_closed(self):
        self.assertFalse(module.decision(plan(), "main")[0])
        for p in (
            {}, None, plan([]),
            plan(["docs/v2/qa/unreviewed-security-contract.md"],
                 unclassified_paths=["docs/v2/qa/unreviewed-security-contract.md"]),
            plan(["backend/app/Services/OrderService.php"], backend_dashboard_only=False),
            plan(["backend/app/Services/OrderService.php"], areas={"backend": False}),
            plan(["backend/app/Services/OrderService.php", "docs/v2/unreviewed.md"]),
        ):
            with self.subTest(p=p):
                self.assertFalse(module.decision(p, "Foodex_V2")[0])

    def test_critical_paths_fail_closed(self):
        cases = (
            "backend/database/migrations/a.php",
            "backend/database/seeders/ImportantSeeder.php",
            "backend/composer.lock", "backend/composer.json",
            "backend/.env.example", "backend/bootstrap/app.php",
            "backend/config/database.php", "backend/routes/web.php",
            "backend/tests/Deployment/UpgradeTest.php",
            "backend/app/Http/Middleware/ScopeGate.php",
            "backend/app/Policies/StorePolicy.php",
            "backend/app/Services/SetupWizard.php",
            "backend/app/Services/BackupRecoveryService.php",
            "backend/app/Services/ReleaseSignatureVerifier.php",
            "backend/app/Services/PermissionDecision.php",
            "backend/app/Services/TenantBoundary.php",
            "backend/app/Services/FinanceLedger.php",
            "backend/tests/Feature/StoreOwnershipIsolationTest.php",
            "backend/app/Services/BankSettlement.php",
            "backend/app/Http/Controllers/Admin/UserAccessController.php",
        )
        for path in cases:
            with self.subTest(path=path):
                self.assertFalse(module.decision(plan([path]), "Foodex_V2")[0])

    def test_sensitive_branches_and_release_matrix_fail_closed(self):
        for name in ("fix/9876-v2-tenant-scope", "test/2697-v2-ap-officer-least-privilege", "ci/4117-v2-fast"):
            self.assertFalse(module.decision(plan(branch=name), "Foodex_V2")[0])
        for area in ("mobile_ui", "enterprise_performance", "operational_completion_e2e",
                     "c13_integrated_e2e", "mobile_prod_ux_acceptance"):
            with self.subTest(area=area):
                self.assertFalse(module.decision(plan(areas={"backend": True, area: True}), "Foodex_V2")[0])

    def test_cli_corrupt_plan_fails_closed(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / "bad.json"
            path.write_text("{not-json", encoding="utf-8")
            p = subprocess.run([sys.executable, str(SCRIPT), "--plan", str(path),
                                "--base-ref", "Foodex_V2"], capture_output=True, text=True)
            self.assertEqual(0, p.returncode, p.stderr)
            self.assertIn("v2_fast_backend=false", p.stdout)

    def test_required_gate_and_fast_laravel_parity(self):
        fast = (ROOT / ".github/workflows/v2-backend-fast-ci.yml").read_text()
        original = (ROOT / ".github/workflows/backend-ci.yml").read_text()
        for name in ("Backend fast-fail", "Backend tests", "composer lint", "composer analyse",
                     "composer audit", "Validate OpenAPI contract", "php artisan migrate:fresh --force",
                     "php artisan db:seed --force", "composer test"):
            self.assertIn(name, fast)
            self.assertIn(name, original)
        self.assertNotIn("production-services:", fast)
        # Keep scoped Laravel jobs in sync with the canonical reusable workflow.
        # Ignore only comment and timeout differences; command changes must be
        # incorporated into the scoped lane before backend-ci changes can merge.
        canonical = original.split("\n  production-services:\n", 1)[0]
        canonical = canonical.replace("name: Backend CI", "name: FOODEX V2 Scoped Backend CI", 1)
        def contract(text):
            return "\n".join(
                line for line in text.splitlines()
                if not line.lstrip().startswith(("#", "timeout-minutes:"))
            ).strip()
        self.assertEqual(contract(canonical), contract(fast))
        gate = (ROOT / ".github/workflows/required-ci-gate.yml").read_text()
        for needle in (
            "v2_fast_backend: $" + "{{ steps.deployment.outputs.v2_fast_backend }}",
            "uses: ./.github/workflows/v2-backend-fast-ci.yml",
            "      - v2-backend-fast-validation",
            "V2_FAST_BACKEND_RESULT:",
            'require_success "V2 scoped backend validation"',
            'require_applicable_success "$BACKEND_CHANGED" "Backend validation"',
        ):
            self.assertIn(needle, gate)

    def test_post_merge_full_backend_acceptance(self):
        workflow = (ROOT / ".github/workflows/v2-integrated-backend-acceptance.yml").read_text()
        for item in ("push:", "Foodex_V2", "uses: ./.github/workflows/backend-ci.yml",
                     "cancel-in-progress: true"):
            self.assertIn(item, workflow)

if __name__ == "__main__":
    unittest.main()
