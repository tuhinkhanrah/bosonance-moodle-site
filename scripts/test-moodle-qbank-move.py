"""Opt-in live test of one disposable question-bank entry move and return."""

import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys

_spec = importlib.util.spec_from_file_location(
    "qbank_api_client", Path(__file__).with_name("test-moodle-qbank-api.py")
)
if _spec is None or _spec.loader is None:
    raise RuntimeError("Cannot load the question-bank API client")
qbank = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(qbank)


class TestFailure(Exception):
    pass


def require(condition, message):
    if not condition:
        raise TestFailure(message)


def categories(client, context):
    items, offset = [], 0
    while True:
        page = client.call("list_categories", contextid=context, offset=offset, limit=100)
        require(isinstance(page, dict) and isinstance(page.get("items"), list)
                and type(page.get("total")) is int and page["total"] >= 0,
                "Unexpected category response")
        items.extend(page["items"])
        if len(items) >= page["total"]:
            require(len(items) == page["total"], "Category list changed while paging")
            return {item["categoryid"]: item for item in items}
        require(bool(page["items"]), "Incomplete category list")
        offset += len(page["items"])


def question(client, context, questionid, categoryid, entryid=None):
    result = client.call("get_question", contextid=context, questionid=questionid, limit=100)
    item = result["question"]
    require(item["questionid"] == questionid and item["categoryid"] == categoryid,
            "Question is not in the expected category")
    require(entryid is None or item["entryid"] == entryid, "Question entry changed")
    require(not result["entryinuse"], "Question entry is in use")
    require(result["versions"] and result["versions"][0]["questionid"] == questionid
            and result["versions"][0]["version"] == item["version"],
            "Select the latest question version")
    return result


def preview(client, context, questionid, version, source, target, entry, count):
    result = client.call("preview_move", contextid=context, questionid=questionid,
                         expectedversion=version, targetid=target)
    require(result["contextid"] == context and result["sourceid"] == source
            and result["targetid"] == target and result["entryid"] == entry
            and result["questionid"] == questionid and result["version"] == version
            and len(result["versions"]) == count, "Unexpected move preview")
    require(qbank.is_hex64(result["previewtoken"]), "Invalid preview token")
    return result


def show_preview(label, result):
    print(label + ":", json.dumps({key: value for key, value in result.items()
                                   if key != "previewtoken"}, indent=2), flush=True)


def confirm(prompt):
    require(input(prompt).strip() == "MOVE", "Cancelled by user")


def expect_error(client, code, token):
    try:
        client.call("move_question", previewtoken=token, exclusive=True)
    except qbank.ApiError as error:
        require(error.errorcode == code,
                f"Expected {code}; got {error.errorcode or 'a transport/response error'}")
        print(f"[PASS] Rejected with {code}", flush=True)
        return
    raise TestFailure(f"Expected {code}, but the move succeeded")


def run(client, args):
    context, source, target, alternate, questionid = (
        args.contextid, args.sourceid, args.targetid, args.alternateid, args.questionid
    )
    require(len({source, target, alternate}) == 3, "Categories must be distinct")
    bank = categories(client, context)
    require(all(category in bank for category in (source, target, alternate)),
            "All categories must exist in the selected bank")
    require(bank[target]["parentid"] != 0 and bank[alternate]["parentid"] != 0,
            "Destinations must not be the top category")
    require(bank[alternate]["entrycount"] == 0, "Alternate category must be empty")
    original = question(client, context, questionid, source)
    item = original["question"]
    entry, version, count = item["entryid"], item["version"], original["versiontotal"]
    print("Selected question:", json.dumps(item, indent=2), flush=True)
    print("Categories:", json.dumps([bank[c] for c in (source, target, alternate)], indent=2), flush=True)

    forward = preview(client, context, questionid, version, source, target, entry, count)
    stale = preview(client, context, questionid, version, source, alternate, entry, count)
    require(forward["versions"] == stale["versions"], "Question versions changed between previews")
    show_preview("Forward preview", forward)
    show_preview("Stale-test preview", stale)
    confirm("Review both previews. Confirm disposable question and exclusive bank access; type MOVE: ")
    result = client.call("move_question", previewtoken=forward["previewtoken"], exclusive=True)
    require(result["entryid"] == entry and result["sourceid"] == source and result["targetid"] == target
            and result["versions"] == forward["versions"], "Unexpected move result")
    question(client, context, questionid, target, entry)
    print("[PASS] Move and destination verified; audit ID:", result["moveid"], flush=True)

    expect_error(client, "previewused", forward["previewtoken"])
    question(client, context, questionid, target, entry)
    expect_error(client, "previewstale", stale["previewtoken"])
    question(client, context, questionid, target, entry)
    print("[PASS] Rejected attempts left the question in the destination", flush=True)

    back = preview(client, context, questionid, version, target, source, entry, count)
    require(back["versions"] == forward["versions"], "Question versions changed before return move")
    show_preview("Return preview", back)
    confirm("Review the return preview and confirm exclusive bank access; type MOVE: ")
    result = client.call("move_question", previewtoken=back["previewtoken"], exclusive=True)
    require(result["entryid"] == entry and result["sourceid"] == target and result["targetid"] == source
            and result["versions"] == back["versions"], "Unexpected return move result")
    question(client, context, questionid, source, entry)
    print("[PASS] Return move verified; audit ID:", result["moveid"], flush=True)


def positive_int(value):
    try:
        number = int(value)
        if number > 0:
            return number
    except ValueError:
        pass
    raise argparse.ArgumentTypeError("must be a positive integer")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="HTTPS Moodle base URL")
    for name in ("contextid", "sourceid", "targetid", "alternateid", "questionid"):
        parser.add_argument("--" + name, type=positive_int, required=True)
    parser.add_argument("--timeout", type=positive_int, default=60, help="Request timeout in seconds")
    parser.add_argument("--allow-move", action="store_true", required=True,
                        help="Acknowledge this test moves a real question twice")
    args = parser.parse_args(argv)
    try:
        client = qbank.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
        run(client, args)
    except qbank.ApiError as error:
        print(f"[FAIL] API error: {error.errorcode or 'transport or invalid response'}", file=sys.stderr)
    except (TestFailure, KeyError, TypeError, ValueError, EOFError, KeyboardInterrupt) as error:
        print(f"[FAIL] {type(error).__name__}: {error}", file=sys.stderr)
    else:
        print("All move checks passed.")
        return 0
    print("STOP: check the question location and move audit before retrying; no automatic recovery was attempted.",
          file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
