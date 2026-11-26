"""Opt-in live test of one disposable question-bank entry move and return."""

import argparse
from concurrent.futures import ThreadPoolExecutor
import importlib.util
import json
import os
from pathlib import Path
import sys
from threading import Barrier

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


def race(client, function, previews, rejection):
    require(len(previews) == 2, "A controlled race requires exactly two previews")
    clients = [qbank.MoodleClient(client.base, client.token, client.timeout) for _ in previews]
    barrier = Barrier(len(clients))

    def request(index):
        barrier.wait(timeout=30)
        try:
            return index, clients[index].call(function, previewtoken=previews[index]["previewtoken"],
                                              exclusive=True), None
        except qbank.ApiError as error:
            return index, None, error

    with ThreadPoolExecutor(max_workers=len(clients)) as executor:
        outcomes = list(executor.map(request, range(len(clients))))
    successes = [(index, result) for index, result, error in outcomes if error is None]
    errors = [error for _, _, error in outcomes if error is not None]
    require(len(successes) == 1 and len(errors) == 1,
            "Concurrent requests did not produce exactly one success and one rejection; inspect audit/state")
    require(errors[0].errorcode == rejection,
            f"Expected {rejection}; got {errors[0].errorcode or str(errors[0])}")
    index, result = successes[0]
    print(f"[PASS] Independent concurrent requests: one success, one {rejection}", flush=True)
    return previews[index], result


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
    require(all(bank[category]["parentid"] != 0 for category in (source, target, alternate)),
            "Source and destinations must not be the top category; the test must be able to return to source")
    require(bank[alternate]["entrycount"] == 0, "Alternate category must be empty")
    original = question(client, context, questionid, source)
    item = original["question"]
    entry, version, count = item["entryid"], item["version"], original["versiontotal"]
    print("Selected question:", json.dumps(item, indent=2), flush=True)
    print("Categories:", json.dumps([bank[c] for c in (source, target, alternate)], indent=2), flush=True)

    def check_location(location):
        current = question(client, context, questionid, location, entry)
        require(current["question"] == dict(item, categoryid=location)
                and current["versiontotal"] == count and current["versions"] == original["versions"],
                "Unexpected question metadata/version changes")
        expected = dict(bank)
        if location != source:
            expected[source] = dict(bank[source], entrycount=bank[source]["entrycount"] - 1)
            expected[location] = dict(bank[location], entrycount=bank[location]["entrycount"] + 1)
        require(categories(client, context) == expected, "Unexpected category or question-count changes")

    def verify(proposed, result):
        require(all(result[key] == proposed[key] for key in
                    ("contextid", "questionid", "entryid", "version", "sourceid", "targetid", "versions"))
                and qbank.is_int(result["moveid"], 1), "Unexpected move result")
        check_location(proposed["targetid"])
        print("[PASS] Move and destination verified; audit ID:", result["moveid"], flush=True)

    def apply(proposed, label):
        show_preview(label, proposed)
        confirm("Confirm isolated staging, disposable question and no other bank writes; type MOVE: ")
        result = client.call("move_question", previewtoken=proposed["previewtoken"], exclusive=True)
        verify(proposed, result)

    forward = preview(client, context, questionid, version, source, target, entry, count)
    stale = preview(client, context, questionid, version, source, alternate, entry, count)
    require(forward["versions"] == stale["versions"], "Question versions changed between previews")
    show_preview("Stale-test preview", stale)
    check_location(source)
    if args.concurrent:
        show_preview("Concurrent single-token move preview", forward)
        confirm("Confirm isolated staging, disposable question and no other bank writes; type MOVE: ")
        winner, result = race(client, "move_question", [forward, forward], "previewused")
        verify(winner, result)
    else:
        apply(forward, "Forward preview")

    expect_error(client, "previewused", forward["previewtoken"])
    check_location(target)
    expect_error(client, "previewstale", stale["previewtoken"])
    check_location(target)
    print("[PASS] Rejected attempts left the question in the destination", flush=True)

    location = target
    if args.concurrent:
        left = preview(client, context, questionid, version, target, source, entry, count)
        right = preview(client, context, questionid, version, target, alternate, entry, count)
        require(left["versions"] == right["versions"] == forward["versions"],
                "Question versions changed between competing previews")
        check_location(target)
        show_preview("First competing move", left)
        show_preview("Second competing move", right)
        confirm("Confirm isolated staging and no bank writes except this controlled pair; type MOVE: ")
        winner, result = race(client, "move_question", [left, right], "previewstale")
        verify(winner, result)
        location = winner["targetid"]
    if location != source:
        back = preview(client, context, questionid, version, location, source, entry, count)
        require(back["versions"] == forward["versions"], "Question versions changed before return move")
        check_location(location)
        apply(back, "Return preview")
    check_location(source)
    print("[PASS] Question entry returned to the original category", flush=True)


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
    parser.add_argument("--url", required=True, help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    for name in ("contextid", "sourceid", "targetid", "alternateid", "questionid"):
        parser.add_argument("--" + name, type=positive_int, required=True)
    parser.add_argument("--timeout", type=positive_int, default=60, help="Request timeout in seconds")
    parser.add_argument("--allow-move", action="store_true", required=True,
                        help="Acknowledge this moves a real entry twice, or up to three times with --concurrent")
    parser.add_argument("--concurrent", action="store_true",
                        help="Race one move token, then competing moves to source/alternate; return to source")
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
