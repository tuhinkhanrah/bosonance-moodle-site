"""Opt-in live status round-trip test for one disposable, unused question."""

import argparse
import importlib.util
import os
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location("qbank_move", Path(__file__).with_name("test-moodle-qbank-move.py"))
if spec is None or spec.loader is None:
    raise RuntimeError("Cannot load the question-bank test helpers")
move = importlib.util.module_from_spec(spec)
spec.loader.exec_module(move)
qbank = move.qbank


def run(client, args):
    info = move.question(client, args.contextid, args.questionid, args.categoryid)
    item = info["question"]
    move.require(item["status"] == "ready", "Disposable question must initially be ready")
    entry, version = item["entryid"], item["version"]
    bank = move.categories(client, args.contextid)
    print("Selected question:", item, flush=True)

    def preview(old, new):
        result = client.call("preview_status", contextid=args.contextid, questionid=args.questionid,
                             expectedversion=version, expectedstatus=old, targetstatus=new)
        move.require(result["contextid"] == args.contextid and result["categoryid"] == args.categoryid
                     and result["entryid"] == entry and result["questionid"] == args.questionid
                     and result["version"] == version and result["oldstatus"] == old
                     and result["newstatus"] == new and qbank.is_hex64(result["previewtoken"]),
                     "Unexpected status preview")
        check_status(old)
        return result

    def check_status(expected):
        current = move.question(client, args.contextid, args.questionid, args.categoryid, entry)
        expected_versions = [dict(record, status=expected) if record["questionid"] == args.questionid
                             else record for record in info["versions"]]
        move.require(current["question"] == dict(item, status=expected)
                     and current["versions"] == expected_versions,
                     "Unexpected question metadata/version/status changes")
        move.require(move.categories(client, args.contextid) == bank, "Unexpected category or question-count changes")

    def verify(result, changed):
        move.require(all(changed[key] == result[key] for key in
                         ("contextid", "categoryid", "entryid", "questionid", "version", "oldstatus", "newstatus"))
                     and qbank.is_int(changed["statusid"], 1),
                     "Unexpected status change result")
        check_status(result["newstatus"])
        print(f"[PASS] {result['oldstatus']} -> {result['newstatus']}; audit ID: {changed['statusid']}", flush=True)

    def apply(result):
        move.show_preview("Status preview", result)
        move.confirm("Confirm isolated staging, disposable question and no other bank writes; type MOVE: ")
        changed = client.call("change_status", previewtoken=result["previewtoken"], exclusive=True)
        verify(result, changed)

    first = preview("ready", "draft")
    stale = preview("ready", "hidden")
    move.show_preview("Stale-test preview", stale)
    if args.concurrent:
        move.show_preview("Concurrent single-token status preview", first)
        move.confirm("Confirm isolated staging, disposable question and no other bank writes; type MOVE: ")
        winner, changed = move.race(client, "change_status", [first, first], "statuspreviewused")
        verify(winner, changed)
    else:
        apply(first)
    for token, code in ((first["previewtoken"], "statuspreviewused"), (stale["previewtoken"], "statusstale")):
        try:
            client.call("change_status", previewtoken=token, exclusive=True)
        except qbank.ApiError as error:
            move.require(error.errorcode == code, f"Expected {code}; got {error.errorcode or 'a transport/response error'}")
        else:
            raise move.TestFailure(f"Expected {code}, but the change succeeded")
        check_status("draft")
        print(f"[PASS] Rejected with {code}; status remains draft", flush=True)
    current_status = "draft"
    if args.concurrent:
        left = preview("draft", "hidden")
        right = preview("draft", "ready")
        move.show_preview("First competing status change", left)
        move.show_preview("Second competing status change", right)
        move.confirm("Confirm isolated staging and no bank writes except this controlled pair; type MOVE: ")
        winner, changed = move.race(client, "change_status", [left, right], "statusstale")
        verify(winner, changed)
        current_status = winner["newstatus"]
    else:
        apply(preview("draft", "hidden"))
        current_status = "hidden"
    if current_status != "ready":
        apply(preview(current_status, "ready"))
    check_status("ready")
    print("[PASS] Question status returned to ready", flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    for name in ("contextid", "categoryid", "questionid"):
        parser.add_argument("--" + name, type=move.positive_int, required=True)
    parser.add_argument("--timeout", type=move.positive_int, default=60)
    parser.add_argument("--allow-status-change", action="store_true", required=True,
                        help="Acknowledge this test changes a real question up to three times")
    parser.add_argument("--concurrent", action="store_true",
                        help="Race one status token, then draft-to-hidden/ready previews; restore ready")
    args = parser.parse_args(argv)
    try:
        client = qbank.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
        run(client, args)
    except qbank.ApiError as error:
        print(f"[FAIL] API error: {error.errorcode or 'transport or invalid response'}", file=sys.stderr)
    except (move.TestFailure, KeyError, TypeError, ValueError, EOFError, KeyboardInterrupt) as error:
        print(f"[FAIL] {type(error).__name__}: {error}", file=sys.stderr)
    else:
        print("All status checks passed.")
        return 0
    print("STOP: check the question status and audit before retrying; no automatic recovery was attempted.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
