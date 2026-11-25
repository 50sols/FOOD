#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import sys
import zipfile

RUNTIME_PREFIXES = (
    "backend/app/",
    "backend/bootstrap/",
    "backend/config/",
    "backend/database/migrations/",
    "backend/database/seeders/",
    "backend/lang/",
    "backend/public/",
    "backend/resources/",
    "backend/routes/",
)

RUNTIME_EXACT = {
    "VERSION",
    "backend/artisan",
    "backend/composer.json",
    "backend/composer.lock",
}

# Recovery-critical files are shipped in every incremental update, even when unchanged
# from the selected cumulative base. This heals installations that previously received
# an incomplete update package.
RUNTIME_ALWAYS_INCLUDE = {
    # Repair a live host after interrupted 125 update rollback, even if these
    # files have no git diff relative to the cumulative source tag.
    "backend/app/Providers/AppServiceProvider.php",
    "backend/app/Services/SystemInspectorRecorder.php",
    "backend/app/Services/BuiltInJobRoleCatalog.php",
    "backend/database/seeders/BuiltInJobRoleSeeder.php",
    "backend/config/permissions.php",
    "backend/app/Domain/Installer/InstallerWorkflow.php",
    "backend/app/Domain/Updater/LaravelUpdateRuntime.php",
    "backend/app/Http/Middleware/RunSchedulerHeartbeat.php",
    "backend/app/Services/SchedulerRuntime.php",
    "backend/bootstrap/app.php",
    "backend/config/foodex.php",
    "backend/public/.htaccess",
    "backend/public/brand/foodex-economical-group.webp",
    "backend/routes/console.php",
}

# Public static assets are intentionally cumulative/self-healing. Production
# shared-host installs may have received an older incomplete asset tree or
# directories with non-web-readable permissions.
RUNTIME_ALWAYS_INCLUDE_DIRS = (
    "backend/public/assets/admin",
    "backend/public/assets/leaflet/1.9.4",
    # The real shared Flutter Web previews are part of every Dashboard update.
    # The release workflow builds these trees before invoking this packager.
    "backend/public/preview/customer",
    "backend/public/preview/driver",
)

# Some supported shared-host/cPanel installs expose the repository release root as
# the HTTP document root while Laravel itself lives under backend/. Keep the
# canonical backend/public tree, but also emit safe static-only aliases at the
# release root so same-origin /assets, /brand, /demo and /preview URLs work
# without a second manual deployment path. Never mirror index.php or .htaccess.
PUBLIC_ROOT_MIRROR_PREFIXES = (
    "assets/",
    "brand/",
    "demo/",
    "preview/",
)

# CI can stage pinned third-party runtimes outside Composer's protected vendor/
# directory. Their files are copied into these application-owned locations and
# are included deterministically in the dashboard update ZIP.
GENERATED_RUNTIME_DIRS = (
    "backend/app/ThirdParty/tcpdf",
)

PROTECTED_PARTS = {".git", "storage", "vendor"}
FIXED_ZIP_TIME = (2026, 9, 27, 0, 0, 0)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build a deterministic FOODEX dashboard update bundle.")
    parser.add_argument("--base", required=True, help="Source commit for the minimum supported installed release.")
    parser.add_argument("--target-version", required=True)
    parser.add_argument("--minimum-current-version", required=True)
    parser.add_argument("--output-dir", default="dist/update")
    parser.add_argument("--release-notes", required=True)
    return parser.parse_args()


def run_git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return result.stdout


def is_runtime_path(path: str) -> bool:
    return path in RUNTIME_EXACT or path.startswith(RUNTIME_PREFIXES)


def safe_path(path: str) -> str:
    # Match the strict portable ZIP-member rules enforced by the PHP signer,
    # verifier and extractor. Never silently canonicalize a dangerous filename.
    if (
        not path
        or path != path.strip()
        or path.startswith("/")
        or "\\" in path
        or any(ord(char) < 0x20 or ord(char) > 0x7E for char in path)
    ):
        raise RuntimeError(f"Unsafe update path: {path}")

    parts = path.split("/")
    if any(part in {"", ".", ".."} for part in parts):
        raise RuntimeError(f"Unsafe update path: {path}")

    for part in parts:
        if (
            part.rstrip(" .") != part
            or any(char in '<>:"|?*' for char in part)
            or re.fullmatch(r"(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?", part, re.IGNORECASE)
        ):
            raise RuntimeError(f"Nonportable update path: {path}")

        lowered = part.lower()
        if lowered in PROTECTED_PARTS:
            raise RuntimeError(f"Protected runtime path is not allowed: {path}")

        # Unlike the old exact '.env' check, this also excludes .env.local,
        # .ENV.production and other live secrets. Only explicit example
        # templates accepted by UpdatePackageValidator are distributable.
        if (lowered == ".env" or lowered.startswith(".env.")) and not re.fullmatch(
            r"\.env(?:\.[A-Za-z0-9_-]+)*\.example", part
        ):
            raise RuntimeError(f"Environment files are not allowed in updates: {path}")

    # Static mirrors and Laravel's public tree must not receive executable or
    # server-configuration aliases from a compromised generated asset folder.
    # The existing canonical Laravel front controller and root .htaccess are
    # the only public executable/configuration exceptions.
    public_mirror = path.startswith(PUBLIC_ROOT_MIRROR_PREFIXES)
    laravel_public = path.startswith("backend/public/")
    bootstrap = path in {"backend/public/index.php", "backend/public/.htaccess"}
    if public_mirror or (laravel_public and not bootstrap):
        for part in parts:
            if (
                re.search(r"\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)(?:\.|$)", part, re.IGNORECASE)
                or re.fullmatch(r"(?:\.htaccess|\.user\.ini|web\.config)(?:\..*)?", part, re.IGNORECASE)
            ):
                raise RuntimeError(f"Executable public update path is not allowed: {path}")

    return path


def complete_runtime_files(repo_root: Path) -> list[str]:
    selected: set[str] = {
        path for path in RUNTIME_EXACT
        if (repo_root / path).is_file()
    }

    for prefix in RUNTIME_PREFIXES:
        root = repo_root / prefix.rstrip("/")
        if not root.is_dir():
            continue
        for source in sorted(path for path in root.rglob("*") if path.is_file()):
            selected.add(safe_path(source.relative_to(repo_root).as_posix()))

    selected.update(RUNTIME_ALWAYS_INCLUDE)

    for required_dir in RUNTIME_ALWAYS_INCLUDE_DIRS:
        root = repo_root / required_dir
        if not root.is_dir():
            raise RuntimeError(f"Required public runtime directory is missing: {required_dir}")
        for source in sorted(path for path in root.rglob("*") if path.is_file()):
            selected.add(safe_path(source.relative_to(repo_root).as_posix()))

    for generated_dir in GENERATED_RUNTIME_DIRS:
        root = repo_root / generated_dir
        if not root.is_dir():
            continue
        for source in sorted(path for path in root.rglob("*") if path.is_file()):
            selected.add(safe_path(source.relative_to(repo_root).as_posix()))

    return sorted(selected)


def changed_runtime_files(base: str, repo_root: Path) -> list[str]:
    diff = run_git("diff", "--name-status", "--find-renames", f"{base}..HEAD")
    selected: set[str] = set()

    for raw in diff.splitlines():
        if not raw.strip():
            continue

        parts = raw.split("\t")
        status = parts[0]

        if status.startswith("R") or status.startswith("C"):
            if len(parts) != 3:
                raise RuntimeError(f"Unexpected git diff row: {raw}")
            old_path, new_path = parts[1], parts[2]

            if is_runtime_path(old_path) and status.startswith("R"):
                raise RuntimeError(
                    f"Runtime rename/delete is unsupported by the current updater: {old_path} -> {new_path}"
                )
            if is_runtime_path(new_path):
                selected.add(safe_path(new_path))
            continue

        if len(parts) != 2:
            raise RuntimeError(f"Unexpected git diff row: {raw}")

        path = parts[1]
        if not is_runtime_path(path):
            continue

        if status.startswith("D"):
            raise RuntimeError(
                f"Runtime deletion is unsupported by the current updater: {path}"
            )

        if status[0] in {"A", "M", "T"}:
            selected.add(safe_path(path))

    selected.add("VERSION")
    selected.update(RUNTIME_ALWAYS_INCLUDE)

    for required_dir in RUNTIME_ALWAYS_INCLUDE_DIRS:
        root = repo_root / required_dir
        if not root.is_dir():
            raise RuntimeError(f"Required public runtime directory is missing: {required_dir}")

        for source in sorted(path for path in root.rglob("*") if path.is_file()):
            selected.add(safe_path(source.relative_to(repo_root).as_posix()))

    for generated_dir in GENERATED_RUNTIME_DIRS:
        root = repo_root / generated_dir
        if not root.is_dir():
            continue

        for source in sorted(path for path in root.rglob("*") if path.is_file()):
            selected.add(safe_path(source.relative_to(repo_root).as_posix()))

    return sorted(selected)


def package_entries(files: list[str]) -> list[tuple[str, str]]:
    entries: dict[str, str] = {}

    for source in files:
        canonical = safe_path(source)
        entries[canonical] = canonical

        public_prefix = "backend/public/"
        if not canonical.startswith(public_prefix):
            continue

        public_relative = canonical[len(public_prefix):]
        if not public_relative.startswith(PUBLIC_ROOT_MIRROR_PREFIXES):
            continue

        alias = safe_path(public_relative)
        existing = entries.get(alias)
        if existing is not None and existing != canonical:
            raise RuntimeError(
                f"Shared-host public alias collision: {alias} maps to both {existing} and {canonical}"
            )
        entries[alias] = canonical

    return sorted(entries.items())


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def trusted_source_file(repo_root: Path, source_relative: str) -> Path:
    # Path.is_file() follows symlinks. A linked asset can otherwise leak files
    # OUTSIDE the reviewed Git worktree into an otherwise correctly signed ZIP.
    # Reject linked parents too, including Windows junctions escaping the root.
    relative = safe_path(source_relative)
    if repo_root.is_symlink():
        raise RuntimeError("Release build root must not be a symbolic link.")

    source = repo_root
    for part in relative.split("/"):
        source = source / part
        if source.is_symlink():
            raise RuntimeError(f"Release input must not contain symbolic links: {relative}")

    if not source.is_file():
        raise RuntimeError(f"Selected update file is missing: {relative}")

    # A hard link is not a symlink: resolve() still places it inside this
    # worktree even when another pathname exposes the SAME external inode.
    # The signer must not package a foreign, multiply-linked runtime source.
    metadata = source.lstat()
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError(f"Release input must be a single-link regular file: {relative}")

    try:
        source.resolve(strict=True).relative_to(repo_root.resolve(strict=True))
    except (ValueError, OSError):
        raise RuntimeError(f"Release input escaped the reviewed source tree: {relative}") from None
    return source


def _source_identity(metadata: os.stat_result) -> tuple[int, int, int, int, int, int, int]:
    """Pin the reviewed inode, links, size and modification metadata."""
    return (
        metadata.st_dev,
        metadata.st_ino,
        metadata.st_mode,
        metadata.st_nlink,
        metadata.st_size,
        metadata.st_mtime_ns,
        metadata.st_ctime_ns,
    )


def _open_pinned_source(
    repo_root: Path, source_relative: str, expected: os.stat_result
):
    """Open a preflighted source without following any replaced path component.

    Path.read_bytes() performs a fresh symlink-following traversal after
    trusted_source_file() returns. A build-time attacker could replace the
    final file or an ancestor with a link in that gap and inject secrets
    into an otherwise source-verified signed ZIP.
    """
    if (
        not hasattr(os, "O_NOFOLLOW")
        or not hasattr(os, "O_DIRECTORY")
        or os.open not in os.supports_dir_fd
    ):
        raise RuntimeError("Safe directory-relative update packaging is unavailable.")

    relative = safe_path(source_relative)
    parent_fd = None
    file_fd = None
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    try:
        parent_fd = os.open(repo_root, directory_flags)
        components = relative.split("/")
        for component in components[:-1]:
            child_fd = os.open(component, directory_flags, dir_fd=parent_fd)
            os.close(parent_fd)
            parent_fd = child_fd

        file_fd = os.open(
            components[-1],
            os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_BINARY", 0),
            dir_fd=parent_fd,
        )
        opened = os.fstat(file_fd)
        current = (repo_root / relative).lstat()
        if (
            not stat.S_ISREG(opened.st_mode)
            or opened.st_nlink != 1
            or not stat.S_ISREG(current.st_mode)
            or _source_identity(opened) != _source_identity(expected)
            or _source_identity(current) != _source_identity(expected)
        ):
            raise RuntimeError(f"Release input changed after preflight: {relative}")

        handle = os.fdopen(file_fd, "rb")
        file_fd = None
        return handle
    except OSError as exc:
        raise RuntimeError(
            f"Release input changed or linked during packaging: {relative}"
        ) from exc
    finally:
        if file_fd is not None:
            os.close(file_fd)
        if parent_fd is not None:
            os.close(parent_fd)


def build_zip(repo_root: Path, entries: list[tuple[str, str]], output: Path) -> None:
    # Preflight all sources and pin their reviewed inode metadata. Hold no
    # unbounded number of open descriptors while scanning a full Laravel tree.
    verified: list[tuple[str, str, os.stat_result]] = []
    folded_names: set[str] = set()
    for archive_name, source_relative in entries:
        canonical = safe_path(archive_name)
        if canonical != source_relative and (
            not canonical.startswith(PUBLIC_ROOT_MIRROR_PREFIXES)
            or source_relative != "backend/public/" + canonical
        ):
            raise RuntimeError(f"Unexpected update member source mapping: {canonical}")
        if canonical.lower() in folded_names:
            raise RuntimeError(f"Ambiguous update member alias: {canonical}")
        folded_names.add(canonical.lower())
        reviewed = trusted_source_file(repo_root, source_relative)
        verified.append((canonical, source_relative, reviewed.lstat()))

    output.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(
        prefix=f".{output.name}.", suffix=".staging", dir=output.parent
    )
    os.close(fd)
    temporary = Path(temporary_name)
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for archive_name, source_relative, expected in verified:
                info = zipfile.ZipInfo(archive_name, date_time=FIXED_ZIP_TIME)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = (0o100644 & 0xFFFF) << 16

                # The file and EACH ancestor must still be the original
                # reviewed inode. Never re-open the string path via read_bytes.
                with _open_pinned_source(repo_root, source_relative, expected) as original:
                    with archive.open(info, "w") as packed:
                        remaining = expected.st_size
                        while remaining:
                            chunk = original.read(min(1024 * 1024, remaining))
                            if not chunk:
                                raise RuntimeError(
                                    f"Release input truncated during packaging: {source_relative}"
                                )
                            packed.write(chunk)
                            remaining -= len(chunk)
                        if original.read(1):
                            raise RuntimeError(
                                f"Release input grew during packaging: {source_relative}"
                            )

                    # An in-place rewrite, unlink or later file/parent swap
                    # during streaming must fail BEFORE publication.
                    try:
                        current = (repo_root / source_relative).lstat()
                    except OSError as exc:
                        raise RuntimeError(
                            f"Release input changed during packaging: {source_relative}"
                        ) from exc
                    if (
                        _source_identity(os.fstat(original.fileno())) != _source_identity(expected)
                        or _source_identity(current) != _source_identity(expected)
                    ):
                        raise RuntimeError(
                            f"Release input changed during packaging: {source_relative}"
                        )

        with zipfile.ZipFile(temporary, "r") as archive:
            names = archive.namelist()
            expected_names = [archive_name for archive_name, _, _ in verified]
            if names != expected_names:
                raise RuntimeError("Update ZIP file list does not match the deterministic selection.")
            for name in names:
                safe_path(name)
                if name.endswith("/"):
                    raise RuntimeError(f"Unexpected directory entry in update ZIP: {name}")

        # A failed build only ever leaves a disposable private staging file.
        # Never expose a partially written archive at the delivery path.
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)


def main() -> int:
    args = parse_args()
    repo_root = Path(run_git("rev-parse", "--show-toplevel").strip())

    installed_version = (repo_root / "VERSION").read_text(encoding="utf-8").strip()
    if installed_version != args.target_version:
        raise RuntimeError(
            f"VERSION contains {installed_version!r}; expected target {args.target_version!r}."
        )

    run_git("cat-file", "-e", f"{args.base}^{{commit}}")
    # Dashboard updates are cumulative/self-healing by contract. Package the
    # complete application-owned runtime so drifted shared-host installs can
    # recover missing unchanged Laravel files before migrations execute.
    files = complete_runtime_files(repo_root)
    entries = package_entries(files)

    migrations = [path for path in files if path.startswith("backend/database/migrations/")]
    contains_migrations = bool(migrations)

    output_dir = repo_root / args.output_dir
    output_dir.mkdir(parents=True, exist_ok=True)

    package = output_dir / "FOODEX-Update.zip"
    manifest_path = output_dir / "FOODEX-Update.json"
    files_path = output_dir / "FOODEX-Update.files.txt"
    hash_path = output_dir / "FOODEX-Update.sha256.txt"

    build_zip(repo_root, entries, package)
    package_hash = sha256(package)

    source_commit = run_git("rev-parse", "HEAD").strip()
    manifest = {
        "schema_version": 1,
        "available": True,
        "package": package.name,
        "target_version": args.target_version,
        "minimum_current_version": args.minimum_current_version,
        "source_base": run_git("rev-parse", args.base).strip(),
        "source_commit": source_commit,
        "sha256": package_hash,
        "contains_migrations": contains_migrations,
        "migrations": migrations,
        "delta": {
            "file_count": len(entries),
            "source_file_count": len(files),
            "files_manifest": "FOODEX-Update.files.txt",
        },
        "requires_full_redeploy": False,
        "reason": None,
        "release_notes": args.release_notes,
        "dashboard_fields": {
            "target_version": args.target_version,
            "minimum_current_version": args.minimum_current_version,
            "source_commit": source_commit,
            "sha256": package_hash,
            "contains_migrations": contains_migrations,
            "release_notes": args.release_notes,
        },
    }

    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    files_path.write_text("\n".join(name for name, _ in entries) + "\n", encoding="utf-8")
    hash_path.write_text(f"{package_hash}  {package.name}\n", encoding="utf-8")

    print(f"FOODEX update package: {package}")
    print(f"target_version={args.target_version}")
    print(f"minimum_current_version={args.minimum_current_version}")
    print(f"contains_migrations={str(contains_migrations).lower()}")
    print(f"file_count={len(entries)}")
    print(f"source_file_count={len(files)}")
    print(f"migration_count={len(migrations)}")
    print(f"sha256={package_hash}")

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise
