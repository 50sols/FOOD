#!/usr/bin/env python3
"""Deterministic exhaustive PHPUnit Unit/Feature partition; never skip unknown suites."""
from __future__ import annotations

import argparse
import hashlib
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def collect_tests(config: Path) -> list[str]:
    root = config.parent.resolve()
    tree = ET.parse(config)
    suites = tree.getroot().find("testsuites")
    if suites is None or not list(suites):
        raise ValueError("PHPUnit testsuites are missing")
    files: set[str] = set()
    for suite in suites.findall("testsuite"):
        for source in suite:
            if source.tag == "directory":
                if source.text is None:
                    raise ValueError("Empty PHPUnit testsuite directory")
                folder = (root / source.text.strip()).resolve()
                if not folder.is_dir() or not folder.is_relative_to(root):
                    raise ValueError(f"Missing or unsafe PHPUnit directory: {source.text}")
                suffix = source.get("suffix", "Test.php")
                matches = [p for p in folder.rglob(f"*{suffix}") if p.is_file()]
                if not matches:
                    raise ValueError(f"Empty PHPUnit test directory: {source.text}")
                for path in matches:
                    if not path.resolve().is_relative_to(root):
                        raise ValueError(f"Escaping PHPUnit test path: {path}")
                    files.add(path.relative_to(root).as_posix())
            elif source.tag == "file":
                if source.text is None:
                    raise ValueError("Empty PHPUnit test path")
                file = (root / source.text.strip()).resolve()
                if not file.is_file() or not file.is_relative_to(root):
                    raise ValueError(f"Missing or unsafe PHPUnit test file: {source.text}")
                files.add(file.relative_to(root).as_posix())
            else:
                raise ValueError(f"Unsupported PHPUnit suite entry: {source.tag}")
    if not files:
        raise ValueError("No PHPUnit files discovered")
    return sorted(files)


def distribute(files: list[str], count: int) -> list[list[str]]:
    if not 2 <= count <= 16:
        raise ValueError("Shard count must be between 2 and 16")
    if len(files) < count:
        raise ValueError("Not enough tests to populate every shard")
    groups: list[list[str]] = [[] for _ in range(count)]
    for path in files:
        digest = hashlib.sha256(path.encode("utf-8")).digest()
        groups[int.from_bytes(digest[:8], "big") % count].append(path)
    if any(not shard for shard in groups):
        raise ValueError("Empty shard; refusing incomplete coverage")
    flattened = [path for group in groups for path in group]
    if sorted(flattened) != files or len(set(flattened)) != len(flattened):
        raise ValueError("Shard partition loses or duplicates tests")
    return groups


def write_shard(config: Path, paths: list[str], output: Path) -> None:
    tree = ET.parse(config)
    root = tree.getroot()
    suites = root.find("testsuites")
    assert suites is not None
    suites.clear()
    suite = ET.SubElement(suites, "testsuite", {"name": "LaravelShard"})
    for path in paths:
        ET.SubElement(suite, "file").text = path
    output.parent.mkdir(parents=True, exist_ok=True)
    tree.write(output, encoding="utf-8", xml_declaration=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", type=Path, default=Path("phpunit.xml"))
    parser.add_argument("--count", type=int, default=4)
    parser.add_argument("--index", type=int)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--verify", action="store_true")
    args = parser.parse_args()
    try:
        paths = collect_tests(args.config)
        groups = distribute(paths, args.count)
        if args.verify:
            print(f"Verified {len(paths)} unique PHPUnit test files across {args.count} nonempty shards: {[len(g) for g in groups]}")
            if args.index is None:
                return 0
        if args.index is None or args.output is None or not 0 <= args.index < args.count:
            parser.error("Generating a shard requires --index within range and --output")
        write_shard(args.config, groups[args.index], args.output)
        print(f"Shard {args.index + 1}/{args.count}: {len(groups[args.index])} test files")
        return 0
    except (ValueError, ET.ParseError, OSError) as exc:
        print(f"PHPUnit sharding refused: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
