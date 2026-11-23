# GCC local CI — isolated GitHub PR-comment agent

Issue #4405. Integration target Foodex_V2 only. Local evidence never replaces exact-head Required CI.

## Objective

After a one-time setup on GCC, commands flow through GitHub PR comments, not repeated Desktop Commander calls. The Windows scheduled task checks comments every 120 seconds, verifies the command is from the repository owner, validates the exact current PR SHA, runs a fixed test profile in isolated Docker, and posts a summary back to the PR.

## Installation prerequisites

Git, GitHub CLI already authenticated to the FOOD repository, and Docker Desktop running Linux containers. Install the reviewed script at this stable location on GCC:

    %LOCALAPPDATA%\FoodexGccCI\gcc-agent.ps1

Download from an exact reviewed commit SHA, not a mutable branch ref. Test prerequisites and install using:

    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Doctor
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Install

Install initializes the cursor to the latest existing repository comment, registers the FOODEX-GCC-Local-CI Windows logon task, and starts it. Pre-existing comments are ignored.

## Request syntax

Post a PR conversation comment authored by walidatiyaai2025-gif, on an open same-repository issue-style PR targeting Foodex_V2:

    /gcc-ci run 0123456789abcdef0123456789abcdef01234567

Replace the sample SHA with that PR's actual current full 40-character head SHA.

The agent rejects comments from other users, a different repository, non-V2 PRs, closed PRs, fork heads, unknown branch naming, and SHA changes. It does not accept shell commands from GitHub. The first batch runs only CI map/failure registry validation and focused Python tests.

## Evidence

The agent posts a PASS/FAIL comment with PR number, exact SHA and exit code; results are advisory. Logs stay on GCC under:

    %LOCALAPPDATA%\FoodexGccCI\test-<PR>-<COMMENT_ID>.log

It keeps a cursor in cursor.json to avoid repeating handled comments. Requests that fall outside the latest 100 comments between polls need to be re-issued.

## Isolation

Docker image: python:3.12 (includes Git for planner regression tests). Security options: offline network, read-only code mount, read-only container filesystem, no host credentials, no Docker socket, no elevated capabilities, CPU/memory/PID limits. Test command is fixed in the agent script.

This initial profile does not validate Laravel, Composer, Flutter, MySQL, Redis, signed Setup ZIP, Update ZIP, or real installer/recovery. Add backend and full test profiles only after review of isolated execution and parity. Never treat this local advisory evidence as equivalent to GitHub Required CI, product acceptance or production readiness.

Do not register GCC as a general self-hosted runner on the public FOOD repository. This is an opt-in guarded alternative to exposing the Windows workstation to GitHub PR code.

## Diagnostics / stopping

    powershell.exe -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Doctor
    powershell.exe -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Once
    Get-ScheduledTask -TaskName FOODEX-GCC-Local-CI
    Stop-ScheduledTask -TaskName FOODEX-GCC-Local-CI
    Unregister-ScheduledTask -TaskName FOODEX-GCC-Local-CI -Confirm:$false

No installation, test success, GitHub evidence, or scheduled service status should be claimed without verification.
