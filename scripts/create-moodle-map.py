import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys
import unicodedata


SCRIPT = Path(__file__).with_name("upload-moodle-html.py")
SPEC = importlib.util.spec_from_file_location("moodle_uploader", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise ImportError(f"Cannot load Moodle client from {SCRIPT}")
uploader = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(uploader)


def category_key(name):
    name = unicodedata.normalize("NFKC", name).casefold().replace("+", "plus")
    return "".join(char for char in name if char.isalnum())


def course_key(name):
    return unicodedata.normalize("NFKC", name).casefold().replace("_", "-")


def unique_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise uploader.UploadError(f"Duplicate key in overrides: {key}")
        result[key] = value
    return result


def load_overrides(path):
    if path is None:
        return {"categories": {}, "courses": {}}
    try:
        data = json.loads(path.read_text(encoding="utf-8-sig"), object_pairs_hook=unique_keys)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise uploader.UploadError(f"Cannot read overrides {path}: {exc}") from exc
    if not isinstance(data, dict) or set(data) - {"categories", "courses"}:
        raise uploader.UploadError("Overrides must contain only categories and/or courses objects")
    result = {}
    for kind in ("categories", "courses"):
        entries = data.get(kind, {})
        if not isinstance(entries, dict):
            raise uploader.UploadError(f"Overrides {kind} must be an object")
        for key, value in entries.items():
            if not isinstance(key, str) or not key or type(value) is not int or value <= 0:
                raise uploader.UploadError(f"Overrides {kind} must map local folder paths to positive IDs")
        result[kind] = entries
    return result


def records(value, kind):
    if not isinstance(value, list):
        raise uploader.UploadError(f"Moodle returned an invalid {kind} list")
    result = {}
    for record in value:
        name_field = "name" if kind == "categories" else "shortname"
        parent_field = "parent" if kind == "categories" else "categoryid"
        if (
            not isinstance(record, dict)
            or type(record.get("id")) is not int
            or record["id"] <= 0
            or not isinstance(record.get(name_field), str)
            or not record[name_field].strip()
            or type(record.get(parent_field)) is not int
            or record[parent_field] < 0
        ):
            raise uploader.UploadError(f"Moodle returned an invalid {kind} record")
        if record["id"] in result:
            raise uploader.UploadError(f"Moodle returned duplicate {kind} ID {record['id']}")
        result[record["id"]] = record
    return result


def make_map(root, client, overrides):
    root = root.resolve()
    base = (root / uploader.CATEGORIES).resolve()
    if not base.is_dir():
        raise uploader.UploadError(f"Category directory is missing: {base}")
    category_files = {}
    course_files = {}
    for source in sorted(base.rglob("description.html")):
        try:
            resolved = source.resolve(strict=True)
            relative = resolved.relative_to(base)
            resolved.relative_to(root)
        except (OSError, ValueError) as exc:
            raise uploader.UploadError(f"Description is missing or outside the repository: {source}") from exc
        if not resolved.is_file():
            raise uploader.UploadError(f"Description is not a file: {source}")
        try:
            if not resolved.read_text(encoding="utf-8-sig").strip():
                raise uploader.UploadError(f"Description is empty: {source}")
        except (OSError, UnicodeError) as exc:
            raise uploader.UploadError(f"Cannot read description {source}: {exc}") from exc
        parts = relative.parts
        is_course = len(parts) >= 3 and parts[-3] == "courses"
        if "courses" in parts and not is_course:
            raise uploader.UploadError(f"Unsupported description path: {source}")
        folder = relative.parent.as_posix()
        destination = course_files if is_course else category_files
        if folder in destination:
            raise uploader.UploadError(f"Duplicate local description path: {folder}")
        destination[folder] = resolved.relative_to(root).as_posix()
    if not category_files or not course_files:
        raise uploader.UploadError("No category or course descriptions found")
    for kind, known in (("categories", category_files), ("courses", course_files)):
        unknown = set(overrides[kind]) - set(known)
        if unknown:
            raise uploader.UploadError(f"Unknown {kind} override paths: {', '.join(sorted(unknown))}")

    categories = records(client.call("core_course_get_categories", {}), "categories")
    courses = records(client.call("core_course_get_courses", {}), "courses")
    mapped_categories = {}
    used_categories = {}
    used_courses = {}
    items = []
    errors = []

    for folder in sorted(category_files, key=lambda value: (value.count("/"), value)):
        parent = Path(folder).parent.as_posix()
        parent_id = None if parent == "." else mapped_categories.get(parent)
        if parent != "." and parent_id is None:
            errors.append(f"category {folder}: parent {parent} is unresolved")
            continue
        available = [item for item in categories.values()
                     if parent_id is None or item["parent"] == parent_id]
        candidates = [item for item in available
                      if category_key(item["name"]) == category_key(Path(folder).name)]
        override = overrides["categories"].get(folder)
        if override is not None:
            candidate = categories.get(override)
            if candidate is None or (parent_id is not None and candidate["parent"] != parent_id):
                errors.append(f"category {folder}: override ID {override} is missing or has the wrong parent")
                continue
        elif len(candidates) == 1:
            candidate = candidates[0]
        else:
            options = ", ".join(f"{item['id']}: {item['name']}" for item in (candidates or available)) or "none"
            errors.append(f"category {folder}: {len(candidates)} matches; available IDs: {options}; add an override")
            continue
        if candidate["id"] in used_categories:
            errors.append(f"category {folder}: ID {candidate['id']} already used by {used_categories[candidate['id']]}")
            continue
        mapped_categories[folder] = candidate["id"]
        used_categories[candidate["id"]] = folder
        items.append({"kind": "category", "id": candidate["id"], "expected": candidate["name"],
                      "file": category_files[folder]})

    for folder in sorted(course_files):
        category = Path(folder).parent.parent.as_posix()
        category_id = mapped_categories.get(category)
        if category_id is None:
            errors.append(f"course {folder}: category {category} is unresolved")
            continue
        available = [item for item in courses.values() if item["categoryid"] == category_id]
        candidates = [item for item in available
                      if course_key(item["shortname"]) == course_key(Path(folder).name)]
        override = overrides["courses"].get(folder)
        if override is not None:
            candidate = courses.get(override)
            if candidate is None or candidate["categoryid"] != category_id:
                errors.append(f"course {folder}: override ID {override} is missing or in the wrong category")
                continue
        elif len(candidates) == 1:
            candidate = candidates[0]
        else:
            options = ", ".join(f"{item['id']}: {item['shortname']}" for item in (candidates or available)) or "none"
            errors.append(f"course {folder}: {len(candidates)} matches; available IDs: {options}; add an override")
            continue
        if candidate["id"] in used_courses:
            errors.append(f"course {folder}: ID {candidate['id']} already used by {used_courses[candidate['id']]}")
            continue
        used_courses[candidate["id"]] = folder
        items.append({"kind": "course", "id": candidate["id"], "expected": candidate["shortname"],
                      "file": course_files[folder]})

    if errors:
        raise uploader.UploadError("No map written; resolve these mappings:\n" + "\n".join(errors))
    return {"items": items}


def main(argv=None):
    parser = argparse.ArgumentParser(description="Create a candidate Moodle HTML map using read-only API calls.")
    parser.add_argument("--url", default=os.environ.get("MOODLE_URL"), help="Moodle HTTPS base URL")
    parser.add_argument("--output", type=Path, required=True, help="New mapping JSON file")
    parser.add_argument("--overrides", type=Path, help="JSON file of explicitly selected category and course IDs")
    args = parser.parse_args(argv)
    if not args.url:
        parser.error("provide --url or set MOODLE_URL")
    if args.output.exists():
        raise uploader.UploadError(f"Refusing to overwrite {args.output}")
    overrides = load_overrides(args.overrides)
    client = uploader.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN"))
    mapping = make_map(uploader.ROOT, client, overrides)
    try:
        fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as output:
            json.dump(mapping, output, ensure_ascii=False, indent=2)
            output.write("\n")
    except OSError as exc:
        raise uploader.UploadError(f"Could not write mapping {args.output}: {exc}") from exc
    print(f"Created {args.output} with {len(mapping['items'])} candidate(s). Review every mapping before uploading.")


if __name__ == "__main__":
    try:
        main()
    except uploader.UploadError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        sys.exit(1)
