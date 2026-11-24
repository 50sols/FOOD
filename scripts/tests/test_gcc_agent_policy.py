"""Guard the bounded local-CI agent against accidental trust-boundary regressions.

These are static contract checks, not substitutes for running it on Windows GCC.
"""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (ROOT / "scripts/local-ci/gcc-agent.ps1").read_text(encoding="utf-8")


class GccAgentContractTests(unittest.TestCase):
    def test_owner_and_exact_head_authorization(self):
        self.assertIn("$Owner='walidatiyaai2025-gif'", SCRIPT)
        self.assertIn("$Base='Foodex_V2'", SCRIPT)
        self.assertIn("$Comment.user.login -cne $Owner", SCRIPT)
        self.assertIn("$pr.head.repo.full_name -cne $Repo", SCRIPT)
        self.assertIn("$pr.head.sha -cne $sha", SCRIPT)
        self.assertIn("$actual -cne $Sha", SCRIPT)

    def test_doctor_binds_authenticated_github_to_immutable_canonical_repository(self):
        # A valid gh login is not sufficient after an organization transfer.
        # The agent must verify the same immutable repo as its PR gate before
        # reading or dispatching owner commands, without logging API secrets.
        doctor = SCRIPT.split("function Doctor {", 1)[1].split("function Api(", 1)[0]
        self.assertIn("$Repo='50sols/FOOD'", SCRIPT)
        self.assertIn("$RepositoryId=1385223867", SCRIPT)
        self.assertIn('gh api "repos/$Repo" --jq \'.id\' 2>$null', doctor)
        self.assertIn("$resolvedId -cne [string]$RepositoryId", doctor)
        self.assertIn("Canonical GitHub repository identity preflight failed.", doctor)
        self.assertLess(doctor.index("gh api"), doctor.index("docker info"))
        self.assertNotIn("Write-Output $resolvedId", doctor)

    def test_fixed_command_and_docker_isolation(self):
        self.assertNotIn("Invoke-Expression", SCRIPT)
        self.assertNotIn("iex ", SCRIPT.lower())
        self.assertIn("'--network','none'", SCRIPT)
        self.assertIn("'--read-only'", SCRIPT)
        self.assertIn("'--cap-drop','ALL'", SCRIPT)
        self.assertIn("'/work:ro'", SCRIPT.replace('("$($work):/work:ro")', "'/work:ro'"))
        self.assertNotIn("docker.sock", SCRIPT)
        self.assertNotIn("self-hosted", SCRIPT)
        self.assertIn("test_foodex_branch_policy.py", SCRIPT)

    def test_fetch_bounded_and_replay_safe(self):
        self.assertIn("Invoke-BoundedGitFetch", SCRIPT)
        self.assertIn("WaitForExit(900000)", SCRIPT)
        self.assertIn("GIT_TERMINAL_PROMPT='0'", SCRIPT)
        self.assertIn("already reported; skipping replay.", SCRIPT)
        self.assertIn("Invoke-DockerLogged -DockerArgs $args", SCRIPT)
        self.assertNotIn("& docker @args *> $log", SCRIPT)

    def test_git_fetch_credential_retry_is_bounded_and_noninteractive(self):
        # A migrated org may need the host's trusted Git helper. Anonymous
        # access is tried first and only a classified access rejection may
        # trigger exactly one noninteractive helper-backed fetch attempt.
        fetch = SCRIPT.split("function Invoke-BoundedGitFetch(", 1)[1].split("function Smoke(", 1)[0]
        self.assertIn("for($attempt=0;$attempt -lt 2;$attempt++)", fetch)
        self.assertIn("$authenticated=($attempt -eq 1)", fetch)
        self.assertIn("if(-not $authenticated) { $arguments+=' -c credential.helper=' }", fetch)
        self.assertIn("$script:GccLastFailedStage=Classify-GitFetchFailure -Path $attemptStderr", fetch)
        self.assertIn("$script:GccLastFailedStage -eq 'git-fetch-access'", fetch)
        self.assertIn("if(-not $authenticated -and", fetch)
        self.assertIn("continue", fetch)
        self.assertIn("$env:GIT_TERMINAL_PROMPT='0'", fetch)
        self.assertIn("$env:GCM_INTERACTIVE='never'", fetch)
        self.assertIn("$env:GIT_TERMINAL_PROMPT=$oldPrompt", fetch)
        self.assertIn("$env:GCM_INTERACTIVE=$oldGcmInteractive", fetch)
        self.assertIn("WaitForExit(900000)", fetch)
        self.assertNotIn("gh auth token", fetch)
        self.assertNotIn("GH_TOKEN", fetch)
        self.assertNotIn("Invoke-Expression", fetch)

    def test_comment_backlog_pagination_is_cursor_safe(self):
        # More than 100 GitHub issue comments can arrive between polls.
        # Never advance the cursor after inspecting only the newest page.
        self.assertIn("function Comments([long]$After=0)", SCRIPT)
        self.assertIn('Api "$Uri&page=$page"', SCRIPT)
        self.assertIn("if([long]$comment.id -le $After)", SCRIPT)
        self.assertIn("if($batch.Count -lt 100)", SCRIPT)
        self.assertIn("refusing to advance cursor", SCRIPT)
        self.assertIn("Comments -After $cursor", SCRIPT)
        self.assertIn("foreach($c in @(Api $Uri))", SCRIPT)
        self.assertNotIn("function Comments { return @(Api $Uri) }", SCRIPT)

    def test_report_replay_scans_beyond_first_pr_comment_page(self):
        # A cursor replay must not re-execute a command whose result moved
        # beyond the most recent 100 PR comments.
        self.assertIn("function RequestAlreadyReported([int]$Pr,[long]$Request)", SCRIPT)
        self.assertIn('comments?per_page=100&sort=created&direction=desc&page=$page', SCRIPT)
        self.assertIn("$body.Contains($smokeMarker)", SCRIPT)
        self.assertIn("$body.Contains($fullMarker)", SCRIPT)
        self.assertIn("if([long]$item.id -le $Request)", SCRIPT)
        self.assertIn("refusing duplicate execution", SCRIPT)
        self.assertIn("RequestAlreadyReported -Pr $n -Request ([long]$Comment.id)", SCRIPT)
        self.assertNotIn("$published.Count -gt 0", SCRIPT)

    def test_git_fetch_failure_is_classified_without_exposing_logs(self):
        # Distinguish process spawn, timeout and nonzero Git exit in the
        # automatic PR evidence without publishing local stderr or secrets.
        self.assertIn("$script:GccLastFailedStage='git-fetch-process-start'", SCRIPT)
        self.assertIn("$script:GccLastFailedStage='git-fetch-timeout'", SCRIPT)
        self.assertIn("function Classify-GitFetchFailure([string]$Path)", SCRIPT)
        self.assertIn("$script:GccLastFailedStage=Classify-GitFetchFailure -Path $attemptStderr", SCRIPT)
        self.assertIn("return 'git-fetch-nonzero-exit'", SCRIPT)
        for failure_class in ("git-fetch-lock", "git-fetch-network", "git-fetch-access", "git-fetch-ref"):
            self.assertIn(f"return '{failure_class}'", SCRIPT)
        self.assertIn("WaitForExit(900000)", SCRIPT)
        self.assertIn("local diagnostic files are private", SCRIPT)
        self.assertIn("- Stage: $stageLabel", SCRIPT)
        self.assertNotIn("Get-Content -LiteralPath $stderr", SCRIPT)

    def test_private_git_fetch_diagnostics_emit_only_fixed_classes(self):
        # Execute the genuine PowerShell function on disposable hostile output,
        # rather than simply checking source text. The runner must not echo
        # credential-like strings or local paths from a failed Git fetch.
        pwsh = shutil.which("pwsh")
        if not pwsh:
            self.skipTest("PowerShell is unavailable; hosted parser gate remains mandatory")

        start = SCRIPT.index("function Classify-GitFetchFailure(")
        end = SCRIPT.index("function Invoke-BoundedGitFetch(", start)
        private_classifier = SCRIPT[start:end]
        code = private_classifier + "\nWrite-Output (Classify-GitFetchFailure -Path $env:GCC_TEST_STDERR_PATH)"
        cases = [
            ("fatal: Unable to create '.git/shallow.lock': File exists", "git-fetch-lock"),
            ("fatal: unable to access: Could not resolve host: github.com", "git-fetch-network"),
            ("remote: Repository not found.", "git-fetch-access"),
            ("fatal: unable to access 'https://github.com/50sols/FOOD.git/': The requested URL returned error: 403", "git-fetch-access"),
            ("fatal: unable to access 'https://github.com/50sols/FOOD.git/': The requested URL returned error: 401", "git-fetch-access"),
            ("remote: Permission to 50sols/FOOD denied to GCC-worker.", "git-fetch-access"),
            ("fatal: couldn't find remote ref refs/pull/3444/head", "git-fetch-ref"),
            ("unrecognized fetch failure", "git-fetch-nonzero-exit"),
        ]
        with tempfile.TemporaryDirectory(prefix="gcc-fetch-classifier-") as directory:
            log = Path(directory) / "private-git.stderr"
            for private_error, expected in cases:
                log.write_text(private_error + "\nFAKE_TOKEN_NOT_FOR_PUBLIC_OUTPUT", encoding="utf-8")
                env = os.environ.copy()
                env["GCC_TEST_STDERR_PATH"] = str(log)
                completed = subprocess.run(
                    [pwsh, "-NoProfile", "-NonInteractive", "-Command", code],
                    capture_output=True,
                    text=True,
                    env=env,
                    timeout=30,
                    check=False,
                )
                self.assertEqual(completed.returncode, 0, completed.stderr[:400])
                self.assertEqual(completed.stdout.strip(), expected)
                self.assertNotIn("FAKE_TOKEN_NOT_FOR_PUBLIC_OUTPUT", completed.stdout + completed.stderr)
                self.assertNotIn(str(log), completed.stdout + completed.stderr)

            log.write_text("x" * 32769, encoding="utf-8")
            env["GCC_TEST_STDERR_PATH"] = str(log)
            oversized = subprocess.run(
                [pwsh, "-NoProfile", "-NonInteractive", "-Command", code],
                capture_output=True,
                text=True,
                env=env,
                timeout=30,
                check=False,
            )
            self.assertEqual(oversized.returncode, 0, oversized.stderr[:400])
            self.assertEqual(oversized.stdout.strip(), "git-fetch-nonzero-exit")

    def test_full_ci_replay_uses_fresh_attempt_scoped_checkout(self):
        # A crash before posting the result can replay an owner command.
        # An earlier git init/origin/shallow.lock must not poison that retry.
        self.assertIn(r'("work\{0}-{1}-{2}" -f $n,$Comment.id,[guid]::NewGuid().ToString("N"))', SCRIPT)
        self.assertIn("if($LASTEXITCODE -ne 0) { throw 'Full CI git init failed' }", SCRIPT)
        self.assertIn("if($LASTEXITCODE -ne 0) { throw 'Full CI git remote failed' }", SCRIPT)
        self.assertNotIn('$work=Join-Path $Root "work\\\\$n-$($Comment.id)"', SCRIPT)
        self.assertIn("if($fetched -cne $sha)", SCRIPT)
        self.assertIn("Remove-Item -LiteralPath $work -Recurse -Force", SCRIPT)

    def test_gcc_reports_running_agent_script_fingerprint_without_secrets(self):
        # A GCC machine may still have an older scheduled agent installed.
        # Both reports must identify the source script actually executing,
        # while never disclosing the installation path or host credentials.
        self.assertIn("function AgentSourceFingerprint", SCRIPT)
        self.assertIn("$source=[string]$PSCommandPath", SCRIPT)
        self.assertIn("Get-FileHash -LiteralPath $source -Algorithm SHA256", SCRIPT)
        self.assertIn("if($digest -cnotmatch '^[a-f0-9]{64}        self.assertIn("**Advisory only**", SCRIPT)
        self.assertIn("Report -Pr $n", SCRIPT)
        self.assertNotIn("/statuses/", SCRIPT)
        self.assertNotIn("/check-runs", SCRIPT)


if __name__ == "__main__":
    unittest.main()
)", SCRIPT)
        self.assertIn("return 'unavailable'", SCRIPT)
        self.assertEqual(SCRIPT.count("Installed agent script SHA-256 (diagnostic only): $(AgentSourceFingerprint)"), 2)
        self.assertNotIn("Installed agent path:", SCRIPT)
        self.assertNotIn("Installed agent script contents:", SCRIPT)
        self.assertIn("**Advisory only**", SCRIPT)

    def test_advisory_not_required_status(self):
        self.assertIn("**Advisory only**", SCRIPT)
        self.assertIn("Report -Pr $n", SCRIPT)
        self.assertNotIn("/statuses/", SCRIPT)
        self.assertNotIn("/check-runs", SCRIPT)


if __name__ == "__main__":
    unittest.main()
