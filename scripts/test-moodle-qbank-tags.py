"""Opt-in live tag replacement and clearing test for one disposable question."""

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

FIELDS = ("contextid", "categoryid", "entryid", "questionid", "version", "oldtags", "newtags")


def run(client, args):
    move.require(1 <= len(args.tagids) <= 50 and len(set(args.tagids)) == len(args.tagids),
                 "Specify 1-50 distinct standard tag IDs")
    move.require(not args.concurrent or len(args.tagids) >= 2, "--concurrent requires at least two distinct tag IDs")
    info = move.question(client, args.contextid, args.questionid, args.categoryid)
    item = info["question"]
    entry, version = item["entryid"], item["version"]
    bank = move.categories(client, args.contextid)
    print("Selected question:", item, flush=True)

    def check_question():
        current = move.question(client, args.contextid, args.questionid, args.categoryid, entry)
        move.require(current["question"] == item and current["versions"] == info["versions"],
                     "Unexpected question metadata/version changes")
        move.require(move.categories(client, args.contextid) == bank, "Unexpected category or question-count changes")

    def preview(tagids, oldtags):
        parameters = {"clear": True} if not tagids else {"tagids": tagids}
        result = client.call("preview_tags", contextid=args.contextid, questionid=args.questionid,
                             expectedversion=version, **parameters)
        move.require(result["contextid"] == args.contextid and result["categoryid"] == args.categoryid
                     and result["entryid"] == entry and result["questionid"] == args.questionid
                     and result["version"] == version and result["oldtags"] == oldtags
                     and [tag["id"] for tag in result["newtags"]] == tagids
                     and qbank.is_hex64(result["previewtoken"]), "Unexpected tag preview")
        check_question()
        return result

    def verify(result, changed):
        move.require(all(changed[key] == result[key] for key in FIELDS)
                     and qbank.is_int(changed["tagsid"], 1), "Unexpected tag change result")
        check_question()
        print(f"[PASS] Tag change applied; audit ID: {changed['tagsid']}", flush=True)

    def confirm():
        move.require(input("Confirm isolated staging, disposable question and no other bank/global tag writes; "
                           "type TAGS: ").strip() == "TAGS", "Cancelled by user")

    def apply(result):
        move.show_preview("Tag preview", result)
        confirm()
        changed = client.call("change_tags", previewtoken=result["previewtoken"], exclusive=True)
        verify(result, changed)

    def expect_error(token, code):
        try:
            client.call("change_tags", previewtoken=token, exclusive=True)
        except qbank.ApiError as error:
            move.require(error.errorcode == code,
                         f"Expected {code}; got {error.errorcode or 'a transport/response error'}")
        else:
            raise move.TestFailure(f"Expected {code}, but the change succeeded")
        print(f"[PASS] Rejected with {code}", flush=True)

    first = preview(args.tagids, [])
    stale = preview(args.tagids, [])
    move.require(first["newtags"] == stale["newtags"], "Tags changed between previews")
    move.show_preview("Stale-test preview", stale)
    if args.concurrent:
        move.show_preview("Concurrent single-token tag preview", first)
        confirm()
        winner, changed = move.race(client, "change_tags", [first, first], "tagspreviewused")
        verify(winner, changed)
    else:
        apply(first)
    assigned = first["newtags"]
    preview([], assigned)
    print("[PASS] Assigned tags verified by a fresh preview", flush=True)
    expect_error(first["previewtoken"], "tagspreviewused")
    expect_error(stale["previewtoken"], "tagsstale")
    clear = preview([], assigned)
    print("[PASS] Rejected calls left tag assignments unchanged", flush=True)
    if args.concurrent:
        subset = preview(args.tagids[:-1], assigned)
        move.show_preview("First competing tag replacement", subset)
        move.show_preview("Second competing tag replacement (clear)", clear)
        confirm()
        winner, changed = move.race(client, "change_tags", [subset, clear], "tagsstale")
        verify(winner, changed)
        preview(args.tagids, winner["newtags"])
        if winner["newtags"]:
            apply(preview([], winner["newtags"]))
    else:
        apply(clear)
    preview(args.tagids, [])
    print("[PASS] Cleared tags verified by a fresh preview", flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    for name in ("contextid", "categoryid", "questionid"):
        parser.add_argument("--" + name, type=move.positive_int, required=True)
    parser.add_argument("--tagids", type=move.positive_int, nargs="+", required=True,
                        help="Existing standard question tag IDs, in desired order")
    parser.add_argument("--timeout", type=move.positive_int, default=60)
    parser.add_argument("--allow-tag-change", action="store_true", required=True,
                        help="Acknowledge this changes real tags twice, or up to three times with --concurrent")
    parser.add_argument("--concurrent", action="store_true",
                        help="Race one tag token, then subset/clear previews; requires at least two tag IDs")
    args = parser.parse_args(argv)
    if args.concurrent and len(args.tagids) < 2:
        parser.error("--concurrent requires at least two distinct --tagids")
    try:
        client = qbank.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
        run(client, args)
    except qbank.ApiError as error:
        print(f"[FAIL] API error: {error}", file=sys.stderr)
    except (move.TestFailure, KeyError, TypeError, ValueError, EOFError, KeyboardInterrupt) as error:
        print(f"[FAIL] {type(error).__name__}: {error}", file=sys.stderr)
    else:
        print("All tag checks passed.")
        return 0
    print("STOP: check the question tags and tag audit before retrying; no automatic recovery was attempted.",
          file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
