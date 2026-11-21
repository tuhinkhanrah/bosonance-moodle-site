import argparse
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


ROOT = Path(__file__).resolve().parent.parent
CATEGORIES = Path(
    "site-admin", "courses", "manage-courses-and-categories", "course-categories"
)


class UploadError(Exception):
    pass


@dataclass(frozen=True)
class Item:
    kind: str
    id: int
    expected: str
    file: str
    html: str


def load_manifest(path, root=ROOT):
    try:
        data = json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise UploadError(f"Cannot read manifest {path}: {exc}") from exc
    if not isinstance(data, dict) or set(data) != {"items"}:
        raise UploadError("Manifest must be an object containing only an items array")
    if not isinstance(data["items"], list) or not data["items"]:
        raise UploadError("Manifest items must be a non-empty array")

    root = root.resolve()
    categories = (root / CATEGORIES).resolve()
    items = []
    seen_targets = set()
    seen_files = set()
    for index, entry in enumerate(data["items"], 1):
        if not isinstance(entry, dict) or set(entry) != {"kind", "id", "expected", "file"}:
            raise UploadError(f"Item {index} must contain kind, id, expected and file")
        kind, target_id = entry["kind"], entry["id"]
        expected, file = entry["expected"], entry["file"]
        if kind not in ("course", "category"):
            raise UploadError(f"Item {index}: kind must be course or category")
        if type(target_id) is not int or target_id <= 0:
            raise UploadError(f"Item {index}: id must be a positive integer")
        if not isinstance(expected, str) or not expected.strip():
            raise UploadError(f"Item {index}: expected must be a non-empty name")
        if not isinstance(file, str) or not file:
            raise UploadError(f"Item {index}: file must be a repository-relative path")
        relative = Path(file)
        if relative.is_absolute():
            raise UploadError(f"Item {index}: file must be repository-relative")
        try:
            source = (root / relative).resolve(strict=True)
            category_relative = source.relative_to(categories)
            source_relative = source.relative_to(root)
        except (OSError, ValueError) as exc:
            raise UploadError(f"Item {index}: file is missing or outside course-categories: {file}") from exc
        is_course = len(category_relative.parts) >= 3 and category_relative.parts[-3] == "courses"
        if (
            source.name != "description.html"
            or ("courses" in category_relative.parts and not is_course)
            or is_course != (kind == "course")
        ):
            raise UploadError(f"Item {index}: file is not a {kind} description.html: {file}")
        if not source.is_file():
            raise UploadError(f"Item {index}: not a file: {file}")
        target = (kind, target_id)
        if target in seen_targets or source in seen_files:
            raise UploadError(f"Item {index}: duplicate target or source file")
        seen_targets.add(target)
        seen_files.add(source)
        try:
            html = source.read_text(encoding="utf-8-sig")
        except (OSError, UnicodeError) as exc:
            raise UploadError(f"Item {index}: cannot read {file}: {exc}") from exc
        if not html.strip():
            raise UploadError(f"Item {index}: HTML file is empty: {file}")
        items.append(Item(kind, target_id, expected, str(source_relative), html))
    return items


def select_items(items, category_ids, course_ids):
    requested = {("category", target_id) for target_id in category_ids}
    requested.update(("course", target_id) for target_id in course_ids)
    if not requested:
        return items
    invalid = sorted((kind, target_id) for kind, target_id in requested if target_id <= 0)
    if invalid:
        raise UploadError("Selected IDs must be positive integers")
    available = {(item.kind, item.id) for item in items}
    missing = sorted(requested - available)
    if missing:
        targets = ", ".join(f"{kind} {target_id}" for kind, target_id in missing)
        raise UploadError(f"Selected targets not found in manifest: {targets}")
    return [item for item in items if (item.kind, item.id) in requested]


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise HTTPError(req.full_url, code, "Redirect refused", headers, fp)


class MoodleClient:
    def __init__(self, base_url, token):
        try:
            parsed = urlsplit(base_url)
        except ValueError as exc:
            raise UploadError("Invalid Moodle base URL") from exc
        if (
            parsed.scheme != "https"
            or not parsed.hostname
            or parsed.username is not None
            or parsed.password is not None
            or parsed.query
            or parsed.fragment
        ):
            raise UploadError("Moodle URL must be an HTTPS base URL without credentials, query or fragment")
        if not token or not token.strip():
            raise UploadError("Set the MOODLE_TOKEN environment variable")
        self.endpoint = base_url.rstrip("/") + "/webservice/rest/server.php"
        self.token = token
        self.opener = build_opener(NoRedirect())

    def call(self, function, parameters):
        fields = {
            "wstoken": self.token,
            "wsfunction": function,
            "moodlewsrestformat": "json",
            **parameters,
        }
        request = Request(
            self.endpoint,
            data=urlencode(fields).encode("utf-8"),
            headers={"Accept": "application/json"},
            method="POST",
        )
        try:
            with self.opener.open(request, timeout=30) as response:
                body = response.read()
        except HTTPError as exc:
            raise UploadError(f"{function}: HTTP {exc.code}; redirects are not followed") from exc
        except (URLError, TimeoutError, OSError) as exc:
            raise UploadError(f"{function}: network request failed") from exc
        try:
            result = json.loads(body)
        except (UnicodeError, json.JSONDecodeError) as exc:
            raise UploadError(f"{function}: Moodle did not return valid JSON") from exc
        if isinstance(result, dict) and "exception" in result:
            code = str(result.get("errorcode", "unknown")).replace(self.token, "[redacted]")
            message = str(result.get("message", "Moodle API error")).replace(self.token, "[redacted]")
            raise UploadError(f"{function}: {code}: {message}")
        return result


def preflight(items, client):
    for item in items:
        if item.kind == "course":
            result = client.call("core_course_get_courses", {"options[ids][0]": item.id})
            field, text_field = "shortname", "summary"
        else:
            result = client.call("core_course_get_categories", {
                "criteria[0][key]": "id",
                "criteria[0][value]": item.id,
                "addsubcategories": 0,
            })
            field, text_field = "name", "description"
        if (
            not isinstance(result, list)
            or len(result) != 1
            or not isinstance(result[0], dict)
            or result[0].get("id") != item.id
        ):
            raise UploadError(f"{item.kind} {item.id}: Moodle did not return exactly the requested target")
        current = result[0]
        if current.get(field) != item.expected:
            raise UploadError(
                f"{item.kind} {item.id}: expected {field} {item.expected!r}, "
                f"received {current.get(field)!r}"
            )
        if not isinstance(current.get(text_field), str):
            raise UploadError(f"{item.kind} {item.id}: Moodle did not return its current {text_field}")
        old_hash = hashlib.sha256(current[text_field].encode("utf-8")).hexdigest()[:12]
        new_hash = hashlib.sha256(item.html.encode("utf-8")).hexdigest()[:12]
        print(
            f"Verified {item.kind} {item.id} ({item.expected}): {item.file} "
            f"(formatted current SHA-256 {old_hash}, new SHA-256 {new_hash})"
        )


def apply(items, client):
    completed = 0
    for item in items:
        try:
            if item.kind == "course":
                result = client.call("core_course_update_courses", {
                    "courses[0][id]": item.id,
                    "courses[0][summary]": item.html,
                    "courses[0][summaryformat]": 1,
                })
                if not isinstance(result, dict) or not isinstance(result.get("warnings"), list):
                    raise UploadError("Unexpected course-update response")
                if result["warnings"]:
                    warnings = str(result["warnings"])
                    token = getattr(client, "token", "")
                    if token:
                        warnings = warnings.replace(token, "[redacted]")
                    raise UploadError(f"Moodle returned course-update warnings: {warnings}")
            else:
                result = client.call("core_course_update_categories", {
                    "categories[0][id]": item.id,
                    "categories[0][description]": item.html,
                    "categories[0][descriptionformat]": 1,
                })
                if result is not None:
                    raise UploadError("Unexpected category-update response")
        except UploadError as exc:
            raise UploadError(
                f"{item.kind} {item.id}: update failed or could not be confirmed; "
                f"{completed} earlier update(s) completed. Verify Moodle before retrying. {exc}"
            ) from exc
        completed += 1
        print(f"Updated {item.kind} {item.id}: {item.file}")


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="Upload explicitly mapped course and category descriptions; dry-run by default."
    )
    parser.add_argument("--manifest", type=Path, required=True, help="JSON file mapping HTML files to Moodle IDs")
    parser.add_argument("--url", default=os.environ.get("MOODLE_URL"), help="Moodle HTTPS base URL")
    parser.add_argument(
        "--category-ids", type=int, nargs="+", action="append", metavar="ID",
        help="Select category IDs from the manifest (does not include children)",
    )
    parser.add_argument(
        "--course-ids", type=int, nargs="+", action="append", metavar="ID",
        help="Select course IDs from the manifest",
    )
    parser.add_argument("--apply", action="store_true", help="Actually update Moodle")
    parser.add_argument(
        "--backup-confirmed", action="store_true",
        help="Confirm an independent database and Moodle data backup exists",
    )
    args = parser.parse_args(argv)
    if args.apply and not args.backup_confirmed:
        parser.error("--apply requires --backup-confirmed")
    if not args.url:
        parser.error("provide --url or set MOODLE_URL")

    category_ids = [target_id for group in (args.category_ids or []) for target_id in group]
    course_ids = [target_id for group in (args.course_ids or []) for target_id in group]
    items = select_items(load_manifest(args.manifest), category_ids, course_ids)
    client = MoodleClient(args.url, os.environ.get("MOODLE_TOKEN"))
    preflight(items, client)
    if args.apply:
        apply(items, client)
    else:
        print(f"Dry run: {len(items)} target(s) verified; no content was changed.")


if __name__ == "__main__":
    try:
        main()
    except UploadError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        sys.exit(1)
