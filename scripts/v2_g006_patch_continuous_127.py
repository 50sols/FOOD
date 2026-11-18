#!/usr/bin/env python3
"""CI-only G006 patch for a continuous v125 -> v127 MySQL fixture.

Only a copied historical CI harness is modified. The immutable published ZIP,
historical Git tag, Laravel runtime and production updater are never changed.
"""
from pathlib import Path
import sys

OLD_DATABASE_LINE = b"DB_DATABASE=foodex_upgrade_127"
CONTINUOUS_DATABASE_LINE = b"DB_DATABASE=foodex_recovery_125"
LINE_ENDING_BYTES = bytes((13, 10))


def patch_continuous_127(path: Path) -> None:
    """Point the copied v127 harness at the already-populated v125 test DB."""
    if path.is_symlink() or not path.is_file():
        raise ValueError("G006 v127 harness must be a regular file")

    if any(parent.is_symlink() for parent in path.parents):
        raise ValueError("G006 v127 harness cannot be reached through a symlinked parent")

    original = path.read_bytes()
    lines = original.splitlines(keepends=True)
    database_lines = [
        line for line in lines
        if line.rstrip(LINE_ENDING_BYTES).startswith(b"DB_DATABASE=")
    ]
    if len(database_lines) != 1 or database_lines[0].rstrip(LINE_ENDING_BYTES) != OLD_DATABASE_LINE:
        raise ValueError("G006 v127 database target changed; refusing continuous-chain patch")

    if original.count(OLD_DATABASE_LINE) != 1:
        raise ValueError("G006 v127 database target must occur exactly once")

    updated = original.replace(OLD_DATABASE_LINE, CONTINUOUS_DATABASE_LINE, 1)
    if updated.count(CONTINUOUS_DATABASE_LINE) != 1:
        raise ValueError("G006 continuous database target verification failed")

    path.write_bytes(updated)


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        raise ValueError("Usage: v2_g006_patch_continuous_127.py PATH_TO_COPIED_V127_CI_SCRIPT")
    patch_continuous_127(Path(argv[1]))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv))
    except (OSError, ValueError) as error:
        print("G006 continuous fixture patch rejected: " + str(error), file=sys.stderr)
        sys.exit(2)
