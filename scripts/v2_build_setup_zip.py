#!/usr/bin/env python3
"""Build the G004 CI-only Setup ZIP with stable bytes for identical source trees.

This is not a release signer, authenticity verifier, or production publisher.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import os
import re
import stat
import unicodedata
import tempfile
import zipfile
from pathlib import Path
from v2_setup_staging_verify import ALLOWED_ENV_EXAMPLES, SENSITIVE_ENV_KEYS, _parse_env_template


class SetupBuildError(ValueError):
    """Unsafe or non-reproducible staging archive input."""


# Match the staging ZIP verifier's limits *before* compressed packaging, so
# an unsafe/dirty source tree cannot exhaust the builder's CI runner first.
MAX_ENTRIES = 100_000
MAX_ENTRY_SIZE = 512 * 1024 * 1024
MAX_TOTAL_SIZE = 4 * 1024 * 1024 * 1024

# Keep the producer at least as strict as the independent G004 ZIP verifier:
# client credential homes and their generic config/state files must never be
# copied into a temporary archive, even in a dirty release staging checkout.
CREDENTIAL_STORAGE_PARTS = frozenset({
    ".aws", ".ssh", ".docker", ".kube", ".gnupg", ".azure", ".terraform",
    ".git-credentials", "application_default_credentials.json",
    "azureprofile.json", "accesstokens.json", "terraform.tfstate",
    "terraform.tfstate.backup",
})

# Shared-host extractors may interpret these names differently or refuse them.
RESERVED_DEVICE = re.compile(
    r"^(?:con|conin\$|conout\$|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])(?:\..*)?$",
    re.IGNORECASE,
)


def _is_sensitive_private_file(name: str) -> bool:
    """Reject key material without blocking public CA bundles or SSH .pub files."""
    lower = name.casefold()
    return (
        lower in {
            "id_rsa", "id_ed25519", "id_ecdsa", "id_dsa",
            "credentials.json", "client_secret.json",
            "service-account.json", "service_account.json",
        }
        or lower.endswith((".key", ".p12", ".pfx"))
        or (lower.endswith(".pem") and any(
            marker in lower for marker in ("private", "secret")
        ))
    )


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
    if (
        not hasattr(os, "O_DIRECTORY")
        or not hasattr(os, "O_NOFOLLOW")
        or not hasattr(os, "O_NONBLOCK")
        or os.open not in os.supports_dir_fd
    ):
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
            parts[-1],
            # O_NONBLOCK prevents a checked regular file replaced with a FIFO
            # from blocking forever before fstat rejects its changed type.
            os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | getattr(os, "O_BINARY", 0),
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

def _copy_exact_member(source, target, expected_size: int, name: str) -> None:
    """Stream exactly the scanned bytes, rejecting truncation or growth."""
    remaining = expected_size
    while remaining:
        chunk = source.read(min(1024 * 1024, remaining))
        if not chunk:
            raise SetupBuildError(f"Setup staging member shrank during packaging: {name}")
        if len(chunk) > remaining:
            raise SetupBuildError(f"Setup staging member grew during packaging: {name}")
        target.write(chunk)
        remaining -= len(chunk)
    # Reading until EOF can consume unbounded newly appended data despite
    # a prior lstat budget. Probe just one byte instead.
    if source.read(1):
        raise SetupBuildError(f"Setup staging member grew during packaging: {name}")


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
    portable_paths = set()
    # Do not accept a NEW file identity at packaging time as the baseline:
    # a formerly clean dotenv example could gain secrets after inspection.
    preflight_identities = {}
    for source_path in paths:
        name = source_path.relative_to(source_root).as_posix()
        components = name.split("/")
        if any(
            not part or part in (".", "..") or part != part.rstrip(" .")
            or any(ch in '<>:"|?*\\\\' or ord(ch) < 32 or ord(ch) == 127 for ch in part)
            or RESERVED_DEVICE.fullmatch(part)
            for part in components
        ):
            raise SetupBuildError(f"Unsafe cross-platform Setup staging member: {name}")
        portable = unicodedata.normalize("NFC", name).casefold()
        if portable in portable_paths:
            raise SetupBuildError(f"Duplicate portable Setup staging member: {name}")
        portable_paths.add(portable)
        source_meta = source_path.lstat()
        if stat.S_ISLNK(source_meta.st_mode) or not (
            stat.S_ISDIR(source_meta.st_mode) or stat.S_ISREG(source_meta.st_mode)
        ):
            raise SetupBuildError(f"Unsafe non-regular Setup staging member: {name}")
        if stat.S_ISREG(source_meta.st_mode) and source_meta.st_nlink > 1:
            raise SetupBuildError(f"Hard-linked Setup staging member: {name}")
        # Fail before creating the temporary archive. Keep the same checks
        # in the packaging loop to detect a later swap of the staged member.
        allowed_templates = {".env.example", ".env.production.example"}
        for index, part in enumerate(components):
            folded = part.casefold()
            if index > 0 and components[index - 1].casefold() == ".config" and folded == "gcloud":
                raise SetupBuildError(f"Credential storage in Setup staging: {name}")
            if folded == ".git":
                raise SetupBuildError(f"Source control metadata in Setup staging: {name}")
            if folded in CREDENTIAL_STORAGE_PARTS:
                raise SetupBuildError(f"Credential storage in Setup staging: {name}")
            if folded in ("auth.json", ".npmrc", ".netrc", ".pypirc", ".yarnrc.yml"):
                raise SetupBuildError(f"Package manager credentials in Setup staging: {name}")
            if _is_sensitive_private_file(folded):
                raise SetupBuildError(f"Private key or service credentials in Setup staging: {name}")
            if folded == ".env" or (folded.startswith(".env.") and folded not in allowed_templates):
                raise SetupBuildError(f"Secret environment configuration in Setup staging: {name}")
        # The verifier rejects runtime data, but that is too late if the
        # builder has already serialized credentials or customer uploads to
        # a temporary ZIP. Empty Laravel storage/cache directories are safe.
        folded_parts = tuple(part.casefold() for part in source_path.relative_to(source_root).parts)
        # Public static assets must not carry executable scripts or alternate
        # server configuration from a dirty deployment staging checkout.
        # Keep the canonical Laravel front controller and root .htaccess.
        public_asset = (
            folded_parts[:2] == ("backend", "public")
            or folded_parts[:1] == ("public",)
            or folded_parts[:1] in (("assets",), ("brand",), ("demo",), ("preview",))
        )
        canonical_bootstrap = name in (
            "backend/public/index.php",
            "backend/public/.htaccess",
            "public/index.php",
            "public/.htaccess",
        )
        if public_asset and not canonical_bootstrap and any(
            re.search(r"\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)(?:\.|$)", part, re.IGNORECASE)
            or re.fullmatch(r"(?:\.htaccess|\.user\.ini|web\.config)(?:\..*)?", part, re.IGNORECASE)
            for part in source_path.relative_to(source_root).parts
        ):
            raise SetupBuildError(f"Executable public static asset in Setup staging: {name}")
        upload_roots = (
            ("backend", "public", "storage"),
            ("backend", "public", "uploads"),
            ("public", "storage"),
            ("public", "uploads"),
            ("backend", "uploads"),
        )
        if any(folded_parts[:len(root)] == root for root in upload_roots):
            raise SetupBuildError(f"Public uploaded/runtime content in Setup staging: {name}")
        if stat.S_ISREG(source_meta.st_mode) and (
            folded_parts[:2] == ("backend", "storage")
            or folded_parts[:3] == ("backend", "bootstrap", "cache")
        ):
            raise SetupBuildError(f"Runtime storage/cache content in Setup staging: {name}")
        if stat.S_ISREG(source_meta.st_mode) and source_path.name.casefold() in ALLOWED_ENV_EXAMPLES:
            # The downstream ZIP verifier runs only after publication. Never
            # serialize a filled dotenv template into even a private ZIP.
            # Match its canonical sensitive-key and quoted-value policy.
            if source_meta.st_size > 1024 * 1024:
                raise SetupBuildError("Setup environment template exceeds preflight budget.")
            with _open_verified_member(source_path, source_meta, name, source_root) as template:
                payload = template.read(1024 * 1024 + 1)
                if (len(payload) != source_meta.st_size
                    or _member_identity(os.fstat(template.fileno())) != _member_identity(source_meta)):
                    raise SetupBuildError("Setup environment template changed during preflight.")
            latest_template = source_path.lstat()
            if _member_identity(latest_template) != _member_identity(source_meta):
                raise SetupBuildError("Setup environment template changed during preflight.")
            try:
                assignments = _parse_env_template(payload.decode("utf-8"))
            except UnicodeDecodeError as exc:
                raise SetupBuildError("Invalid Setup environment template encoding.") from exc
            for secret_key in SENSITIVE_ENV_KEYS:
                safe_values = {"", "null"} if secret_key == "REDIS_PASSWORD" else {""}
                if any(value.casefold() not in safe_values for value in assignments.get(secret_key, [])):
                    raise SetupBuildError("Setup environment template contains non-empty secret fields.")
        if stat.S_ISREG(source_meta.st_mode):
            if source_meta.st_size > MAX_ENTRY_SIZE:
                raise SetupBuildError("Setup ZIP staging entry exceeds size budget.")
            preflight_total += source_meta.st_size
            if preflight_total > MAX_TOTAL_SIZE:
                raise SetupBuildError("Setup ZIP staging expanded size exceeds budget.")
        preflight_identities[name] = _member_identity(source_meta)

    output.parent.mkdir(parents=True, exist_ok=True)
    handle, temporary_name = tempfile.mkstemp(prefix=".v2-setup-", suffix=".zip", dir=output.parent)
    os.close(handle)
    temporary = Path(temporary_name)
    verification_fd = None
    published_own_inode = False
    publication_verified = False
    published_inode = None
    try:
        total_size = 0
        with zipfile.ZipFile(temporary, "w", allowZip64=True) as archive:
            for path in paths:
                name = path.relative_to(source_root).as_posix()
                meta = path.lstat()
                if _member_identity(meta) != preflight_identities[name]:
                    raise SetupBuildError(f"Setup staging member changed after preflight: {name}")
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
                for index, component in enumerate(path.relative_to(source_root).parts):
                    folded = component.casefold()
                    if index > 0 and path.relative_to(source_root).parts[index - 1].casefold() == ".config" and folded == "gcloud":
                        raise SetupBuildError(f"Credential storage in Setup staging: {name}")
                    if folded == ".git":
                        raise SetupBuildError(f"Source control metadata in Setup staging: {name}")
                    if folded in CREDENTIAL_STORAGE_PARTS:
                        raise SetupBuildError(f"Credential storage in Setup staging: {name}")
                    if folded in ("auth.json", ".npmrc", ".netrc", ".pypirc", ".yarnrc.yml"):
                        raise SetupBuildError(f"Package manager credentials in Setup staging: {name}")
                    if _is_sensitive_private_file(folded):
                        raise SetupBuildError(f"Private key or service credentials in Setup staging: {name}")
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
                        _copy_exact_member(source, target, meta.st_size, name)
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
            # Hold a descriptor to the exact hashed inode across publication:
            # the temporary pathname itself could be replaced by a rival.
            verification_fd = os.dup(archive_file.fileno())
        # Publishing must be exclusive: a second builder (or symlink) can
        # create the destination after the initial existence preflight.
        # The completed temporary archive and target share a directory, so
        # hard-link creation is atomic and never replaces that late arrival.
        # If the filesystem cannot guarantee this, fail closed.
        try:
            os.link(temporary, output)
            published_own_inode = True
            published_inode = (before.st_dev, before.st_ino)
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
        # os.link legitimately changes ctime. Comparing only inode, length and
        # restored mtime after that cannot identify a same-inode write between
        # the original hash and publication. Re-read the *same open inode*.
        with os.fdopen(verification_fd, "rb") as verification_file:
            verification_fd = None
            verification_file.seek(0)
            published_checksum = hashlib.sha256()
            for chunk in iter(lambda: verification_file.read(1024 * 1024), b""):
                published_checksum.update(chunk)
            if published_checksum.digest() != checksum.digest():
                raise SetupBuildError("Published Setup ZIP contents changed after checksum.")
        # The pathname may have been replaced *after* the initial link
        # identity check while we rehashed the pinned inode. Recheck before
        # attesting its digest. A later hostile swap still needs external
        # directory ownership controls and is not made atomic by this check.
        final_publication = output.lstat()
        if (not stat.S_ISREG(final_publication.st_mode)
            or (final_publication.st_dev, final_publication.st_ino) != published_inode):
            raise SetupBuildError("Published Setup ZIP path changed after checksum.")
        publication_verified = True
    finally:
        if verification_fd is not None:
            os.close(verification_fd)
        # Failed final verification must not leave a seemingly ready Setup ZIP.
        # Only remove the exact inode we linked; never remove a concurrent
        # replacement at that pathname. This is best-effort cleanup, not an
        # atomic guarantee against a hostile pathname swap during unlink.
        if published_own_inode and not publication_verified:
            try:
                current = output.lstat()
                if (stat.S_ISREG(current.st_mode)
                    and (current.st_dev, current.st_ino) == published_inode):
                    output.unlink()
            except FileNotFoundError:
                pass
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
