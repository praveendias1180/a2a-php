#!/usr/bin/env python3
"""Report upstream A2A changes since our pins, as one GitHub issue.

Reads the pins from the files that actually use them (so they can't drift):
  - Python SDK commit ........ UPSTREAM.md (a2a-python row)
  - Python SDK release ....... tests/Interop/python/requirements.txt (a2a-sdk==X)
  - spec (a2a.proto) ......... buf.gen.yaml (ref:)
  - TCK commit ............... .github/workflows/ci.yml (A2A_TCK_REF)

and lists new upstream commits that touch the public API: the Python SDK's
__init__.py files (its __all__ lists) and types, a2a.proto / the spec, and the
TCK's requirements. Then it creates or updates ONE open issue labelled
"upstream" (or prints the body with --dry-run).

Usage:
  GH_TOKEN=... python3 scripts/upstream-watch.py --repo owner/name
  python3 scripts/upstream-watch.py --dry-run      # print the issue body only
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
API = "https://api.github.com"
TITLE = "Upstream A2A changes to review"
LABEL = "upstream"


def api(path: str, method: str = "GET", body: dict | None = None) -> object:
    req = urllib.request.Request(API + path, method=method, data=json.dumps(body).encode() if body else None)
    req.add_header("Accept", "application/vnd.github+json")
    req.add_header("X-GitHub-Api-Version", "2022-11-28")
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read() or b"null")


def pins() -> dict[str, str]:
    upstream = (ROOT / "UPSTREAM.md").read_text()
    python_commit = re.search(r"a2a-python\)\s*\|\s*`([0-9a-f]{7,40})`", upstream)
    spec_ref = re.search(r"^\s*ref:\s*(\S+)", (ROOT / "buf.gen.yaml").read_text(), re.M)
    tck_ref = re.search(r"A2A_TCK_REF:\s*([0-9a-f]{7,40})", (ROOT / ".github/workflows/ci.yml").read_text())
    sdk = re.search(r"a2a-sdk\[[^\]]*\]==([\w.]+)", (ROOT / "tests/Interop/python/requirements.txt").read_text())
    missing = [n for n, m in [("python commit", python_commit), ("spec ref", spec_ref), ("tck ref", tck_ref), ("a2a-sdk", sdk)] if not m]
    if missing:
        sys.exit(f"could not read pins: {', '.join(missing)}")
    return {
        "python": python_commit.group(1),  # type: ignore[union-attr]
        "spec": spec_ref.group(1),  # type: ignore[union-attr]
        "tck": tck_ref.group(1),  # type: ignore[union-attr]
        "sdk": sdk.group(1),  # type: ignore[union-attr]
    }


def changes(repo: str, base: str, interesting: re.Pattern[str]) -> tuple[int, list[tuple[str, str, str, list[str]]]]:
    """(total new commits, [(sha, date, subject, interesting files)]) on the default branch since base."""
    default = api(f"/repos/{repo}")["default_branch"]  # type: ignore[index]
    cmp = api(f"/repos/{repo}/compare/{base}...{default}")
    total = cmp["ahead_by"]  # type: ignore[index]
    hits = []
    for c in cmp["commits"]:  # type: ignore[index]
        detail = api(f"/repos/{repo}/commits/{c['sha']}")
        files = [f["filename"] for f in detail["files"] if interesting.search(f["filename"])]  # type: ignore[index]
        if files:
            hits.append((c["sha"][:7], c["commit"]["committer"]["date"][:10], c["commit"]["message"].splitlines()[0], files))
    return total, hits


def section(title: str, repo: str, base: str, total: int, hits: list[tuple[str, str, str, list[str]]]) -> str:
    out = [f"### {title}", "", f"Pinned at `{base}`. **{total}** new commit(s) on the default branch; **{len(hits)}** touch the public API."]
    if hits:
        out += ["", "| Commit | Date | Subject | Files |", "|---|---|---|---|"]
        for sha, date, subject, files in hits:
            shown = ", ".join(f"`{f}`" for f in files[:6]) + (f" (+{len(files) - 6})" if len(files) > 6 else "")
            out.append(f"| [`{sha}`](https://github.com/{repo}/commit/{sha}) | {date} | {subject.replace('|', '/')} | {shown} |")
    out.append(f"\nFull diff: https://github.com/{repo}/compare/{base}...HEAD")
    return "\n".join(out)


def build_body() -> tuple[str, bool]:
    p = pins()
    parts = ["Automated weekly check (`.github/workflows/upstream-watch.yml`). Review, port what matters, then bump the pins in their own PR (see `UPSTREAM.md`).", ""]
    anything = False

    total, hits = changes("a2aproject/a2a-python", p["python"], re.compile(r"(^src/a2a/(.*/)?__init__\.py$|^src/a2a/types/|^src/a2a/client/client\.py$|^src/a2a/server/request_handlers/request_handler\.py$)"))
    anything |= bool(hits)
    parts.append(section("Python SDK (`a2aproject/a2a-python`)", "a2aproject/a2a-python", p["python"], total, hits))

    pypi = json.loads(urllib.request.urlopen("https://pypi.org/pypi/a2a-sdk/json", timeout=30).read())["info"]["version"]
    parts += ["", f"Released `a2a-sdk` on PyPI: **{pypi}** (interop tests pin **{p['sdk']}**)."]
    anything |= pypi != p["sdk"]

    total, hits = changes("a2aproject/A2A", p["spec"], re.compile(r"^specification/"))
    tags = [t["name"] for t in api("/repos/a2aproject/A2A/tags?per_page=10")]  # type: ignore[union-attr]
    anything |= bool(hits)
    parts += ["", section("Specification (`a2aproject/A2A`)", "a2aproject/A2A", p["spec"], total, hits), "", "Latest spec tags: " + ", ".join(f"`{t}`" for t in tags[:5])]

    total, hits = changes("a2aproject/a2a-tck", p["tck"], re.compile(r"^(tck/requirements/|specification/|tests/compatibility/)"))
    anything |= bool(hits)
    parts += ["", section("TCK (`a2aproject/a2a-tck`)", "a2aproject/a2a-tck", p["tck"], total, hits)]
    return "\n".join(parts) + "\n", anything


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", "praveendias1180/a2a-php"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    body, anything = build_body()
    if args.dry_run:
        print(f"# {TITLE}\n\n{body}\nwould {'open/update' if anything else 'not touch'} the issue")
        return

    existing = api(f"/repos/{args.repo}/issues?state=open&labels={LABEL}&per_page=10")
    issue = next((i for i in existing if i.get("title") == TITLE), None)  # type: ignore[union-attr]
    if issue:
        api(f"/repos/{args.repo}/issues/{issue['number']}", "PATCH", {"body": body})
        print(f"updated #{issue['number']}")
    elif anything:
        try:
            api(f"/repos/{args.repo}/labels", "POST", {"name": LABEL, "color": "5319e7", "description": "Upstream A2A changes to review"})
        except urllib.error.HTTPError:
            pass  # already exists
        created = api(f"/repos/{args.repo}/issues", "POST", {"title": TITLE, "body": body, "labels": [LABEL]})
        print(f"opened #{created['number']}")  # type: ignore[index]
    else:
        print("nothing new upstream; no issue opened")


if __name__ == "__main__":
    main()
