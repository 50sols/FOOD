"""Fail-closed static contracts for the GCC full local CI extension.

Runtime parity is NOT established by this static suite; an actual disposable
GCC Docker run and GitHub exact-head checks remain separate requirements.
"""
from pathlib import Path
import hashlib
import re
import subprocess
import sys
import tempfile
import unittest
import zipfile

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
        self.assertIn("Missing canonical ZIP artifact at expected evidence path", AGENT)
        self.assertIn("GCC result version must be a strict semantic version.", AGENT)
        self.assertIn("$Comment.user.login -cne $Owner", AGENT)
        self.assertIn("$pr.head.sha -cne $sha", AGENT)
        self.assertIn("$pr.base.ref -cne $Base", AGENT)
        self.assertIn("50sols/FOOD", AGENT)
        self.assertIn("$RepositoryId=1385223867", AGENT)
        self.assertIn("[long]$pr.base.repo.id -ne $RepositoryId", AGENT)
        self.assertIn("[long]$pr.head.repo.id -ne $RepositoryId", AGENT)
        self.assertIn("$fetched -cne $sha", AGENT)
        self.assertIn("Trusted-Toolkit", AGENT)
        self.assertIn("expected[$name]", AGENT)
        self.assertNotIn("Invoke-Expression", AGENT)
        self.assertNotIn("iex ", AGENT.lower())

    def test_scheduled_powershell_agent_has_one_complete_entrypoint(self):
        # A previously appended malformed duplicate after the Mode switch
        # parsed as an unexpected token and killed the Windows scheduled task.
        for symbol in ("function Report-Full(", "function Process(", "function Poll {", "switch($Mode) {"):
            with self.subTest(symbol=symbol):
                self.assertEqual(AGENT.count(symbol), 1)
        self.assertNotIn("\\n) {\\n   throw 'GCC result version", AGENT)
        self.assertTrue(AGENT.rstrip().endswith("}"))
        self.assertLess(AGENT.index("function Report-Full("), AGENT.index("function Process("))
        self.assertLess(AGENT.index("function Process("), AGENT.index("switch($Mode) {"))

    def test_trusted_immutable_local_scripts_are_never_read_from_pr_checkout(self):
        self.assertIn("ed5e18d6d1e9c9489ce206df7f4b5ac2052cac36", AGENT)
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

    def test_gcc_toolkit_pinned_full_script_matches_actual_git_blob(self):
        match = re.search(r"'full\.sh'='([0-9a-f]{40})'", AGENT)
        self.assertIsNotNone(match, "GCC must pin the trusted full.sh Git blob")
        # Windows Git working trees may expose CRLF even though the trusted
        # Git blob and raw downloaded toolkit script are LF-only. Compare the
        # canonical Git blob bytes, not the platform checkout representation.
        content = (BASE / "full.sh").read_bytes().replace(b"\r\n", b"\n")
        actual = hashlib.sha1(
            f"blob {len(content)}\0".encode("ascii") + content
        ).hexdigest()
        self.assertEqual(match.group(1), actual, "Pinned full.sh no longer matches reviewed bytes")

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
        self.assertIn("if DB_CONNECTION=sqlite DB_DATABASE=:memory:", FULL)
        self.assertNotIn("if DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite", FULL)
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

    def test_zip_verifier_rejects_linked_parents_and_escaped_evidence_roots(self):
        # On Windows, a genuine file inside a junction can have no reparse
        # attribute itself. Walking the ZIP's ancestor chain before hashing
        # is necessary in addition to refusing linked artifact files.
        verifier = AGENT[AGENT.index("$seenArtifactNames="):]
        self.assertIn("$canonicalEvidenceRoot=(Get-Item -LiteralPath $report", verifier)
        self.assertIn("$parent=$artifact.Directory", verifier)
        self.assertIn("$parent=$parent.Parent", verifier)
        self.assertIn("Refusing reparse-point ZIP parent directory", verifier)
        self.assertIn("ZIP artifact lies outside canonical GCC evidence root", verifier)
        self.assertIn("$withinEvidenceRoot=$true", verifier)
        self.assertIn("if(-not $withinEvidenceRoot)", verifier)
        self.assertLess(verifier.index("Refusing reparse-point ZIP parent directory"), verifier.index("Get-FileHash"))
        self.assertLess(verifier.index("ZIP artifact lies outside canonical GCC evidence root"), verifier.index("Get-FileHash"))

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

    def test_profiles_require_canonical_zip_paths_not_arbitrary_counts(self):
        self.assertIn('"FOODEX-Setup-v$($result.version).zip"', AGENT)
        self.assertIn("'FOODEX-Update.zip'", AGENT)
        self.assertIn("$requiredPaths", AGENT)
        self.assertIn("Join-Path (Join-Path $report 'update')", AGENT)
        self.assertIn("$artifacts | Where-Object { $_.FullName -ceq $expectedPath }", AGENT)
        self.assertIn("Missing canonical ZIP artifact at expected evidence path", AGENT)
        self.assertNotIn("$requiredPackages=switch($Profile)", AGENT)
        self.assertNotIn("@($artifacts).Count -lt $requiredPackages", AGENT)

    def test_gcc_zip_evidence_rejects_links_and_ambiguous_names_before_hashing(self):
        # The manifest is keyed by artifact basename: two nested ZIPs of
        # the same name must never be accepted against the same hash entry.
        # A Windows reparse point must not be hashed by following its target.
        start = AGENT.index("$artifacts=Get-ChildItem")
        end = AGENT.index("  return 0", start)
        verifier = AGENT[start:end]
        self.assertIn("[IO.FileAttributes]::ReparsePoint", verifier)
        self.assertIn("Refusing symlink/reparse-point ZIP artifact", verifier)
        self.assertIn("StringComparer]::OrdinalIgnoreCase", verifier)
        self.assertIn("$seenArtifactNames.Add([string]$artifact.Name)", verifier)
        self.assertIn("Duplicate ZIP artifact filename", verifier)
        self.assertLess(verifier.index("ReparsePoint"), verifier.index("Get-FileHash"))
        self.assertLess(verifier.index("$seenArtifactNames.Add"), verifier.index("Get-FileHash"))

    def test_setup_zip_rejects_all_non_template_dotenv_files(self):
        # Execute the actual embedded GCC ZIP verifier, not a reimplementation.
        marker = "step=verify-real-setup-zip\n  python3 - \"$setup\" \"$version\" <<'PY'\n"
        self.assertIn(marker, FULL)
        verifier = FULL.split(marker, 1)[1].split("\nPY\n", 1)[0]
        required = {
            "VERSION": "1.0.125\n",
            "backend/artisan": "artisan",
            "backend/vendor/autoload.php": "autoload",
            "backend/public/index.php": "index",
            "backend/.env.production.example": "safe public template",
        }
        cases = {
            "backend/.env.example": True,
            "backend/.env.local": False,
            "backend/.env.production": False,
            "backend/.env.backup": False,
            "backend/.env": False,
            "backend/config/.env": False,
            "elsewhere/.env.secret": False,
        }
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory) / "setup.zip"
            for entry, accepted in cases.items():
                with self.subTest(dotenv_entry=entry):
                    with zipfile.ZipFile(package, "w") as archive:
                        for name, contents in required.items():
                            archive.writestr(name, contents)
                        if entry not in required:
                            archive.writestr(entry, "test-only dummy content")
                    result = subprocess.run(
                        [sys.executable, "-c", verifier, str(package), "1.0.125"],
                        capture_output=True, text=True, check=False,
                    )
                    self.assertEqual(
                        result.returncode == 0, accepted,
                        f"{entry}: stdout={result.stdout} stderr={result.stderr}",
                    )

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
