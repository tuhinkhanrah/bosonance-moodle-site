"""Opt-in live creation and rename round trip for one disposable category."""

import argparse
import importlib.util
import os
from pathlib import Path
import sys
import uuid

spec = importlib.util.spec_from_file_location("qbank_move", Path(__file__).with_name("test-moodle-qbank-move.py"))
if spec is None or spec.loader is None:
    raise RuntimeError("Cannot load the question-bank test helpers")
move = importlib.util.module_from_spec(spec)
spec.loader.exec_module(move)
qbank = move.qbank

FIELDS = ("contextid", "categoryid", "parentid", "action", "oldname", "newname")


def run(client, args):
    expected = move.categories(client, args.contextid)
    move.require(args.parentid in expected, "Parent must belong to the selected bank")
    original_name = "qbank-api-category-" + uuid.uuid4().hex

    def check_bank():
        move.require(move.categories(client, args.contextid) == expected, "Unexpected category or question-count changes")

    def preview(categoryid, parentid, action, oldname, newname):
        result = client.call("preview_category", contextid=args.contextid, categoryid=categoryid,
                             action=action, name=newname)
        move.require(result["contextid"] == args.contextid and result["categoryid"] == categoryid
                     and result["parentid"] == parentid and result["action"] == action
                     and result["oldname"] == oldname and result["newname"] == newname
                     and qbank.is_hex64(result["previewtoken"]), "Unexpected category preview")
        check_bank()
        return result

    def verify(result, changed):
        move.require(all(changed[key] == result[key] for key in FIELDS)
                     and qbank.is_int(changed["categoryauditid"], 1)
                     and qbank.is_int(changed["resultcategoryid"], 1), "Unexpected category change result")
        categoryid = changed["resultcategoryid"]
        if result["action"] == "create":
            move.require(categoryid not in expected, "Create returned an existing category")
            expected[categoryid] = {"categoryid": categoryid, "parentid": args.parentid,
                                    "name": result["newname"], "entrycount": 0}
            print("Created disposable category ID:", categoryid, flush=True)
        else:
            move.require(categoryid == result["categoryid"], "Rename changed category ID")
            expected[categoryid] = dict(expected[categoryid], name=result["newname"])
        check_bank()
        print("[PASS] Category change verified; audit ID:", changed["categoryauditid"], flush=True)
        return categoryid

    def confirm():
        move.require(input("Confirm isolated staging, backup and no writes except this test; type CATEGORY: ").strip()
                     == "CATEGORY", "Cancelled by user")

    def apply(result):
        move.show_preview("Category preview", result)
        confirm()
        changed = client.call("change_category", previewtoken=result["previewtoken"], exclusive=True)
        return verify(result, changed)

    def expect_error(result, code):
        try:
            client.call("change_category", previewtoken=result["previewtoken"], exclusive=True)
        except qbank.ApiError as error:
            move.require(error.errorcode == code, f"Expected {code}; got {error.errorcode or str(error)}")
        else:
            raise move.TestFailure(f"Expected {code}, but the change succeeded")
        check_bank()
        print(f"[PASS] Rejected with {code}; bank unchanged", flush=True)

    first = preview(args.parentid, args.parentid, "create", "", original_name)
    stale = preview(args.parentid, args.parentid, "create", "", original_name + "-stale")
    if args.check_busy:
        move.show_preview("Lock-contention category preview", first)
        print("Hold the shared plugin bank lock on the staging server for 45 seconds now. "
              "Confirm here within 15 seconds of the server reporting LOCK HELD.", flush=True)
        confirm()
        expect_error(first, "importbusy")
    if args.concurrent:
        move.show_preview("Concurrent single-token create preview", first)
        confirm()
        winner, changed = move.race(client, "change_category", [first, first], "categorypreviewused")
        categoryid = verify(winner, changed)
    else:
        categoryid = apply(first)
    expect_error(first, "categorypreviewused")
    expect_error(stale, "categorystale")
    if args.concurrent:
        left = preview(categoryid, args.parentid, "rename", original_name, original_name + "-left")
        right = preview(categoryid, args.parentid, "rename", original_name, original_name + "-right")
        move.show_preview("First competing rename", left)
        move.show_preview("Second competing rename", right)
        confirm()
        winner, changed = move.race(client, "change_category", [left, right], "categorystale")
        verify(winner, changed)
    else:
        apply(preview(categoryid, args.parentid, "rename", original_name, original_name + "-renamed"))
    apply(preview(categoryid, args.parentid, "rename", expected[categoryid]["name"], original_name))
    print(f"[PASS] Rename round trip; empty category {categoryid} remains for manual staging cleanup", flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    for name in ("contextid", "parentid"):
        parser.add_argument("--" + name, type=move.positive_int, required=True)
    parser.add_argument("--timeout", type=move.positive_int, default=60)
    parser.add_argument("--allow-category-change", action="store_true", required=True,
                        help="Acknowledge this creates a category and renames it twice")
    parser.add_argument("--concurrent", action="store_true",
                        help="Race two independent requests for one token, then two competing rename tokens")
    parser.add_argument("--check-busy", action="store_true",
                        help="Verify importbusy while an operator holds the shared bank lock on staging")
    args = parser.parse_args(argv)
    if args.check_busy and args.timeout <= 30:
        parser.error("--check-busy requires --timeout greater than the 30-second bank-lock wait")
    try:
        client = qbank.MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
        run(client, args)
    except qbank.ApiError as error:
        print(f"[FAIL] API error: {error}", file=sys.stderr)
    except (move.TestFailure, KeyError, TypeError, ValueError, EOFError, KeyboardInterrupt) as error:
        print(f"[FAIL] {type(error).__name__}: {error}", file=sys.stderr)
    else:
        print("All category checks passed.")
        return 0
    print("STOP: inspect categories and audit records before retrying; no automatic recovery was attempted.",
          file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
