"""Fail-closed static contracts for the GCC full local CI extension.

Runtime parity is NOT established by this static suite; an actual disposable
GCC Docker run and GitHub exact-head checks remain separate requirements.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
BASE = ROOT / "scripts/local-ci"
AGENT = (BASE / "gcc-agent.ps1").read_text(encoding="utf-8")
COMPOSE = (BASE / "compose.backend.yaml").read_text(encoding="utf-8")
PREPARE = (BASE / "prepare.sh").read_text(encoding="utf-8")
FULL = (BASE / "full.sh").read_text(encoding="utf-8")
DOCKERFILE = (BASE / "Dockerfile.backend").read_text(encoding="utf-8")


class GccHeavyExecutionContracts(unittest.TestCase):
    def test_full_command_is_owner_exact_head_and_not_arbitrary_shell(self):
        self.assertIn("/gcc-ci (run|full)", AGENT)
        self.assertIn("$Comment.user.login -cne $Owner", AGENT)
        self.assertIn("$pr.head.sha -cne $sha", AGENT)
        self.assertIn("$pr.base.ref -cne $Base", AGENT)
        self.assertIn("$fetched -cne $sha", AGENT)
        self.assertIn("Trusted-Toolkit", AGENT)
        self.assertIn("expected[$name]", AGENT)
        self.assertNotIn("Invoke-Expression", AGENT)
        self.assertNotIn("iex ", AGENT.lower())

    def test_trusted_immutable_local_scripts_are_never_read_from_pr_checkout(self):
        self.assertIn("465f765b72af87e838384efc01247f7ac38ca7c5", AGENT)
        self.assertIn("raw.githubusercontent.com", AGENT)
        self.assertIn("git hash-object $file", AGENT)
        self.assertIn("context: .", COMPOSE)
        self.assertIn("COPY full.sh /ci/full.sh", DOCKERFILE)
        self.assertIn("COPY prepare.sh /ci/prepare.sh", DOCKERFILE)
        self.assertIn("Dockerfile.flutter", COMPOSE)
        self.assertIn("foodex-gcc-flutter-cache", COMPOSE)
        self.assertIn("Flutter Web preview build failed", AGENT)
        self.assertIn("backend/composer.lock", AGENT)
        self.assertIn("read_only: true", COMPOSE)
        self.assertIn("target: /source", COMPOSE)

    def test_full_php_tests_run_in_isolated_mysql_redis(self):
        self.assertIn("image: mysql:8.0", COMPOSE)
        self.assertIn("image: redis:7", COMPOSE)
        self.assertIn("network_mode: service:mysql", COMPOSE)
        self.assertIn("internal: true", COMPOSE)
        self.assertIn("cap_drop: [ALL]", COMPOSE)
        self.assertIn("security_opt: [no-new-privileges:true]", COMPOSE)
        self.assertIn("mysql-redis-deployment-recovery", FULL)
        self.assertIn("vendor/bin/phpunit tests/Deployment", FULL)
        self.assertIn("for shard in 0 1 2 3", FULL)
        self.assertIn("php artisan migrate:fresh", FULL)
        self.assertIn("php artisan db:seed", FULL)
        self.assertIn("node --test", FULL)

    def test_locked_production_setup_and_actual_update(self):
        self.assertIn("--no-scripts --no-plugins", PREPARE)
        self.assertIn("composer audit", PREPARE)
        self.assertIn("--no-dev", PREPARE)
        self.assertIn("composer analyse", FULL)
        self.assertIn("zip -qr", FULL)
        self.assertIn("validate-fresh-setup.sh", FULL)
        self.assertIn("scripts/build-update-bundle.py", FULL)
        self.assertIn("full_upgrade_acceptance=False", FULL)
        self.assertIn("trusted_release=False", FULL)
        self.assertIn("signed=False", FULL)
        self.assertIn("Get-FileHash", AGENT)

    def test_no_ci_bypass_or_secret_github_mount(self):
        self.assertNotIn("GITHUB_TOKEN", COMPOSE)
        self.assertNotIn("GH_TOKEN", COMPOSE)
        self.assertNotIn("docker.sock", COMPOSE)
        self.assertNotIn("gh api", FULL)
        self.assertNotIn("gh api", PREPARE)
        self.assertIn("GitHub Required CI remains authoritative", AGENT)
        self.assertNotIn("/check-runs", AGENT)
        self.assertNotIn("/statuses/", AGENT)


if __name__ == "__main__":
    unittest.main()
