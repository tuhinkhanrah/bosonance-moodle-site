"""Manage the current Bosonance question-bank API without automatic writes or retries."""

import argparse
from contextlib import contextmanager
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys
import time
import uuid
from xml.etree import ElementTree


_spec = importlib.util.spec_from_file_location(
    "moodle_qbank_transport", Path(__file__).with_name("moodle_qbank_transport.py")
)
if _spec is None or _spec.loader is None:
    raise RuntimeError("Cannot load the question-bank API client")
if _spec.name in sys.modules:
    qbank = sys.modules[_spec.name]
else:
    qbank = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(qbank)
    sys.modules[_spec.name] = qbank

MAX_XML_BYTES = 10 * 1024 * 1024
PREVIEW_APPLY = {
    "preview_xml": "import_xml",
    "preview_move": "move_question",
    "preview_status": "change_status",
    "preview_tags": "change_tags",
    "preview_category": "change_category",
    "preview_delete_question": "delete_question",
    "preview_delete_category": "delete_category",
}
AUDIT_IDS = {
    "import_xml": "importid",
    "move_question": "moveid",
    "change_status": "statusid",
    "change_tags": "tagsid",
    "change_category": "categoryauditid",
    "delete_question": "deletionid",
    "delete_category": "deletionid",
}


class CliError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CliError(message)


def hex64(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


@contextmanager
def private_output(path):
    if path is None:
        yield None
        return
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    complete = False
    try:
        with os.fdopen(fd, "wb") as stream:
            yield stream
            stream.flush()
            os.fsync(stream.fileno())
        complete = True
    finally:
        if not complete:
            path.unlink()


def emit(result, stream):
    content = json.dumps(result, ensure_ascii=False, indent=2) + "\n"
    if stream is None:
        print(content, end="")
    else:
        stream.write(content.encode("utf-8"))


def validate_summary(item):
    require(isinstance(item, dict) and qbank.SUMMARY_KEYS.issubset(item)
            and all(qbank.is_int(item[key], 1) for key in ("questionid", "entryid", "categoryid", "version"))
            and item["status"] in qbank.STATUSES[:-1]
            and isinstance(item["name"], str) and isinstance(item["qtype"], str)
            and qbank.is_int(item["timemodified"]), "Invalid question metadata")


def validate_page(result, idkey):
    require(isinstance(result, dict) and qbank.is_int(result.get("total"))
            and isinstance(result.get("items"), list), "Invalid list response")
    require(len(result["items"]) <= result["total"], "List exceeds reported total")
    for item in result["items"]:
        require(isinstance(item, dict) and qbank.is_int(item.get(idkey), 1),
                "Invalid item ID in list response")
        if idkey == "questionid":
            validate_summary(item)
        else:
            require(qbank.is_int(item.get("parentid")) and qbank.is_int(item.get("entrycount"))
                    and isinstance(item.get("name"), str), "Invalid category metadata")


def list_items(client, function, parameters, all_pages):
    idkey = "categoryid" if function == "list_categories" else "questionid"
    result = client.call(function, **parameters)
    validate_page(result, idkey)
    if not all_pages:
        return result
    items = list(result["items"])
    total = result["total"]
    while len(items) < total:
        require(bool(result["items"]), "Incomplete list; bank may have changed")
        result = client.call(function, **dict(parameters, offset=len(items)))
        validate_page(result, idkey)
        require(result["total"] == total, "List total changed while paging")
        items.extend(result["items"])
    require(len(items) == total and len({item[idkey] for item in items}) == total,
            "Duplicate or inconsistent items while paging")
    return {"total": total, "items": items}


def validate_category_subtree(result, parameters):
    deleteunusedquestions = parameters.get("deleteunusedquestions", False)
    recursive = parameters.get("recursive", False) or deleteunusedquestions
    require(type(recursive) is bool, "Invalid recursive request")
    require(type(deleteunusedquestions) is bool, "Invalid question-deletion option")
    fields = ("recursive", "categorycount", "categories", "deleteunusedquestions", "entrycount", "questionentries")
    if not recursive and not any(key in result for key in fields):
        return
    require(type(result.get("recursive")) is bool and result["recursive"] == recursive,
            "Server did not confirm the requested recursive scope; deploy the 1.2.0 plugin")
    if deleteunusedquestions:
        require(result.get("deleteunusedquestions") is True,
                "Server did not confirm unused-question deletion; deploy the updated plugin")
    else:
        require("deleteunusedquestions" not in result,
                "Server returned an unexpected question-deletion scope")
    categories = result.get("categories")
    require(isinstance(categories, list) and qbank.is_int(result.get("categorycount"), 1)
            and result["categorycount"] == len(categories) <= 1000,
            "Invalid category subtree count")
    nodes = {}
    for item in categories:
        require(isinstance(item, dict) and qbank.is_int(item.get("categoryid"), 1)
                and qbank.is_int(item.get("parentid"), 1) and isinstance(item.get("name"), str)
                and item["categoryid"] not in nodes, "Invalid category subtree item")
        nodes[item["categoryid"]] = item
    root = result["categoryid"]
    require(root in nodes and nodes[root]["parentid"] == result["parentid"]
            and result["parentid"] not in nodes and (recursive or len(nodes) == 1),
            "Category subtree root mismatch")
    connected = {root}
    for node in nodes:
        chain = set()
        while node not in connected:
            require(node in nodes and node not in chain, "Disconnected or cyclic category subtree")
            chain.add(node)
            node = nodes[node]["parentid"]
        connected.update(chain)
    if deleteunusedquestions:
        entries = result.get("questionentries")
        require(result.get("deleteunusedquestions") is True and isinstance(entries, list)
                and qbank.is_int(result.get("entrycount")) and result["entrycount"] == len(entries) <= 100
                and qbank.is_int(result.get("questioncount")) and result["questioncount"] <= 1000,
                "Invalid unused-question deletion count")
        entryids, questionids = set(), set()
        versiontotal = 0
        for entry in entries:
            require(isinstance(entry, dict) and qbank.is_int(entry.get("entryid"), 1)
                    and entry["entryid"] not in entryids
                    and qbank.is_int(entry.get("categoryid"), 1) and entry["categoryid"] in nodes
                    and qbank.is_int(entry.get("questionid"), 1)
                    and qbank.is_int(entry.get("version"), 1) and isinstance(entry.get("name"), str)
                    and isinstance(entry.get("qtype"), str)
                    and qbank.is_int(entry.get("questioncount"), 1) and entry["questioncount"] <= 100,
                    "Invalid question-entry deletion scope")
            versions = entry.get("versions")
            require(isinstance(versions, list) and len(versions) == entry["questioncount"]
                    and all(isinstance(item, dict) and qbank.is_int(item.get("questionid"), 1)
                            and qbank.is_int(item.get("version"), 1) and item.get("status") in qbank.STATUSES[:-1]
                            and qbank.is_int(item.get("timemodified")) for item in versions)
                    and versions[-1]["questionid"] == entry["questionid"]
                    and versions[-1]["version"] == entry["version"]
                    and [item["version"] for item in versions] == sorted({item["version"] for item in versions})
                    and not questionids.intersection(item["questionid"] for item in versions),
                    "Invalid question versions in category deletion scope")
            entryids.add(entry["entryid"])
            questionids.update(item["questionid"] for item in versions)
            versiontotal += len(versions)
        require(versiontotal == result["questioncount"], "Question deletion version total mismatch")
    else:
        require(not any(key in result for key in ("entrycount", "questionentries")),
                "Server returned an unexpected question-entry scope")


def validate_preview(function, result, parameters):
    require(isinstance(result, dict) and hex64(result.get("previewtoken"))
            and qbank.is_int(result.get("expires"), 1), "Invalid preview token or expiry")
    require(result["expires"] > time.time(), "Preview has already expired")
    require(result.get("contextid") == parameters["contextid"], "Preview bank mismatch")
    if function in ("preview_delete_question", "preview_delete_category"):
        kind = "question" if function == "preview_delete_question" else "category"
        require(result.get("kind") == kind and isinstance(result.get("name"), str)
                and qbank.is_int(result.get("categoryid"), 1)
                and qbank.is_int(result.get("parentid")), "Invalid deletion scope")
        versions = result.get("versions")
        require(isinstance(versions, list) and all(
            isinstance(item, dict) and qbank.is_int(item.get("questionid"), 1)
            and qbank.is_int(item.get("version"), 1) and item.get("status") in qbank.STATUSES[:-1]
            and qbank.is_int(item.get("timemodified")) for item in versions
        ), "Invalid deletion versions")
        if kind == "question":
            require(result.get("questionid") == parameters["questionid"]
                    and result.get("version") == parameters["expectedversion"]
                    and qbank.is_int(result.get("entryid"), 1)
                    and qbank.is_int(result.get("questioncount"), 1)
                    and result["questioncount"] == len(versions) <= 100
                    and len({item["questionid"] for item in versions}) == len(versions)
                    and len({item["version"] for item in versions}) == len(versions)
                    and versions[-1]["questionid"] == result["questionid"]
                    and versions[-1]["version"] == result["version"],
                    "Question deletion preview mismatch")
        else:
            require(result["categoryid"] == parameters["categoryid"] and not versions
                    and qbank.is_int(result["parentid"], 1)
                    and all(type(result.get(key)) is int and result[key] == 0
                            for key in ("entryid", "questionid", "version"))
                    and ((parameters.get("deleteunusedquestions") and qbank.is_int(result.get("questioncount")))
                         or (not parameters.get("deleteunusedquestions") and result.get("questioncount") == 0)),
                    "Category deletion preview mismatch")
            validate_category_subtree(result, parameters)
    elif function == "preview_xml":
        require(result.get("categoryid") == parameters["categoryid"]
                and hex64(result.get("sha256")) and hex64(result.get("warningshash"))
                and qbank.is_int(result.get("questioncount"), 1)
                and result.get("canapply") in (True, 1)
                and isinstance(result.get("categories"), list)
                and qbank.valid_import_tags(result.get("tags"))
                and isinstance(result.get("warnings"), list)
                and type(result.get("requiresduplicateoverride")) in (bool, int)
                and result["requiresduplicateoverride"] in (0, 1),
                "Invalid XML preview response")
    elif function == "preview_category":
        require(result.get("categoryid") == parameters["categoryid"]
                and result.get("action") == parameters["action"]
                and result.get("newname") == parameters["name"]
                and qbank.is_int(result.get("parentid"), 1)
                and isinstance(result.get("oldname"), str), "Category preview mismatch")
    else:
        require(result.get("questionid") == parameters["questionid"]
                and result.get("version") == parameters["expectedversion"]
                and qbank.is_int(result.get("entryid"), 1), "Question preview mismatch")
        if function == "preview_move":
            require(result.get("targetid") == parameters["targetid"]
                    and qbank.is_int(result.get("sourceid"), 1)
                    and isinstance(result.get("versions"), list) and bool(result["versions"]),
                    "Move preview mismatch")
        elif function == "preview_status":
            require(result.get("oldstatus") == parameters["expectedstatus"]
                    and result.get("newstatus") == parameters["targetstatus"],
                    "Status preview mismatch")
        else:
            for key in ("oldtags", "newtags"):
                tags = result.get(key)
                require(isinstance(tags, list) and all(
                    isinstance(tag, dict) and qbank.is_int(tag.get("id"), 1)
                    and isinstance(tag.get("name"), str) for tag in tags
                ), "Invalid tag preview")
            require({tag["id"] for tag in result["newtags"]} == set(parameters.get("tagids", [])),
                    "Tag preview mismatch")


def load_preview(path, function, base):
    document = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(document, dict) and document.get("format") == 1,
            "Not a CLI preview file")
    require(document.get("operation") == function, "Preview belongs to another operation")
    require(document.get("url") == base, "Preview belongs to another Moodle URL")
    preview = document.get("preview")
    parameters = document.get("parameters")
    require(isinstance(parameters, dict) and qbank.is_int(parameters.get("contextid"), 1),
            "Invalid saved preview parameters")
    preview_function = next(key for key, value in PREVIEW_APPLY.items() if value == function)
    validate_preview(preview_function, preview, parameters)
    return preview


def validate_applied(function, result, preview):
    require(isinstance(result, dict) and qbank.is_int(result.get(AUDIT_IDS[function]), 1),
            "Invalid apply response; inspect Moodle state and audit")
    fields = ("contextid", "categoryid", "sha256", "questioncount") if function == "import_xml" else (
        key for key in preview if key not in ("previewtoken", "expires")
        and not (function in ("delete_question", "delete_category") and key == "name")
    )
    for key in fields:
        expected = preview[key]
        if function == "delete_category" and key == "categories":
            expected = [{field: item[field] for field in ("categoryid", "parentid")} for item in preview[key]]
        if function == "delete_category" and key == "questionentries":
            expected = [{field: value for field, value in item.items() if field != "name"}
                        for item in preview[key]]
        require(key in result and result[key] == expected,
                "Apply result differs from preview; inspect Moodle state and audit")
    if function == "import_xml":
        require(isinstance(result.get("questions"), list)
                and len(result["questions"]) == preview["questioncount"],
                "Imported question count mismatch; inspect Moodle")
    elif function == "change_category":
        require(qbank.is_int(result.get("resultcategoryid"), 1),
                "Missing resulting category ID; inspect Moodle")
    elif function in ("delete_question", "delete_category"):
        require(type(result.get("deleted")) in (bool, int) and result["deleted"] in (True, 1),
                "Permanent deletion was not confirmed; inspect Moodle state and audit")


def parameters_for(args):
    if args.function in AUDIT_IDS:
        return {}
    parameters = {"contextid": args.contextid}
    for key in ("categoryid", "questionid", "expectedversion", "targetid", "expectedstatus",
                "targetstatus", "action", "name", "offset", "limit", "status", "qtype", "search", "tag"):
        if hasattr(args, key):
            parameters[key] = getattr(args, key)
    if args.function == "get_question":
        parameters["includecontent"] = args.include_content
    if args.function == "export_xml":
        parameters["questionids"] = args.questionids
    if args.function == "preview_tags":
        if args.clear:
            parameters["clear"] = True
        else:
            parameters["tagids"] = args.tagids
    if args.function == "preview_delete_category" and args.recursive:
        parameters["recursive"] = True
    if args.function == "preview_delete_category" and args.delete_unused_questions:
        parameters["deleteunusedquestions"] = True
    return parameters


def run(client, args, stream):
    function = args.function
    parameters = parameters_for(args)
    if function in AUDIT_IDS:
        preview = load_preview(args.preview_file, function, client.base)
        parameters = {"previewtoken": preview["previewtoken"]}
        if function == "import_xml":
            require(not preview["requiresduplicateoverride"] or args.allow_duplicate,
                    "Exact repeat requires --allow-duplicate after reviewing its warnings")
            parameters.update(warningshash=preview["warningshash"], allowduplicate=args.allow_duplicate)
        else:
            parameters["exclusive"] = True
        try:
            result = client.call(function, **parameters)
        except qbank.ApiError as error:
            message = error.errorcode or str(error)
            raise CliError(message.replace(preview["previewtoken"], "[redacted]")) from None
        validate_applied(function, result, preview)
        emit(result, stream)
    elif function in PREVIEW_APPLY:
        if function == "preview_xml":
            with args.file.open("rb") as source:
                content = source.read(MAX_XML_BYTES + 1)
            require(0 < len(content) <= MAX_XML_BYTES, "XML file must be nonempty and at most 10 MiB")
            filename = f"qbank-{uuid.uuid4().hex}.xml"
            parameters.update(draftitemid=client.upload_draft(filename, content), filename=filename)
        result = client.call(function, **parameters)
        validate_preview(function, result, parameters)
        if function == "preview_xml":
            require(result["sha256"] == hashlib.sha256(content).hexdigest(),
                    "Uploaded XML hash differs from preview")
        emit({"format": 1, "url": client.base, "operation": PREVIEW_APPLY[function],
              "parameters": parameters, "preview": result}, stream)
        emit({key: value for key, value in result.items() if key != "previewtoken"}, None)
    elif function in ("list_categories", "list_questions"):
        emit(list_items(client, function, parameters, args.all), stream)
    elif function == "export_xml":
        result = client.call(function, **parameters)
        require(isinstance(result, dict) and isinstance(result.get("xml"), str),
                "Invalid XML export response")
        content = result["xml"].encode("utf-8")
        require(0 < len(content) <= MAX_XML_BYTES
                and hashlib.sha256(content).hexdigest() == result.get("sha256")
                and result.get("questioncount") == len(args.questionids), "XML export integrity mismatch")
        document = ElementTree.fromstring(content)
        require(document.tag == "quiz" and len([
            question for question in document.findall("question") if question.get("type") != "category"
        ]) == len(args.questionids), "XML export document/count mismatch")
        stream.write(content)
    else:
        result = client.call(function, **parameters)
        require(isinstance(result, dict)
                and qbank.is_int(result.get("versiontotal"), 1)
                and isinstance(result.get("versions"), list), "Invalid question response")
        validate_summary(result.get("question"))
        require(result["question"]["questionid"] == args.questionid
                and len(result["versions"]) <= min(args.limit, result["versiontotal"])
                and all(key in result and type(result[key]) in (bool, int) and result[key] in (0, 1)
                        for key in ("inuse", "entryinuse")), "Invalid question identity/history/usage flags")
        for version in result["versions"]:
            require(isinstance(version, dict) and qbank.is_int(version.get("questionid"), 1)
                    and qbank.is_int(version.get("version"), 1)
                    and version.get("status") in qbank.STATUSES[:-1], "Invalid version history")
        if args.include_content:
            content = result.get("content")
            require(isinstance(content, dict) and qbank.CONTENT_KEYS.issubset(content)
                    and all(isinstance(content[key], str) for key in ("questiontext", "generalfeedback"))
                    and all(qbank.is_int(content[key]) for key in ("questiontextformat", "generalfeedbackformat")),
                    "Requested question content is missing or invalid")
        else:
            require("content" not in result, "Unexpected question content; refusing to print it")
        emit(result, stream)


def positive(value):
    number = int(value)
    if not 1 <= number <= 2**63 - 1:
        raise argparse.ArgumentTypeError("must be a positive signed 64-bit integer")
    return number


def offset(value):
    number = int(value)
    if number < 0:
        raise argparse.ArgumentTypeError("must be nonnegative")
    return number


def page_limit(value):
    number = positive(value)
    if number > 100:
        raise argparse.ArgumentTypeError("must be between 1 and 100")
    return number


def parser_for_cli():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default=os.environ.get("MOODLE_URL"),
                        help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    parser.add_argument("--timeout", type=positive, default=60, help="Request timeout in seconds (default 60)")
    commands = parser.add_subparsers(dest="command", required=True)
    helptext = {
        "list_categories": "List bank categories",
        "list_questions": "List latest versions in one category",
        "get_question": "Get one question and paged version history",
        "export_xml": "Export 1-100 selected question versions to a new XML file",
        "preview_xml": "Upload local XML and save an import preview; no bank changes",
        "import_xml": "Apply a saved import preview after warning acknowledgement",
        "preview_move": "Preview moving an entry and all versions within one bank",
        "move_question": "Apply a saved question move preview",
        "preview_status": "Preview ready/draft/hidden status for the latest version",
        "change_status": "Apply a saved status preview",
        "preview_tags": "Preview replacing or clearing standard tags",
        "change_tags": "Apply a saved tag preview",
        "preview_category": "Preview creating a category or renaming an empty leaf",
        "change_category": "Apply a saved category preview",
        "preview_delete_question": "Preview permanent deletion of an unused entry and every version",
        "delete_question": "Irreversibly delete an unused whole entry after preview",
        "preview_delete_category": "Preview deletion of an empty category or explicitly selected unused-question subtree",
        "delete_category": "Irreversibly delete the exact category scope in a saved preview",
    }
    for function, description in helptext.items():
        command = commands.add_parser(function.replace("_", "-"), help=description, description=description)
        command.set_defaults(function=function)
        command.add_argument("--output", type=Path, required=function in PREVIEW_APPLY or function == "export_xml",
                             help="New output file (private, never overwritten)")
        if function in AUDIT_IDS:
            command.add_argument("--preview-file", type=Path, required=True, help="Saved CLI preview JSON")
            command.add_argument("--confirm", action="store_true", help="Confirm the displayed preview was reviewed")
            command.add_argument("--backup-confirmed", action="store_true", help="Confirm a restore-tested backup exists")
            command.add_argument("--exclusive", action="store_true", help="Confirm no other relevant writers from preview through apply")
            if function == "import_xml":
                command.add_argument("--allow-duplicate", action="store_true", help="Explicitly allow an exact repeated import")
            if function in ("delete_question", "delete_category"):
                command.add_argument("--confirm-delete", action="store_true",
                                     help="Acknowledge permanent deletion with no undo")
            continue
        command.add_argument("--contextid", type=positive, required=True, help="Exact question-bank/quiz module context")
        if function in ("list_questions", "preview_xml", "preview_category", "preview_delete_category"):
            command.add_argument("--categoryid", type=positive, required=True)
        if function in ("get_question", "preview_move", "preview_status", "preview_tags", "preview_delete_question"):
            command.add_argument("--questionid", type=positive, required=True)
        if function in ("preview_move", "preview_status", "preview_tags", "preview_delete_question"):
            command.add_argument("--expectedversion", type=positive, required=True, help="Latest version number from get-question")
        if function in ("list_categories", "list_questions", "get_question"):
            command.add_argument("--offset", type=offset, default=0)
            command.add_argument("--limit", type=page_limit, default=50)
        if function in ("list_categories", "list_questions"):
            command.add_argument("--all", action="store_true", help="Fetch all pages; detects totals/ID inconsistencies")
        if function == "list_questions":
            command.add_argument("--status", choices=qbank.STATUSES, default="ready")
            for key in ("qtype", "search", "tag"):
                command.add_argument("--" + key, default="")
        elif function == "get_question":
            command.add_argument("--include-content", action="store_true", help="Save text/feedback; requires --output")
        elif function == "export_xml":
            command.add_argument("--questionids", type=positive, nargs="+", required=True)
        elif function == "preview_xml":
            command.add_argument("--file", type=Path, required=True, help="Local Moodle XML file")
        elif function == "preview_move":
            command.add_argument("--targetid", type=positive, required=True)
        elif function == "preview_status":
            command.add_argument("--expectedstatus", choices=qbank.STATUSES[:-1], required=True)
            command.add_argument("--targetstatus", choices=qbank.STATUSES[:-1], required=True)
        elif function == "preview_tags":
            tags = command.add_mutually_exclusive_group(required=True)
            tags.add_argument("--tagids", type=positive, nargs="+", help="Existing standard question tag IDs")
            tags.add_argument("--clear", action="store_true", help="Explicitly remove all assigned tags")
        elif function == "preview_category":
            command.add_argument("--action", choices=("create", "rename"), required=True)
            command.add_argument("--name", required=True)
        elif function == "preview_delete_category":
            command.add_argument("--recursive", action="store_true",
                                 help="Preview the whole subtree; every category must have zero question entries")
            command.add_argument("--delete-unused-questions", action="store_true",
                                 help="Delete every unused question entry/version and all descendant categories")
    return parser


def main(argv=None):
    parser = parser_for_cli()
    args = parser.parse_args(argv)
    if not args.url:
        parser.error("provide --url or set MOODLE_URL")
    if args.function in AUDIT_IDS and not (args.confirm and args.backup_confirmed and args.exclusive):
        parser.error("apply requires --confirm --backup-confirmed --exclusive")
    if args.function in ("delete_question", "delete_category") and not args.confirm_delete:
        parser.error("permanent deletion additionally requires --confirm-delete; there is no undo")
    if args.function == "get_question" and args.include_content and args.output is None:
        parser.error("--include-content requires --output; question text is never printed")
    if getattr(args, "all", False) and args.offset:
        parser.error("--all cannot be combined with a nonzero --offset")
    if getattr(args, "recursive", False) and getattr(args, "delete_unused_questions", False):
        parser.error("--recursive and --delete-unused-questions are separate deletion modes")
    if hasattr(args, "questionids") and (len(args.questionids) > 100 or len(set(args.questionids)) != len(args.questionids)):
        parser.error("--questionids requires 1-100 distinct IDs")
    if getattr(args, "tagids", None) and (len(args.tagids) > 50 or len(set(args.tagids)) != len(args.tagids)):
        parser.error("--tagids requires 1-50 distinct IDs; use --clear to remove all tags")
    try:
        client = qbank.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
        with private_output(args.output) as stream:
            run(client, args, stream)
        if args.output is not None:
            print(f"Saved {args.output}", file=sys.stderr)
    except (CliError, qbank.ApiError, OSError, UnicodeError, ValueError, KeyError, ElementTree.ParseError) as error:
        message = (error.errorcode or str(error)) if isinstance(error, qbank.ApiError) else str(error)
        token = os.environ.get("MOODLE_TOKEN", "")
        print(f"[FAIL] {message.replace(token, '[redacted]') if token else message}", file=sys.stderr)
        if args.function in AUDIT_IDS:
            print("If an apply request was sent, it may have committed. Inspect state/audit before retrying; no automatic recovery attempted.",
                  file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("Cancelled; if an apply was sent, inspect state/audit before retrying.", file=sys.stderr)
        return 130
    return 0


if __name__ == "__main__":
    sys.exit(main())
