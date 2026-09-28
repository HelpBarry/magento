#!/usr/bin/env python3
"""Prepares a release in the working tree: bumps the version in composer.json and etc/module.xml,
turns CHANGELOG.md's "## [Unreleased]" section into the new version's section, and adds the matching
entry to RELEASE_NOTES.md (the format pasted into the Magento Marketplace).

Usage: scripts/prepare_release.py patch|minor|major [--date YYYY-MM-DD] [--notes-file PATH]
Prints the new version. --notes-file receives the changelog section, for the GitHub release body.
"""

import argparse
import datetime
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
UNRELEASED = "## [Unreleased]"

# CHANGELOG (Keep a Changelog) headings -> RELEASE_NOTES.md headings.
NOTES_HEADINGS = {
    "Added": "Improvements",
    "Changed": "Improvements",
    "Deprecated": "Deprecations",
    "Removed": "Removals",
    "Fixed": "Bug Fixes",
    "Security": "Security",
}


def fail(message):
    sys.exit(f"prepare_release: {message}")


def bump(version, part):
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
    if not match:
        fail(f"current version {version!r} is not MAJOR.MINOR.PATCH")
    major, minor, patch = map(int, match.groups())
    if part == "major":
        return f"{major + 1}.0.0"
    if part == "minor":
        return f"{major}.{minor + 1}.0"
    return f"{major}.{minor}.{patch + 1}"


def replace_once(path, pattern, replacement):
    text = path.read_text()
    new_text, count = re.subn(pattern, replacement, text, count=1)
    if count != 1:
        fail(f"could not update the version in {path.relative_to(ROOT)}")
    path.write_text(new_text)


def take_unreleased(changelog):
    """Returns (text before the section, section body, text after the section)."""
    start = changelog.find(UNRELEASED)
    if start == -1:
        fail(f"CHANGELOG.md has no '{UNRELEASED}' section")
    body_start = start + len(UNRELEASED)
    next_section = re.search(r"^## ", changelog[body_start:], flags=re.M)
    body_end = body_start + next_section.start() if next_section else len(changelog)
    body = changelog[body_start:body_end].strip()
    if not re.search(r"^- ", body, flags=re.M):
        fail(f"'{UNRELEASED}' in CHANGELOG.md has no entries; add what changed before releasing")
    return changelog[:start], body, changelog[body_end:]


def release_notes_entry(version, date, body):
    groups = {}
    heading = None
    for line in body.splitlines():
        line = line.rstrip()
        if line.startswith("### "):
            name = line[4:].strip()
            heading = NOTES_HEADINGS.get(name, name)
            groups.setdefault(heading, [])
        elif line.startswith("- "):
            if heading is None:
                fail("CHANGELOG entries must sit under a '### Added/Changed/Fixed/...' heading")
            groups[heading].append(line[2:].strip())
        elif line.strip() and heading is not None and groups[heading]:
            # Continuation line of a wrapped bullet.
            groups[heading][-1] += " " + line.strip()

    lines = [f"{version}:", "Stability: Stable Build", "Description:", f"Release {version} ({date})", ""]
    for heading, bullets in groups.items():
        if bullets:
            # Existing release notes list items without a closing period.
            lines += [heading, *("- " + bullet.rstrip(".") for bullet in bullets), ""]
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("bump", choices=["patch", "minor", "major"])
    parser.add_argument("--date", default=datetime.date.today().isoformat())
    parser.add_argument("--notes-file")
    args = parser.parse_args()

    composer_path = ROOT / "composer.json"
    current = json.loads(composer_path.read_text()).get("version")
    if not current:
        fail("composer.json has no version")
    version = bump(current, args.bump)

    changelog_path = ROOT / "CHANGELOG.md"
    before, body, after = take_unreleased(changelog_path.read_text())

    replace_once(composer_path, r'("version":\s*")[^"]+(")', rf"\g<1>{version}\g<2>")
    replace_once(ROOT / "etc/module.xml", r'(setup_version=")[^"]+(")', rf"\g<1>{version}\g<2>")

    changelog_path.write_text(
        f"{before}{UNRELEASED}\n\n## [{version}] - {args.date}\n{body}\n\n{after.lstrip()}"
    )

    notes_path = ROOT / "RELEASE_NOTES.md"
    notes = notes_path.read_text()
    header = re.match(r"# .*\n\nRelease date: .*\n\n", notes)
    if not header:
        fail("RELEASE_NOTES.md does not start with the '# ... Release Notes' / 'Release date:' header")
    notes_path.write_text(
        f"# Bluebarry Magento 2 Module {version} Release Notes\n\nRelease date: {args.date}\n\n"
        + release_notes_entry(version, args.date, body)
        + "\n"
        + notes[header.end():]
    )

    if args.notes_file:
        pathlib.Path(args.notes_file).write_text(body + "\n")
    print(version)


if __name__ == "__main__":
    main()
