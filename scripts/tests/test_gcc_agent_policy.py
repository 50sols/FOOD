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

    def test_advisory_not_required_status(self):
        self.assertIn("**Advisory only**", SCRIPT)
        self.assertIn("Report -Pr $n", SCRIPT)
        self.assertNotIn("/statuses/", SCRIPT)
        self.assertNotIn("/check-runs", SCRIPT)


if __name__ == "__main__":
    unittest.main()
