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
PREVIEW = (BASE / "preview.sh").read_text(encoding="utf-8")
FULL = (BASE / "full.sh").read_text(encoding="utf-8")
DOCKERFILE = (BASE / "Dockerfile.backend").read_text(encoding="utf-8")


class GccHeavyExecutionContracts(unittest.TestCase):
    def test_full_command_is_owner_exact_head_and_not_arbitrary_shell(self):
        self.assertIn("/gcc-ci (run|full|backend|setup|packages)", AGENT)
        self.assertIn("$script:GccLastFailedStage", AGENT)
        self.assertIn("Failed trusted runtime step", AGENT)
        self.assertIn("profile=$Profile expected=$requiredPackages", AGENT)
        self.assertIn("$Comment.user.login -cne $Owner", AGENT)
        self.assertIn("$pr.head.sha -cne $sha", AGENT)
        self.assertIn("$pr.base.ref -cne $Base", AGENT)
        self.assertIn("$fetched -cne $sha", AGENT)
        self.assertIn("Trusted-Toolkit", AGENT)
        self.assertIn("expected[$name]", AGENT)
        self.assertNotIn("Invoke-Expression", AGENT)
        self.assertNotIn("iex ", AGENT.lower())

    def test_trusted_immutable_local_scripts_are_never_read_from_pr_checkout(self):
        self.assertIn("17bd4497c79771adf1b0e7d5e59185139f3e2bed", AGENT)
        self.assertIn("raw.githubusercontent.com", AGENT)
        self.assertIn("git hash-object $file", AGENT)
        self.assertIn("context: .", COMPOSE)
        self.assertIn("COPY full.sh /ci/full.sh", DOCKERFILE)
        self.assertIn("COPY prepare.sh /ci/prepare.sh", DOCKERFILE)
        self.assertIn("Dockerfile.flutter", COMPOSE)
        self.assertIn("git -c safe.directory=/workspace -C /workspace rev-parse HEAD", PREVIEW)
        self.assertNotIn("git -c safe.directory=*", PREVIEW)
        self.assertIn("foodex-gcc-flutter-cache", COMPOSE)
        self.assertIn("Flutter preview build exited", AGENT)
        self.assertIn("function Invoke-DockerLogged", AGENT)
        self.assertIn("Start-Process -FilePath $bin", AGENT)
        self.assertIn("RedirectStandardError", AGENT)
        self.assertIn("Direct Docker backend build", AGENT)
        self.assertIn("COMPOSE_BAKE='false'", AGENT)
        self.assertIn("'build','--progress=plain'", AGENT)
        self.assertNotIn("'compose','-p',$project,'-f',$compose,'build'", AGENT)
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
        self.assertIn("shard_failures=$((shard_failures+1))", FULL)
        self.assertIn("1800s vendor/bin/phpunit", FULL)
        self.assertIn("refusing a PASS", FULL)
        self.assertIn("php artisan migrate:fresh", FULL)
        self.assertIn("php artisan db:seed", FULL)
        self.assertIn("xargs -0 -r -n1 -P4 php -l", FULL)
        self.assertIn("COMPOSER_PROCESS_TIMEOUT=900", FULL)
        self.assertIn("930s composer lint", FULL)
        self.assertIn("930s composer analyse", FULL)
        self.assertIn("node --test", FULL)

    def test_locked_production_setup_and_actual_update(self):
        self.assertIn("--no-scripts --no-plugins", PREPARE)
        self.assertIn("core.autocrlf=false -c core.eol=lf reset --hard", PREPARE)
        self.assertIn("git -c core.autocrlf=false diff --quiet HEAD", PREPARE)
        self.assertIn("Composer audit and OpenAPI lint delegated to mandatory GitHub Backend fast-fail", PREPARE)
        self.assertNotIn("composer audit --no-interaction", PREPARE)
        self.assertNotIn("npx --yes @redocly/cli", PREPARE)
        self.assertIn("composer validate --strict", PREPARE)
        self.assertIn("if [[ \"$profile\" != backend ]]", PREPARE)
        self.assertIn("GCC_PROFILE: ${GCC_PROFILE:-all}", COMPOSE)
        self.assertIn("if($Profile -eq 'all' -or $Profile -eq 'packages')", AGENT)
        self.assertIn("b18f6119161019916c5bb07cb8da5205ae5c1b63", PREPARE)
        self.assertIn("tcpdf_static.php", PREPARE)
        self.assertIn("--no-dev", PREPARE)
        self.assertIn("composer analyse", FULL)
        self.assertIn("zip -qr", FULL)
        self.assertIn("validate-fresh-setup.sh", FULL)
        self.assertIn('export GITHUB_SHA="$GCC_EXPECTED_SHA"', FULL)
        self.assertIn("export GITHUB_WORKSPACE=/workspace", FULL)
        self.assertIn("Refusing GitHub source SHA mismatch", FULL)
        self.assertIn("scripts/build-update-bundle.py", FULL)
        self.assertIn("git diff --quiet v1.0.125..HEAD -- backend/composer.json backend/composer.lock", FULL)
        self.assertIn("verify-safe-update-dependency-boundary", FULL)
        self.assertIn("Update ZIP touches protected files", FULL)
        self.assertIn("Incomplete canonical update ZIP/manifest/checksum set", FULL)
        self.assertIn("full_upgrade_acceptance=False", FULL)
        self.assertIn("all|backend|packages|setup", FULL)
        self.assertIn("if [[ \"$profile\" != setup ]]", FULL)
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
