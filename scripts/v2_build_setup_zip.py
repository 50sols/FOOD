#!/usr/bin/env python3
"""Build the G004 CI-only Setup ZIP with stable bytes for identical source trees.

This is not a release signer, authenticity verifier, or production publisher.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import os
import shutil
import stat
import tempfile
import zipfile
from pathlib import Path


class SetupBuildError(ValueError):
    """Unsafe or non-reproducible staging archive input."""


# Match the staging ZIP verifier's limits *before* compressed packaging, so
# an unsafe/dirty source tree cannot exhaust the builder's CI runner first.
MAX_ENTRIES = 100_000
MAX_ENTRY_SIZE = 512 * 1024 * 1024
MAX_TOTAL_SIZE = 4 * 1024 * 1024 * 1024


def _zip_timestamp(epoch: int) -> tuple[int, int, int, int, int, int]:
    moment = dt.datetime.fromtimestamp(epoch, tz=dt.timezone.utc)
    if not 1980 <= moment.year <= 2107:
        raise SetupBuildError("Commit timestamp is outside the portable ZIP date range.")
    # ZIP/DOS stores only even seconds. Use the Git commit timestamp, not CI time.
    return moment.year, moment.month, moment.day, moment.hour, moment.minute, moment.second // 2 * 2


def _member_identity(meta: os.stat_result) -> tuple[int, int, int, int, int]:
    """Reject a replaced or modified source inode, even if size/mtime are restored."""
    return meta.st_dev, meta.st_ino, meta.st_size, meta.st_mtime_ns, meta.st_ctime_ns


def _open_verified_member(path: Path, meta: os.stat_result, name: str, source_root: Path):
    """Open scanned file through verified directories, never following parent links."""
    # O_NOFOLLOW on the final file alone does not protect against a parent
    # directory being swapped for a symlink after the rglob scan. Traverse
    # from the trusted root using dirfd-relative opens; each component must
    # still be a real directory when it is opened.
    if not hasattr(os, "O_DIRECTORY") or not hasattr(os, "O_NOFOLLOW") or os.open not in os.supports_dir_fd:
        raise SetupBuildError("Safe directory-relative Setup staging opens are unavailable.")
    parts = Path(name).parts
    if not parts or any(part in ("", ".", "..") for part in parts):
        raise SetupBuildError(f"Unsafe Setup staging member name: {name}")
    directory_fd = None
    descriptor = None
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    try:
        directory_fd = os.open(source_root, directory_flags)
        for component in parts[:-1]:
            child_fd = os.open(component, directory_flags, dir_fd=directory_fd)
            os.close(directory_fd)
            directory_fd = child_fd
        descriptor = os.open(
            parts[-1], os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_BINARY", 0),
            dir_fd=directory_fd,
        )
        opened = os.fstat(descriptor)
        current = path.lstat()
        if (
            not stat.S_ISREG(opened.st_mode)
            or not stat.S_ISREG(current.st_mode)
            or _member_identity(opened) != _member_identity(meta)
            or _member_identity(current) != _member_identity(meta)
        ):
            raise SetupBuildError(f"Setup staging member changed before packaging: {name}")
        source = os.fdopen(descriptor, "rb")
        descriptor = None
        return source
    except (OSError, ValueError) as exc:
        raise SetupBuildError(f"Setup staging member changed before packaging: {name}") from exc
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if directory_fd is not None:
            os.close(directory_fd)

def build_setup_zip(source_root: Path, output: Path, source_epoch: int) -> str:
    """Write a fresh, sorted, timestamp-normalized archive; return SHA-256."""
    if not source_root.is_dir() or source_root.is_symlink():
        raise SetupBuildError("Setup staging root must be a real directory.")
    source_root = source_root.resolve(strict=True)
    output = output.absolute()
    if output.exists() or output.is_symlink():
        raise SetupBuildError("Refusing to replace an existing Setup ZIP.")
    if output.parent.resolve() == source_root or source_root in output.parent.resolve().parents:
        raise SetupBuildError("Setup ZIP cannot be written inside its staging root.")

    timestamp = _zip_timestamp(source_epoch)
    # A post-sort count would allocate an unbounded path list before
    # enforcing MAX_ENTRIES. Stop the directory walk at the first excess
    # member, then sort only the bounded, accepted set.
    paths = []
    for candidate in source_root.rglob("*"):
        if len(paths) >= MAX_ENTRIES:
            raise SetupBuildError("Setup ZIP staging member count exceeds budget.")
        paths.append(candidate)
    if not paths:
        raise SetupBuildError("Setup ZIP staging root is empty.")
    paths.sort(key=lambda p: p.relative_to(source_root).as_posix().encode("utf-8"))

    # Calculate the entire expanded-size budget before opening any staging
    # member or allocating a temporary ZIP. Checking only in the packaging
    # loop can start compressing an oversized tree before rejecting it.
    preflight_total = 0
    for source_path in paths:
        source_meta = source_path.lstat()
        name = source_path.relative_to(source_root).as_posix()
        if stat.S_ISLNK(source_meta.st_mode) or not (
            stat.S_ISDIR(source_meta.st_mode) or stat.S_ISREG(source_meta.st_mode)
        ):
            raise SetupBuildError(f"Unsafe non-regular Setup staging member: {name}")
        if stat.S_ISREG(source_meta.st_mode) and source_meta.st_nlink > 1:
            raise SetupBuildError(f"Hard-linked Setup staging member: {name}")
        # Fail before creating the temporary archive. Keep the same checks
        # in the packaging loop to detect a later swap of the staged member.
        allowed_templates = {".env.example", ".env.production.example"}
        for part in source_path.relative_to(source_root).parts:
            folded = part.casefold()
            if folded == ".git":
                raise SetupBuildError(f"Source control metadata in Setup staging: {name}")
            if folded == ".env" or (folded.startswith(".env.") and folded not in allowed_templates):
                raise SetupBuildError(f"Secret environment configuration in Setup staging: {name}")
        if stat.S_ISREG(source_meta.st_mode):
            if source_meta.st_size > MAX_ENTRY_SIZE:
                raise SetupBuildError("Setup ZIP staging entry exceeds size budget.")
            preflight_total += source_meta.st_size
            if preflight_total > MAX_TOTAL_SIZE:
                raise SetupBuildError("Setup ZIP staging expanded size exceeds budget.")

    output.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary_name = tempfile.mkstemp(prefix=".v2-setup-", suffix=".zip", dir=output.parent)
    os.close(handle)
    temporary = Path(temporary_name)
    try:
        total_size = 0
        with zipfile.ZipFile(temporary, "w", allowZip64=True) as archive:
            for path in paths:
                meta = path.lstat()
                if stat.S_ISLNK(meta.st_mode) or not (stat.S_ISDIR(meta.st_mode) or stat.S_ISREG(meta.st_mode)):
                    raise SetupBuildError(f"Unsafe non-regular Setup staging member: {path.relative_to(source_root)!s}")
                if stat.S_ISREG(meta.st_mode) and meta.st_nlink > 1:
                    raise SetupBuildError(f"Hard-linked Setup staging member: {path.relative_to(source_root)!s}")
                if stat.S_ISREG(meta.st_mode):
                    if meta.st_size > MAX_ENTRY_SIZE:
                        raise SetupBuildError("Setup ZIP staging entry exceeds size budget.")
                    total_size += meta.st_size
                    if total_size > MAX_TOTAL_SIZE:
                        raise SetupBuildError("Setup ZIP staging expanded size exceeds budget.")

                name = path.relative_to(source_root).as_posix()
                # Never stage live dotenv configuration, even briefly. The
                # downstream ZIP verifier rejects these too, but a builder
                # must fail before publishing a secret-bearing archive.
                allowed_templates = {".env.example", ".env.production.example"}
                for component in path.relative_to(source_root).parts:
                    folded = component.casefold()
                    if folded == ".git":
                        raise SetupBuildError(f"Source control metadata in Setup staging: {name}")
                    if folded == ".env" or (folded.startswith(".env.") and folded not in allowed_templates):
                        raise SetupBuildError(f"Secret environment configuration in Setup staging: {name}")
                is_dir = stat.S_ISDIR(meta.st_mode)
                info = zipfile.ZipInfo(name + "/" if is_dir else name, date_time=timestamp)
                info.create_system = 3
                info.external_attr = (
                    ((stat.S_IFDIR | 0o755) << 16) | 0x10
                    if is_dir else
                    ((stat.S_IFREG | (0o755 if meta.st_mode & 0o111 else 0o644)) << 16)
                )
                info.compress_type = zipfile.ZIP_STORED if is_dir else zipfile.ZIP_DEFLATED
                info.file_size = 0 if is_dir else meta.st_size
                if is_dir:
                    archive.writestr(info, b"")
                else:
                    with _open_verified_member(path, meta, name, source_root) as source, archive.open(info, "w", force_zip64=False) as target:
                        shutil.copyfileobj(source, target, length=1024 * 1024)
                        if _member_identity(os.fstat(source.fileno())) != _member_identity(meta):
                            raise SetupBuildError(f"Setup staging member changed during packaging: {name}")
                    latest = path.lstat()
                    if not stat.S_ISREG(latest.st_mode) or _member_identity(latest) != _member_identity(meta):
                        raise SetupBuildError(f"Setup staging member changed during packaging: {name}")
        # Hash the completed, private staging inode, not the public output
        # pathname. Opening output after publication is a substitution race:
        # the published ZIP might be replaced before the checksum is read.
        checksum = hashlib.sha256()
        with temporary.open("rb") as archive_file:
            before = os.fstat(archive_file.fileno())
            for chunk in iter(lambda: archive_file.read(1024 * 1024), b""):
                checksum.update(chunk)
            if _member_identity(os.fstat(archive_file.fileno())) != _member_identity(before):
                raise SetupBuildError("Setup ZIP staging output changed during checksum.")
        # Publishing must be exclusive: a second builder (or symlink) can
        # create the destination after the initial existence preflight.
        # The completed temporary archive and target share a directory, so
        # hard-link creation is atomic and never replaces that late arrival.
        # If the filesystem cannot guarantee this, fail closed.
        try:
            os.link(temporary, output)
        except FileExistsError as exc:
            raise SetupBuildError("Refusing to replace an existing Setup ZIP.") from exc
        except OSError as exc:
            raise SetupBuildError("Unable to publish Setup ZIP without overwrite.") from exc
        # Both names must still reference the same unmodified regular inode.
        # A rival os.replace(output, ...) after publication must not turn the
        # returned digest into an attestation for unrelated public bytes.
        staged = temporary.lstat()
        published = output.lstat()
        # Creating a hard link legitimately updates st_ctime_ns. Compare
        # size/mtime and inode to the pre-publication snapshot, then compare
        # the full post-link identities of both directory entries.
        if (not stat.S_ISREG(published.st_mode)
            or (staged.st_dev, staged.st_ino, staged.st_size, staged.st_mtime_ns)
                != (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns)
            or _member_identity(published) != _member_identity(staged)):
            raise SetupBuildError("Published Setup ZIP changed during checksum verification.")
    finally:
        temporary.unlink(missing_ok=True)

    return checksum.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--source-date-epoch", type=int, required=True)
    args = parser.parse_args()
    digest = build_setup_zip(args.source_root, args.output, args.source_date_epoch)
    print(f"UNTRUSTED_CI_STAGING_ONLY sha256={digest}")


if __name__ == "__main__":
    main()
