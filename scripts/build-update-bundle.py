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

# The Update ZIP publisher must never advertise an archive that Laravel's
# default Verify Package limits would reject after it reaches a shared host.
# These limits match UpdatePackageValidator and config/foodex.php. The package
# stays compatible with default deployments even when a source host permits
# larger archives via environment overrides.
MAX_UPDATE_ZIP_MEMBERS = 50_000
MAX_UPDATE_ENTRY_UNCOMPRESSED_BYTES = 268_435_456
MAX_UPDATE_TOTAL_UNCOMPRESSED_BYTES = 1_073_741_824


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build a deterministic FOODEX dashboard update bundle.")
    parser.add_argument("--base", required=True, help="Source commit for the minimum supported installed release.")
    parser.add_argument("--target-version", required=True)
    parser.add_argument("--minimum-current-version", required=True)
    parser.add_argument("--output-dir", default="dist/update")
    parser.add_argument("--release-notes", required=True)
    return parser.parse_args()


def validate_update_version_range(target_version: str, minimum_current_version: str) -> None:
    """Reject malformed or non-advancing Update ZIP version metadata before staging.

    Producer-side validation is mandatory even if the runtime updater performs
    its own independent signed-manifest compatibility checks.
    """
    pattern = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
    versions: list[tuple[int, int, int]] = []
    for label, value in (
        ("target_version", target_version),
        ("minimum_current_version", minimum_current_version),
    ):
        if not re.fullmatch(pattern, value):
            raise RuntimeError(
                f"Invalid {label}: expected canonical MAJOR.MINOR.PATCH version."
            )
        versions.append(tuple(int(part) for part in value.split(".")))

    if versions[0] <= versions[1]:
        raise RuntimeError(
            "Update target_version must be greater than minimum_current_version."
        )


def run_git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return result.stdout


def verify_composer_runtime_contract(repo_root: Path, base: str) -> None:
    """Refuse ZIP-only upgrades needing a different production vendor/autoloader.

    This update format excludes vendor/. Comparing production dependency and
    autoload declarations is a necessary (not sufficient) compatibility gate.
    Full installed-package/version and signer trust still require release QA.
    """
    try:
        previous = json.loads(run_git("show", f"{base}:backend/composer.json"))
        current = json.loads(
            (repo_root / "backend/composer.json").read_text(encoding="utf-8")
        )
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        raise RuntimeError(
            "Unable to verify Composer runtime compatibility for Update ZIP."
        ) from exc

    if not isinstance(previous, dict) or not isinstance(current, dict):
        raise RuntimeError("Composer runtime contract must be a JSON object.")

    # Update ZIP does not ship vendor/ or run Composer on the live host.
    # Package replacement/provision, package-discovery metadata and Composer
    # lifecycle scripts can change runtime behavior without a require diff.
    for section in (
        "require", "autoload", "repositories", "replace", "provide",
        "conflict", "extra", "bin", "scripts",
    ):
        if previous.get(section, {}) != current.get(section, {}):
            raise RuntimeError(
                f"Composer runtime {section} differs from the supported base; "
                "normal Update ZIP excludes vendor/. Build and validate a "
                "full production-dependency upgrade instead."
            )

    previous_config = previous.get("config", {})
    current_config = current.get("config", {})
    if not isinstance(previous_config, dict) or not isinstance(current_config, dict):
        raise RuntimeError("Composer runtime config must be a JSON object.")
    # Changed Composer plugins can invalidate the production build even when
    # the dependency list and lockfile resolutions are unchanged.
    if previous_config.get("allow-plugins", {}) != current_config.get("allow-plugins", {}):
        raise RuntimeError(
            "Composer runtime config.allow-plugins differs from the supported base; "
            "normal Update ZIP excludes vendor/. Build and validate a "
            "full production-dependency upgrade instead."
        )


def verify_composer_lock_contract(repo_root: Path, base: str) -> bool:
    """Reject ZIP-only upgrades when locked production packages differ.

    Updates do not ship vendor/, so identical composer.json declarations alone
    are not proof of identical installed Composer package resolutions. Legacy
    v1.0.125 has no tracked composer.lock: do not invent compatibility evidence
    for that base; its real-host upgrade remains an external acceptance gate.
    Returns True only when a tracked baseline lock was compared successfully.
    """
    try:
        tracked = run_git(
            "ls-tree", "-r", "--name-only", base, "--", "backend/composer.lock"
        ).splitlines()
    except subprocess.CalledProcessError as exc:
        raise RuntimeError("Unable to inspect the baseline Composer lockfile.") from exc

    if not tracked:
        return False
    if tracked != ["backend/composer.lock"]:
        raise RuntimeError("Ambiguous baseline Composer lockfile provenance.")

    try:
        previous = json.loads(run_git("show", f"{base}:backend/composer.lock"))
        current = json.loads(
            (repo_root / "backend/composer.lock").read_text(encoding="utf-8")
        )
    except (OSError, ValueError, subprocess.CalledProcessError) as exc:
        raise RuntimeError(
            "Unable to verify production Composer lockfile compatibility."
        ) from exc

    if (
        not isinstance(previous, dict)
        or not isinstance(current, dict)
        or not isinstance(previous.get("packages"), list)
        or not isinstance(current.get("packages"), list)
    ):
        raise RuntimeError("Composer lockfiles must contain production packages arrays.")

    # dev-only packages may differ without changing production vendor/. Do not
    # compare content-hash, which can change solely for require-dev metadata.
    for section in (
        "packages", "platform", "platform-overrides", "plugin-api-version",
        "aliases", "stability-flags", "minimum-stability", "prefer-stable",
    ):
        if previous.get(section) != current.get(section):
            raise RuntimeError(
                f"Composer lockfile production {section} differs from the "
                "supported base; Update ZIP excludes vendor/. Build and "
                "validate a full dependency-inclusive deployment instead."
            )
    return True


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

    # Public uploads and Laravel's public/storage mount on a reused cPanel/
    # shared-host staging tree are live user data, NOT release source.
    # The runtime inventory recurses into backend/public/**. A physical
    # storage directory (rather than the usual symlink) must be excluded
    # before any ZIP is written. Case-folding also blocks extractor aliases.
    folded_path = path.casefold()
    if any(
        folded_path == root or folded_path.startswith(root + "/")
        for root in (
            "backend/public/uploads",
            "public/uploads",
            "backend/public/storage",
            "public/storage",
        )
    ):
        raise RuntimeError(f"Public uploaded/runtime content is not allowed in updates: {path}")

    # Keep G005's recursively scanned runtime trees at least as strict as
    # the G004 Setup package. A staged package-manager auth file can contain
    # bearer tokens despite having a perfectly portable ZIP member name.
    package_credentials = {"auth.json", ".npmrc", ".netrc", ".pypirc", ".yarnrc.yml"}
    # Recursively staged assets may contain credential homes with generic
    # secret filenames (credentials, config.json), not just private keys.
    credential_directories = {".aws", ".ssh", ".docker", ".kube", ".gnupg", ".azure", ".terraform"}
    credential_files = {".git-credentials", "azureprofile.json", "accesstokens.json", "terraform.tfstate", "terraform.tfstate.backup"}
    for index, part in enumerate(parts):
        # Git stores plaintext credentials in .config/git/credentials and
        # may store HTTP auth tokens in .config/git/config; block both homes.
        if part.casefold() in {"gcloud", "gh", "git"} and index > 0 and parts[index - 1].casefold() == ".config":
            raise RuntimeError(f"Credential storage is not allowed in updates: {path}")
        if part.casefold() == "application_default_credentials.json":
            raise RuntimeError(f"Credential storage is not allowed in updates: {path}")
        if part.casefold() in credential_directories or part.casefold() in credential_files:
            raise RuntimeError(f"Credential storage is not allowed in updates: {path}")
        if part.casefold() in package_credentials:
            raise RuntimeError(f"Package-manager credentials are not allowed in updates: {path}")

        if (
            part.casefold() in {
                "id_rsa", "id_ed25519", "id_ecdsa", "id_dsa",
                "credentials.json", "client_secret.json",
                "service-account.json", "service_account.json",
            }
            or part.casefold().endswith((".key", ".p12", ".pfx"))
            or (part.casefold().endswith(".pem") and any(
                marker in part.casefold() for marker in ("private", "secret")
            ))
        ):
            raise RuntimeError(f"Private key or service credentials are not allowed in updates: {path}")

        if (
            part.rstrip(" .") != part
            or any(char in '<>:"|?*' for char in part)
            or re.fullmatch(r"(?:con|conin\$|conout\$|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?", part, re.IGNORECASE)
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
        or not hasattr(os, "O_NONBLOCK")
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
            # O_NONBLOCK prevents a post-preflight FIFO swap from hanging
            # forever before the regular-file fstat check can reject it.
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | getattr(os, "O_BINARY", 0),
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


def assert_no_file_directory_collisions(filenames: list[str]) -> None:
    """Match the Laravel Verify Package file/parent collision safety rule.

    A regular ZIP file cannot simultaneously be a directory ancestor of
    another file. Python zipfile accepts such archives, while PHP ZipArchive
    and the Laravel verifier must reject them on shared-host filesystems.
    """
    file_names = {name.casefold() for name in filenames}
    for name in file_names:
        parts = name.split("/")[:-1]
        while parts:
            if "/".join(parts) in file_names:
                raise RuntimeError(
                    f"Update ZIP contains a file/directory collision: {name}"
                )
            parts.pop()


def build_zip(repo_root: Path, entries: list[tuple[str, str]], output: Path) -> None:
    # Preflight all sources and pin their reviewed inode metadata. Hold no
    # unbounded number of open descriptors while scanning a full Laravel tree.
    verified: list[tuple[str, str, os.stat_result]] = []
    folded_names: set[str] = set()
    # Laravel's default Verify Package limits apply to expanded ZIP members.
    # Reject oversized selected inputs BEFORE allocating a temporary archive
    # or spending hosted runner resources compressing a doomed package.
    if len(entries) > MAX_UPDATE_ZIP_MEMBERS:
        raise RuntimeError("Update ZIP input exceeds runtime extraction budget: member count.")
    assert_no_file_directory_collisions([name for name, _ in entries])
    expanded_bytes = 0
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
        identity = reviewed.lstat()
        if (
            identity.st_size < 0
            or identity.st_size > MAX_UPDATE_ENTRY_UNCOMPRESSED_BYTES
            or identity.st_size > MAX_UPDATE_TOTAL_UNCOMPRESSED_BYTES - expanded_bytes
        ):
            raise RuntimeError("Update ZIP input exceeds runtime extraction budget: expanded size.")
        expanded_bytes += identity.st_size
        verified.append((canonical, source_relative, identity))

    # Rebuilding must never silently replace a previously validated/signed
    # release archive. Include dangling symlinks, which Path.exists() misses.
    if os.path.lexists(output):
        raise RuntimeError(f"Update ZIP output already exists; refusing overwrite: {output}")

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

        # The staging archive is complete. Publish ONLY if nobody has
        # published first, with a same-directory atomic no-replace hard link.
        # os.replace() would overwrite an already verified/signed ZIP in a
        # concurrent builder race. If links are unsupported, fail closed.
        try:
            os.link(temporary, output)
        except FileExistsError as exc:
            raise RuntimeError(
                f"Update ZIP output already exists; refusing overwrite: {output}"
            ) from exc
        except OSError as exc:
            raise RuntimeError(
                "Safe exclusive Update ZIP publication unavailable; no archive published."
            ) from exc
    finally:
        temporary.unlink(missing_ok=True)


def reject_existing_release_outputs(paths: list[Path]) -> None:
    # A previously checked/signed manifest or hash must be as immutable as
    # the ZIP it describes. This also detects dangling symlinks.
    for path in paths:
        if os.path.lexists(path):
            raise RuntimeError(f"Update release output already exists; refusing overwrite: {path}")


def publish_release_sidecar(path: Path, content: str) -> None:
    reject_existing_release_outputs([path])
    fd, temporary_name = tempfile.mkstemp(
        prefix=f".{path.name}.", suffix=".staging", dir=path.parent
    )
    temporary = Path(temporary_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as staged:
            staged.write(content)
            staged.flush()
            os.fsync(staged.fileno())

        # Same-directory exclusive publication. Do not use write_text() on
        # the final path: it truncates a concurrently verified sidecar.
        try:
            os.link(temporary, path)
        except FileExistsError as exc:
            raise RuntimeError(
                f"Update release output already exists; refusing overwrite: {path}"
            ) from exc
        except OSError as exc:
            raise RuntimeError(
                f"Safe exclusive Update release sidecar publication unavailable: {path}"
            ) from exc
    finally:
        temporary.unlink(missing_ok=True)


def assert_staged_release_member_unchanged(
    path: Path, reviewed: os.stat_result, expected_links: int
) -> None:
    """Fail closed if a staged release member was replaced or rewritten.

    Hard-linking a staged file legitimately changes its ctime/link count;
    compare ctime only before publication, and inode/size/mtime both before
    and after. lstat (not stat) prevents post-preflight symlink swaps.
    """
    try:
        current = path.lstat()
    except OSError as exc:
        raise RuntimeError(
            f"Staged Update release member changed during publication: {path}"
        ) from exc
    if (
        not stat.S_ISREG(current.st_mode)
        or current.st_dev != reviewed.st_dev
        or current.st_ino != reviewed.st_ino
        or current.st_size != reviewed.st_size
        or current.st_mtime_ns != reviewed.st_mtime_ns
        or current.st_nlink != expected_links
        or (expected_links == 1 and current.st_ctime_ns != reviewed.st_ctime_ns)
    ):
        raise RuntimeError(
            f"Staged Update release member changed during publication: {path}"
        )


def sha256_pinned_release_member(
    path: Path,
    reviewed: os.stat_result,
    expected_links: int,
) -> str:
    """Hash the exact reviewed regular inode; detect writes during the read.

    A hardlink changes ctime, so postlink identity checks cannot alone prove
    that same-size bytes were not rewritten with their mtime restored.
    """
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    try:
        fd = os.open(path, flags)
        with os.fdopen(fd, "rb") as stream:
            first = os.fstat(stream.fileno())
            if (
                not stat.S_ISREG(first.st_mode)
                or (first.st_dev, first.st_ino, first.st_size, first.st_mtime_ns)
                != (reviewed.st_dev, reviewed.st_ino, reviewed.st_size, reviewed.st_mtime_ns)
                or first.st_nlink != expected_links
                or (expected_links == 1 and first.st_ctime_ns != reviewed.st_ctime_ns)
            ):
                raise RuntimeError(f"Update release member changed before hashing: {path}")
            checksum = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                checksum.update(chunk)
            last = os.fstat(stream.fileno())
            if (
                (first.st_dev, first.st_ino, first.st_size, first.st_mtime_ns,
                 first.st_ctime_ns, first.st_nlink)
                != (last.st_dev, last.st_ino, last.st_size, last.st_mtime_ns,
                    last.st_ctime_ns, last.st_nlink)
            ):
                raise RuntimeError(f"Update release member changed while hashing: {path}")
            return checksum.hexdigest()
    except OSError as exc:
        raise RuntimeError(f"Unable to hash pinned Update release member: {path}") from exc


def _reject_duplicate_manifest_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
    """Reject ambiguous JSON objects at every nesting level before publication."""
    unique: dict[str, object] = {}
    for key, value in pairs:
        if key in unique:
            raise RuntimeError("Duplicate key in Update release manifest JSON.")
        unique[key] = value
    return unique


def assert_staged_release_consistency(staging_dir: Path) -> str:
    """Refuse mismatched manifest, file list, checksum and actual ZIP bytes.

    A valid digest sidecar alone does not prove publisher authenticity. This
    independent producer preflight prevents internally inconsistent release
    groups from being published as a ready dashboard update.
    """
    manifest_path = staging_dir / "FOODEX-Update.json"
    files_path = staging_dir / "FOODEX-Update.files.txt"
    digest_path = staging_dir / "FOODEX-Update.sha256.txt"
    archive_path = staging_dir / "FOODEX-Update.zip"
    try:
        manifest = json.loads(
            manifest_path.read_text(encoding="utf-8"), object_pairs_hook=_reject_duplicate_manifest_keys
        )
        if not isinstance(manifest, dict) or not isinstance(manifest.get("delta"), dict):
            raise RuntimeError("Invalid Update release manifest.")
        expected_hash = manifest.get("sha256")
        if (
            manifest.get("package") != archive_path.name
            or manifest["delta"].get("files_manifest") != files_path.name
            or not isinstance(expected_hash, str)
            or re.fullmatch(r"[0-9a-f]{64}", expected_hash) is None
        ):
            raise RuntimeError("Update release manifest metadata does not match output names.")
        if digest_path.read_text(encoding="utf-8") != f"{expected_hash}  {archive_path.name}\n":
            raise RuntimeError("Update release checksum sidecar does not match manifest.")
        # A signed group's inventory must have one canonical byte encoding.
        # splitlines() silently accepts CRLF/CR and other line terminators;
        # the dashboard/installer may interpret those bytes differently.
        raw_file_list = files_path.read_bytes()
        if not raw_file_list.endswith(b"\n") or b"\r" in raw_file_list:
            raise RuntimeError("Update release file list must use canonical LF-terminated UTF-8.")
        try:
            file_list_text = raw_file_list.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise RuntimeError("Update release file list must use canonical LF-terminated UTF-8.") from exc
        filenames = file_list_text[:-1].split("\n")
        if not filenames or any(not name for name in filenames) or len(set(filenames)) != len(filenames):
            raise RuntimeError("Update release file list is empty or contains duplicates.")

        # A coherent ZIP, file list and SHA-256 can still be paired with a
        # substituted, non-advancing or unattributable release manifest.
        # Reject such metadata at the final publication boundary, not only
        # when the original command-line arguments are parsed.
        if manifest.get("available") is not True or manifest.get("requires_full_redeploy") is not False:
            raise RuntimeError("Update release manifest is not publishable as a ZIP update.")
        # Unsupported manifest schema, a redeploy reason on an installable
        # ZIP, or missing release notes must never pass the final publisher
        # boundary merely because the archive and digest are coherent.
        if (
            type(manifest.get("schema_version")) is not int
            or manifest["schema_version"] != 1
            or "reason" not in manifest
            or manifest["reason"] is not None
            or not isinstance(manifest.get("release_notes"), str)
            or not manifest["release_notes"].strip()
        ):
            raise RuntimeError("Update release manifest schema or release notes are invalid.")
        target = manifest.get("target_version")
        minimum = manifest.get("minimum_current_version")
        if not isinstance(target, str) or not isinstance(minimum, str):
            raise RuntimeError("Update release manifest has invalid version metadata.")
        validate_update_version_range(target, minimum)
        for field in ("source_base", "source_commit"):
            source_sha = manifest.get(field)
            if not isinstance(source_sha, str) or re.fullmatch(r"[0-9a-f]{40}", source_sha) is None:
                raise RuntimeError(f"Update release manifest has invalid {field} provenance.")
        # The dashboard consumes this denormalized summary separately from
        # the canonical signed release fields. A coherent staged archive and
        # digest must never advertise contradictory upgrade metadata.
        if "dashboard_fields" in manifest:
            dashboard = manifest["dashboard_fields"]
            identity_fields = (
                "target_version",
                "minimum_current_version",
                "source_commit",
                "sha256",
                "contains_migrations",
                "release_notes",
            )
            if not isinstance(dashboard, dict) or any(
                field not in dashboard
                or type(dashboard[field]) is not type(manifest.get(field))
                or dashboard[field] != manifest.get(field)
                for field in identity_fields
            ):
                raise RuntimeError("Update release dashboard summary does not match canonical manifest.")

        delta = manifest["delta"]
        declared_count = delta.get("file_count")
        source_count = delta.get("source_file_count")
        if (
            type(declared_count) is not int
            or declared_count != len(filenames)
            or type(source_count) is not int
            or not 1 <= source_count <= declared_count
        ):
            raise RuntimeError("Update release manifest file counts do not match ZIP selection.")

        # Root-level static mirrors are output aliases, not separate source
        # files. Require the declared source count to represent the actual
        # canonical runtime inventory, not merely fall within a loose range.
        # Both sides of the mirrored pair are checked against the ZIP below.
        canonical_source_count = sum(
            not name.startswith(PUBLIC_ROOT_MIRROR_PREFIXES) for name in filenames
        )
        if source_count != canonical_source_count:
            raise RuntimeError("Update release manifest canonical source file count mismatch.")

        # The signed manifest drives migration/backup readiness. A coherently
        # substituted ZIP+SHA256 must not hide or invent migration entries:
        # bind the exact ordered migration inventory to actual archive names
        # before the publication marker becomes visible.
        selected_migrations = [
            name for name in filenames
            if name.startswith("backend/database/migrations/")
        ]
        declared_migrations = manifest.get("migrations")
        declared_flag = manifest.get("contains_migrations")
        if (
            type(declared_flag) is not bool
            or declared_flag != bool(selected_migrations)
            or not isinstance(declared_migrations, list)
            or declared_migrations != selected_migrations
        ):
            raise RuntimeError("Update release manifest migration inventory mismatch.")

        actual_hash = hashlib.sha256()
        zip_ending = b""
        with archive_path.open("rb") as binary:
            for chunk in iter(lambda: binary.read(1024 * 1024), b""):
                actual_hash.update(chunk)
                zip_ending = (zip_ending + chunk)[-22:]
        if actual_hash.hexdigest() != expected_hash:
            raise RuntimeError("Update release ZIP digest does not match manifest.")
        # Python zipfile scans backwards for EOCD and accepts arbitrary bytes
        # appended after a valid archive. The deterministic first-party
        # builder always writes a comment-free 22-byte EOCD at the real EOF.
        # Hashing coherent extra data does not declare or authorize it.
        if len(zip_ending) != 22 or zip_ending[:4] != b"PK\x05\x06":
            raise RuntimeError("Undeclared Update release ZIP trailing payload.")
        with zipfile.ZipFile(archive_path) as archive:
            members = archive.infolist()
            # The canonical producer emits no ZIP-level comments. Coherent
            # manifest/digest sidecars do not authorize opaque unlisted payload
            # in the end-of-central-directory record.
            if archive.comment:
                raise RuntimeError("Undeclared Update release ZIP archive comment.")
            # Python's ZIP reader tolerates self-extracting/polyglot prefixes
            # and shifts central-directory member offsets to skip them. The
            # canonical first-party builder writes its first member at byte 0.
            # Reject unlisted leading bytes even if the manifest and digest
            # were coherently replaced and every declared member still reads.
            if not members or min(member.header_offset for member in members) != 0:
                raise RuntimeError("Undeclared Update release ZIP leading payload.")
            if [member.filename for member in members] != filenames:
                raise RuntimeError("Update release file list does not match ZIP members.")
            # This must agree with UpdatePackageValidator::validateArchive:
            # a readable, checksummed ZIP with a file as another member's
            # parent path is not a deployable update, including case aliases.
            assert_no_file_directory_collisions(filenames)

            # Fail before decompression if a coherent ZIP+manifest exceeds the
            # default limits enforced by Laravel's Verify Package runtime.
            # Subtraction bounds the running total without integer overflow.
            if len(members) > MAX_UPDATE_ZIP_MEMBERS:
                raise RuntimeError("Update release ZIP exceeds runtime extraction budget: member count.")
            expanded_bytes = 0
            for member in members:
                if (
                    member.file_size < 0
                    or member.file_size > MAX_UPDATE_ENTRY_UNCOMPRESSED_BYTES
                    or member.file_size > MAX_UPDATE_TOTAL_UNCOMPRESSED_BYTES - expanded_bytes
                ):
                    raise RuntimeError("Update release ZIP exceeds runtime extraction budget: expanded size.")
                expanded_bytes += member.file_size

            # Revalidate archive safety at the FINAL publication boundary.
            # Internally consistent sidecars can all be replaced together;
            # a matching digest/file list is not proof that ZIP paths, modes
            # or source-owned runtime scope remain safe for extraction.
            folded_names: set[str] = set()
            for member in members:
                canonical = safe_path(member.filename)
                # Canonical first-party ZIP members have no file comments or
                # extra fields. Do not silently publish undeclared binary
                # metadata that the manifest inventory cannot account for.
                if member.comment or member.extra:
                    raise RuntimeError(
                        f"Undeclared Update release ZIP member metadata: {canonical}"
                    )
                folded = canonical.casefold()
                if folded in folded_names:
                    raise RuntimeError(f"Ambiguous Update release ZIP member: {canonical}")
                folded_names.add(folded)
                if not (
                    is_runtime_path(canonical)
                    or canonical.startswith(PUBLIC_ROOT_MIRROR_PREFIXES)
                ):
                    raise RuntimeError(f"Non-runtime Update release ZIP member: {canonical}")
                file_type = stat.S_IFMT(member.external_attr >> 16)
                if (
                    member.is_dir()
                    or file_type not in (0, stat.S_IFREG)
                    or (member.flag_bits & 1)
                ):
                    raise RuntimeError(f"Unsafe Update release ZIP member type: {canonical}")
                # ZIP_BZIP2/ZIP_LZMA may work in Python but are not portable
                # to the baseline PHP ZipArchive/extension setup on cPanel.
                # Do not publish a coherent update that cannot be extracted.
                if member.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
                    raise RuntimeError(
                        f"Unsupported Update release ZIP compression method: {canonical}"
                    )

            # A coherent ZIP+SHA256 sidecar does not prove that every
            # compressed member can be decoded. Read ALL members to EOF so
            # zipfile checks DEFLATE/CRC integrity before any publication.
            # Stream in fixed-size chunks to avoid buffering release assets.
            for member in members:
                extracted_size = 0
                with archive.open(member) as source_stream:
                    for chunk in iter(lambda: source_stream.read(1024 * 1024), b""):
                        extracted_size += len(chunk)
                        if extracted_size > member.file_size:
                            raise RuntimeError(
                                f"Update release ZIP member size overflow: {member.filename}"
                            )
                if extracted_size != member.file_size:
                    raise RuntimeError(
                        f"Update release ZIP member truncated: {member.filename}"
                    )

            # Shared-host root assets MUST be exact mirrors of their canonical
            # backend/public files. Coherently substituted ZIP+sidecars may
            # otherwise publish independent root-only content, even with a
            # valid SHA-256 and syntactically safe member names.
            by_name = {member.filename: member for member in members}

            # The Laravel updater rejects a ZIP whose embedded VERSION
            # disagrees with its signed target_version. The publisher must
            # reject the same coherent, rehashed group BEFORE advertising it
            # as an installable Dashboard release. The minimal historical
            # staged ZIP fixture has no VERSION; actual source-built updates
            # always include this runtime file.
            version_member = by_name.get("VERSION")
            if version_member is not None:
                if version_member.file_size > 128:
                    raise RuntimeError("Update release embedded VERSION is invalid.")
                with archive.open(version_member) as version_stream:
                    embedded_version = version_stream.read(129)
                accepted_versions = (
                    target.encode("ascii"),
                    (target + "\n").encode("ascii"),
                    (target + "\r\n").encode("ascii"),
                )
                if embedded_version not in accepted_versions:
                    raise RuntimeError("Update release embedded VERSION does not match target_version.")

            for name, member in by_name.items():
                if name.startswith("backend/public/"):
                    alias = name[len("backend/public/"):]
                    if alias.startswith(PUBLIC_ROOT_MIRROR_PREFIXES) and alias not in by_name:
                        raise RuntimeError(f"Update public mirror missing alias: {alias}")
                elif name.startswith(PUBLIC_ROOT_MIRROR_PREFIXES):
                    canonical_source = "backend/public/" + name
                    source = by_name.get(canonical_source)
                    if source is None:
                        raise RuntimeError(f"Update public mirror missing canonical source: {name}")
                    if source.file_size != member.file_size:
                        raise RuntimeError(f"Update public mirror content mismatch: {name}")
                    # Compare real decompressed bytes rather than trusting the
                    # mutable CRC/size metadata of an attacker-replaced archive.
                    with archive.open(canonical_source) as source_stream:
                        with archive.open(name) as mirror_stream:
                            while True:
                                source_bytes = source_stream.read(1024 * 1024)
                                mirror_bytes = mirror_stream.read(1024 * 1024)
                                if source_bytes != mirror_bytes:
                                    raise RuntimeError(f"Update public mirror content mismatch: {name}")
                                if not source_bytes:
                                    break
        return expected_hash
    except (OSError, UnicodeError, ValueError, zipfile.BadZipFile) as exc:
        raise RuntimeError("Update release staged artifact consistency check failed.") from exc


def assert_unlinked_release_directory(directory: Path, label: str) -> None:
    """Refuse symlinked release directories and linked ancestor aliases.

    A clean private staging *leaf* does not make its output directory safe: an
    ancestor link redirects all later publication hardlinks outside the
    intended release root. Check every existing component without following
    symlinks. Reject parent traversal spelling rather than normalizing an
    alias away; caller must still protect the directory from concurrent
    writers at the host ACL boundary.
    """
    if ".." in directory.parts:
        raise RuntimeError(f"Unsafe Update release {label} directory.")
    candidate = Path(os.path.abspath(directory))
    while True:
        try:
            metadata = candidate.lstat()
        except FileNotFoundError:
            pass  # Preflight permits the future output leaf, not linked parents.
        except OSError as exc:
            raise RuntimeError(f"Unsafe Update release {label} directory.") from exc
        else:
            if not stat.S_ISDIR(metadata.st_mode):
                raise RuntimeError(f"Unsafe Update release {label} directory.")
        if candidate == candidate.parent:
            break
        candidate = candidate.parent


def publish_release_bundle(staging_dir: Path, output_dir: Path) -> None:
    """Publish all verified sidecars first; the ZIP is the last release marker.

    The staging tree is private and on the output filesystem. A failed
    publisher retracts only links to its own staged inodes under the lock.
    Readers must not interpret loose sidecars without the final ZIP as a
    complete release; this is not a cryptographic signature verifier.
    """
    names = (
        "FOODEX-Update.json",
        "FOODEX-Update.files.txt",
        "FOODEX-Update.sha256.txt",
        "FOODEX-Update.zip",
    )
    assert_unlinked_release_directory(output_dir, "output")
    assert_unlinked_release_directory(staging_dir, "staging")
    if staging_dir.is_symlink() or not staging_dir.is_dir():
        raise RuntimeError("Unsafe Update release staging directory.")
    staged_paths = [staging_dir / name for name in names]
    outputs = [output_dir / name for name in names]
    reviewed_members: dict[Path, os.stat_result] = {}
    for staged in staged_paths:
        try:
            identity = staged.lstat()
        except OSError as exc:
            raise RuntimeError(f"Missing staged Update release member: {staged}") from exc
        if not stat.S_ISREG(identity.st_mode) or identity.st_nlink != 1:
            raise RuntimeError(f"Unsafe staged Update release member: {staged}")
        reviewed_members[staged] = identity

    # Do not assume the earlier per-file existence preflight is a mutex.
    # A stale lock is intentionally fail-closed and requires manual recovery.
    lock = output_dir / ".FOODEX-Update.publish.lock"
    try:
        lock.mkdir(mode=0o700)
    except OSError as exc:
        raise RuntimeError("Exclusive Update release publication lock unavailable.") from exc

    published: list[tuple[Path, Path]] = []
    try:
        # Recheck the actual bytes and cross-file provenance while holding the
        # publisher mutex. Each staged identity is pinned again below before
        # hardlinking, and after publication against the target hardlink.
        expected_zip_hash = assert_staged_release_consistency(staging_dir)
        reviewed_hashes: dict[Path, str] = {}
        for staged in staged_paths:
            assert_staged_release_member_unchanged(staged, reviewed_members[staged], 1)
            reviewed_hashes[staged] = (
                expected_zip_hash
                if staged.name == "FOODEX-Update.zip"
                else sha256_pinned_release_member(staged, reviewed_members[staged], 1)
            )
            assert_staged_release_member_unchanged(staged, reviewed_members[staged], 1)
        reject_existing_release_outputs(outputs)
        try:
            for staged, output in zip(staged_paths, outputs):
                # A staged member can be changed AFTER the initial scan (or
                # while os.link is called). Pin the originally reviewed inode.
                assert_staged_release_member_unchanged(staged, reviewed_members[staged], 1)
                try:
                    os.link(staged, output)
                except FileExistsError as exc:
                    raise RuntimeError(
                        f"Update release output already exists; refusing overwrite: {output}"
                    ) from exc
                except OSError as exc:
                    raise RuntimeError(
                        f"Safe exclusive Update release group publication unavailable: {output}"
                    ) from exc
                # Record ownership immediately so a failed post-link check
                # retracts this publication along with earlier sidecars.
                published.append((staged, output))
                assert_staged_release_member_unchanged(staged, reviewed_members[staged], 2)
                assert_staged_release_member_unchanged(output, reviewed_members[staged], 2)
            # Verify all hardlinked outputs, not only the last one. A prior
            # sidecar can be rewritten while the final ZIP link is created.
            # These hashes are compared against pre-publication reviewed bytes
            # and a pinned descriptor, then the output names are checked again.
            for staged, output in published:
                assert_staged_release_member_unchanged(staged, reviewed_members[staged], 2)
                assert_staged_release_member_unchanged(output, reviewed_members[staged], 2)
                if sha256_pinned_release_member(output, reviewed_members[staged], 2) != reviewed_hashes[staged]:
                    raise RuntimeError(f"Published Update release contents changed: {output}")
                assert_staged_release_member_unchanged(staged, reviewed_members[staged], 2)
                assert_staged_release_member_unchanged(output, reviewed_members[staged], 2)
        except Exception as exc:
            cleanup_errors: list[str] = []
            for staged, output in reversed(published):
                try:
                    original = staged.lstat()
                    current = output.lstat()
                    if (original.st_dev, original.st_ino) != (current.st_dev, current.st_ino):
                        raise RuntimeError(f"Published Update output no longer owned: {output}")
                    output.unlink()
                except (OSError, RuntimeError) as cleanup_error:
                    cleanup_errors.append(str(cleanup_error))
            if cleanup_errors:
                raise RuntimeError(
                    "Update release group rollback incomplete; manual recovery required: "
                    + "; ".join(cleanup_errors)
                ) from exc
            raise
    finally:
        lock.rmdir()


def main() -> int:
    args = parse_args()
    validate_update_version_range(args.target_version, args.minimum_current_version)
    repo_root = Path(run_git("rev-parse", "--show-toplevel").strip())

    installed_version = (repo_root / "VERSION").read_text(encoding="utf-8").strip()
    if installed_version != args.target_version:
        raise RuntimeError(
            f"VERSION contains {installed_version!r}; expected target {args.target_version!r}."
        )

    run_git("cat-file", "-e", f"{args.base}^{{commit}}")
    verify_composer_runtime_contract(repo_root, args.base)
    lock_verified = verify_composer_lock_contract(repo_root, args.base)
    if not lock_verified:
        print(
            "composer_lock_comparison=unavailable_legacy_base; "
            "real-host production dependency compatibility remains unverified"
        )
    # Dashboard updates are cumulative/self-healing by contract. Package the
    # complete application-owned runtime so drifted shared-host installs can
    # recover missing unchanged Laravel files before migrations execute.
    files = complete_runtime_files(repo_root)
    entries = package_entries(files)

    migrations = [path for path in files if path.startswith("backend/database/migrations/")]
    contains_migrations = bool(migrations)

    output_dir = repo_root / args.output_dir
    # Refuse output-directory aliases BEFORE creating any temporary staged
    # release, then recheck after mkdir and again under the publisher lock.
    assert_unlinked_release_directory(output_dir, "output")
    output_dir.mkdir(parents=True, exist_ok=True)
    assert_unlinked_release_directory(output_dir, "output")

    with tempfile.TemporaryDirectory(prefix=".foodex-update-build-", dir=output_dir) as staging_name:
        staging_dir = Path(staging_name)
        package = staging_dir / "FOODEX-Update.zip"
        manifest_path = staging_dir / "FOODEX-Update.json"
        files_path = staging_dir / "FOODEX-Update.files.txt"
        hash_path = staging_dir / "FOODEX-Update.sha256.txt"

        # Fail before publishing a ZIP when an earlier manifest, checksum or
        # file-list exists. Final sidecar publication still checks atomically.
        reject_existing_release_outputs([output_dir / p.name for p in (package, manifest_path, files_path, hash_path)])
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

        publish_release_sidecar(
            manifest_path, json.dumps(manifest, ensure_ascii=False, indent=2) + "\n"
        )
        publish_release_sidecar(files_path, "\n".join(name for name, _ in entries) + "\n")
        publish_release_sidecar(hash_path, f"{package_hash}  {package.name}\n")

        publish_release_bundle(staging_dir, output_dir)

    package = output_dir / "FOODEX-Update.zip"

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
