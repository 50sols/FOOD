"""Guard the bounded local-CI agent against accidental trust-boundary regressions.

These are static contract checks, not substitutes for running it on Windows GCC.
"""
from pathlib import Path
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
        self.assertIn("$script:GccLastFailedStage='git-fetch-nonzero-exit'", SCRIPT)
        self.assertIn("WaitForExit(900000)", SCRIPT)
        self.assertIn("local diagnostic files are private", SCRIPT)
        self.assertIn("- Stage: $stageLabel", SCRIPT)
        self.assertNotIn("Get-Content -LiteralPath $stderr", SCRIPT)

    def test_full_ci_replay_uses_fresh_attempt_scoped_checkout(self):
        # A crash before posting the result can replay an owner command.
        # An earlier git init/origin/shallow.lock must not poison that retry.
        self.assertIn(r'("work\{0}-{1}-{2}" -f $n,$Comment.id,[guid]::NewGuid().ToString("N"))', SCRIPT)
        self.assertIn("if($LASTEXITCODE -ne 0) { throw 'Full CI git init failed' }", SCRIPT)
        self.assertIn("if($LASTEXITCODE -ne 0) { throw 'Full CI git remote failed' }", SCRIPT)
        self.assertNotIn('$work=Join-Path $Root "work\\\\$n-$($Comment.id)"', SCRIPT)
        self.assertIn("if($fetched -cne $sha)", SCRIPT)
        self.assertIn("Remove-Item -LiteralPath $work -Recurse -Force", SCRIPT)

    def test_advisory_not_required_status(self):
        self.assertIn("**Advisory only**", SCRIPT)
        self.assertIn("Report -Pr $n", SCRIPT)
        self.assertNotIn("/statuses/", SCRIPT)
        self.assertNotIn("/check-runs", SCRIPT)


if __name__ == "__main__":
    unittest.main()
