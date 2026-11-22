#!/usr/bin/env python3
"""Fail-closed routing between full Laravel CI and deployment acceptance on V2 PRs.

The standard backend-ci.yml always includes MySQL/Redis install/upgrade/recovery.
Only a narrowly classified routine V2 backend change can use the exact same
fast-fail + full Laravel test jobs without its deployment-specific job. All
release, policy, authorization, database, and unknown changes keep full CI.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

# Includes framework/bootstrap, installed dependencies, schema and deployment.
CRITICAL_FILES = re.compile(
    r"^backend/(?:"
    r"(?:database|bootstrap|config|routes|tests/Deployment|app/Console|"
    r"app/Exceptions|app/Providers|app/Http/Middleware|app/Models|"
    r"app/Policies)/|"
    r"(?:composer\.(?:json|lock)|phpunit\.xml(?:\.dist)?|artisan|"
    r"\.env[^/]*)$|"
    r"public/index\.php$"
    r")",
    re.IGNORECASE,
)

# A deliberately broad conservative fence: names or tests concerning these
# contracts retain full MySQL/Redis deployment acceptance *before* merge.
CRITICAL_SURFACES = re.compile(
    r"(?:install|setup|updat|upgrad|backup|recover|rollback|migrat|"
    r"deploy|release|signature|signing|crypto|crypt|encrypt|secret|"
    r"zip|package|scheduler|queue|worker|storage|"
    r"auth|permission|privilege|rbac|entitlement|tenant|scope|"
    r"security|policy|isolation|finance|financial|ledger|account|"
    r"payment|settlement|treasury|invoice|credit|bank|audit|"
    r"owner|ownership|sod|checker|approval|access|tax|refund)",
    re.IGNORECASE,
)

RISK_BRANCH = re.compile(
    r"(?:install|setup|updat|upgrad|backup|recover|rollback|"
    r"security|auth|rbac|permission|privilege|tenant|scope|"
    r"finance|ledger|account|payment|treasury|settlement|"
    r"ownership|isolation|credit|sod|checker|migrat|release|"
    r"performance|audit|ci[-_/]|policy)",
    re.IGNORECASE,
)


def decision(plan: dict, base_ref: str) -> tuple[bool, str]:
    """True enables scoped V2 Laravel lane; False requires full backend CI."""
    if base_ref != "Foodex_V2":
        return False, "not-isolated-v2"
    if not isinstance(plan, dict):
        return False, "invalid-plan"
    paths = plan.get("changed_files")
    areas = plan.get("areas")
    if not isinstance(paths, list) or not paths or not isinstance(areas, dict):
        return False, "missing-path-or-area-evidence"
    if not all(isinstance(p, str) and p for p in paths):
        return False, "invalid-changed-path"
    if plan.get("unclassified_paths"):
        return False, "unclassified-paths"
    if plan.get("backend_dashboard_only") is not True or areas.get("backend") is not True:
        return False, "not-reviewed-backend-only"
    if any(areas.get(k) is True for k in (
        "mobile_ui", "enterprise_performance", "operational_completion_e2e",
        "c13_integrated_e2e", "mobile_prod_ux_acceptance",
    )):
        return False, "integrated-gate-required"
    if any(not (p.startswith("backend/") or p.startswith("docs/v2/qa/")) for p in paths):
        return False, "non-backend-path"
    if any(CRITICAL_FILES.search(p) or CRITICAL_SURFACES.search(p) for p in paths):
        return False, "critical-backend-path"
    if RISK_BRANCH.search(str(plan.get("branch", ""))):
        return False, "sensitive-issue-branch"
    return True, "routine-backend-full-laravel"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--base-ref", required=True)
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args()
    try:
        plan = json.loads(args.plan.read_text(encoding="utf-8"))
        fast, reason = decision(plan, args.base_ref)
    except (OSError, ValueError, TypeError) as error:
        # No reliable plan must never authorize the fast lane.
        fast, reason = False, f"unreadable-plan-{type(error).__name__}"
    result = f"v2_fast_backend={'true' if fast else 'false'}\nv2_deployment_reason={reason}\n"
    if args.github_output:
        with args.github_output.open("a", encoding="utf-8") as stream:
            stream.write(result)
    print(result, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
