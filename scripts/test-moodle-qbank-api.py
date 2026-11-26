"""Live smoke and safety tests for the local_bosonanceqbank web service.

By default nothing in the question bank is changed: only read functions,
previews and rejected import calls run. Previews upload small XML files into
the token user's private draft area (Moodle removes stale drafts) and record a
single-use preview token. Question text is never printed.

Real imports run only with both --import-categoryid and --allow-import. They
create one uniquely named child category under that category containing four
small test questions. There is no delete API, so remove that category in the
question bank UI afterwards; its ID is printed.
"""

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys
import uuid
from xml.etree import ElementTree
from xml.sax.saxutils import escape

_spec = importlib.util.spec_from_file_location(
    "moodle_qbank_transport", Path(__file__).with_name("moodle_qbank_transport.py")
)
if _spec is None or _spec.loader is None:
    raise RuntimeError("Cannot load the question-bank API client")
if _spec.name in sys.modules:
    _transport = sys.modules[_spec.name]
else:
    _transport = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(_transport)
    sys.modules[_spec.name] = _transport

ApiError = _transport.ApiError
MoodleClient = _transport.MoodleClient
NoRedirect = _transport.NoRedirect
PREFIX = _transport.PREFIX
STATUSES = _transport.STATUSES
SUMMARY_KEYS = _transport.SUMMARY_KEYS
CONTENT_KEYS = _transport.CONTENT_KEYS
is_int = _transport.is_int
MAX_ID = 2**31 - 1
REPO_ROOT = Path(__file__).resolve().parent.parent.parent
DEFAULT_XML = (
    REPO_ROOT / "question-papers-vault-moodle" / "ncert-exemplar-question-bank" / "class11"
    / "biology" / "keep401_neet_ug_english.xml"
)


class SkipTest(Exception):
    pass


class Runner:
    def __init__(self):
        self.results = []

    def record(self, status, name, detail=""):
        self.results.append((status, name))
        print(f"[{status}] {name}" + (f" - {detail}" if detail else ""), flush=True)

    def check(self, name, func):
        try:
            detail = func()
        except SkipTest as exc:
            self.record("SKIP", name, str(exc))
            return False
        except (AssertionError, ApiError, KeyError, TypeError) as exc:
            self.record("FAIL", name, f"{type(exc).__name__}: {exc}" if not isinstance(exc, ApiError) else str(exc))
            return False
        self.record("PASS", name, detail if isinstance(detail, str) else "")
        return True

    def expect_error(self, name, codes, func):
        def run():
            try:
                func()
            except ApiError as exc:
                if exc.errorcode is None:
                    raise
                assert exc.errorcode in codes, f"expected {sorted(codes)}, got {exc.errorcode}"
                return f"rejected with {exc.errorcode}"
            raise AssertionError("call succeeded but should have been rejected")
        return self.check(name, run)

    def summary(self):
        counts = {s: sum(1 for r in self.results if r[0] == s) for s in ("PASS", "FAIL", "SKIP")}
        print(f"\nSummary: {counts['PASS']} passed, {counts['FAIL']} failed, {counts['SKIP']} skipped")
        return counts["FAIL"] == 0


def assert_category(item):
    assert isinstance(item, dict) and {"categoryid", "parentid", "name", "entrycount"}.issubset(item), "category keys"
    assert is_int(item["categoryid"], 1) and is_int(item["parentid"]) and is_int(item["entrycount"]), "category ints"
    assert isinstance(item["name"], str), "category name"


def assert_summary(item):
    assert isinstance(item, dict) and SUMMARY_KEYS.issubset(item), "question summary keys missing"
    assert is_int(item["questionid"], 1) and is_int(item["entryid"], 1) and is_int(item["categoryid"], 1), "ids"
    assert is_int(item["version"], 1) and item["status"] in ("ready", "draft", "hidden"), "version/status"
    assert isinstance(item["name"], str) and isinstance(item["qtype"], str) and is_int(item["timemodified"]), "fields"


def fetch_all(client, function, validator, page_size=100, **parameters):
    items, offset = [], 0
    while True:
        result = client.call(function, offset=offset, limit=page_size, **parameters)
        assert isinstance(result, dict) and is_int(result.get("total")) and isinstance(result.get("items"), list)
        for item in result["items"]:
            validator(item)
        items.extend(result["items"])
        offset += page_size
        if offset >= result["total"] or not result["items"]:
            assert len(items) == result["total"], f"paged {len(items)} items but total is {result['total']}"
            return items


def category_path(categories, categoryid):
    byid = {c["categoryid"]: c for c in categories}
    segments, current = [], byid[categoryid]
    while current["parentid"]:
        segments.insert(0, current["name"])
        current = byid[current["parentid"]]
    return "/".join(["$module$", "top", *segments])


def mc_xml(path, names, fractions=(100, 0), prolog=""):
    records = [f'<question type="category"><category><text>{escape(path)}</text></category></question>']
    for name in names:
        answers = "".join(f'<answer fraction="{f}"><text>Option {i}</text></answer>' for i, f in enumerate(fractions))
        records.append(
            f'<question type="multichoice"><name><text>{escape(name)}</text></name>'
            '<questiontext format="html"><text>API test question</text></questiontext>'
            f"<single>true</single>{answers}</question>"
        )
    return ('<?xml version="1.0" encoding="UTF-8"?>\n' + prolog + "<quiz>" + "".join(records) + "</quiz>").encode()


def snapshot(client, contextid):
    categories = fetch_all(client, "list_categories", assert_category, contextid=contextid)
    totals = {}
    for category in categories:
        result = client.call("list_questions", contextid=contextid, categoryid=category["categoryid"], status="all", limit=1)
        totals[category["categoryid"]] = (category["entrycount"], result["total"])
    return totals


def run_tests(client, args):
    runner = Runner()
    state = {}
    ctx = args.contextid

    if args.read_only:
        def before_reads():
            state["readonly_before"] = snapshot(client, ctx)
        if not runner.check("read-only: initial bank counts", before_reads):
            return runner

    # list_categories
    def categories_all():
        cats = fetch_all(client, "list_categories", assert_category, contextid=ctx)
        tops = [c for c in cats if c["parentid"] == 0]
        assert len(tops) == 1, f"expected one top category, found {len(tops)}"
        ids = {c["categoryid"] for c in cats}
        assert len(ids) == len(cats), "duplicate category IDs"
        assert all(c["parentid"] in ids for c in cats if c["parentid"]), "category parent outside listed context"
        state["categories"], state["top"] = cats, tops[0]
        return f"{len(cats)} categories"
    if not runner.check("list_categories: full paging and tree integrity", categories_all):
        print("Cannot continue without categories.")
        return runner

    def categories_paging():
        first = client.call("list_categories", contextid=ctx, offset=0, limit=1)
        assert len(first["items"]) == min(1, first["total"])
        if first["total"] > 1:
            second = client.call("list_categories", contextid=ctx, offset=1, limit=1)
            assert second["items"][0]["categoryid"] != first["items"][0]["categoryid"], "pages overlap"
        beyond = client.call("list_categories", contextid=ctx, offset=first["total"], limit=1)
        assert beyond["items"] == [] and beyond["total"] == first["total"], "offset past end not empty"
    runner.check("list_categories: limit=1 pages are distinct; offset past end is empty", categories_paging)

    cats = state["categories"]
    if args.categoryid:
        target = next((c for c in cats if c["categoryid"] == args.categoryid), None)
        if target is None:
            runner.record("FAIL", "--categoryid belongs to --contextid", f"category {args.categoryid} not listed")
            return runner
    else:
        target = max(cats, key=lambda c: (c["entrycount"], c["parentid"] != 0))
    tid, topid = target["categoryid"], state["top"]["categoryid"]
    print(f"Using category {tid} ({target['entrycount']} entries); top category {topid}", flush=True)

    # list_questions
    def questions_by_status():
        totals = {}
        for status in STATUSES:
            items = fetch_all(client, "list_questions", assert_summary, contextid=ctx, categoryid=tid, status=status)
            for item in items:
                assert item["categoryid"] == tid, "question from another category"
                assert status == "all" or item["status"] == status, f"{status} filter returned {item['status']}"
            assert len({i["entryid"] for i in items}) == len(items), "entry listed more than once"
            totals[status] = items
        assert len(totals["all"]) == sum(len(totals[s]) for s in ("ready", "draft", "hidden")), "status totals mismatch"
        assert len(totals["all"]) == target["entrycount"], f"all={len(totals['all'])} entrycount={target['entrycount']}"
        state["questions"] = totals
        return ", ".join(f"{s}={len(v)}" for s, v in totals.items())
    runner.check("list_questions: every status filter, one latest version per entry", questions_by_status)

    questions = state.get("questions", {})
    allq = questions.get("all", [])

    def questions_filters():
        if not allq:
            raise SkipTest("category has no questions")
        sample = allq[0]
        byqtype = client.call("list_questions", contextid=ctx, categoryid=tid, status="all", qtype=sample["qtype"], limit=100)
        assert byqtype["items"] and all(i["qtype"] == sample["qtype"] for i in byqtype["items"]), "qtype filter"
        found = client.call("list_questions", contextid=ctx, categoryid=tid, status="all", search=sample["name"], limit=100)
        assert any(i["questionid"] == sample["questionid"] for i in found["items"]), "search filter missed question"
        none = client.call("list_questions", contextid=ctx, categoryid=tid, status="all", search="zz-no-match-" + uuid.uuid4().hex)
        assert none["total"] == 0 and none["items"] == [], "search for random text returned results"
        notag = client.call("list_questions", contextid=ctx, categoryid=tid, status="all", tag="zz-no-tag-" + uuid.uuid4().hex)
        assert notag["total"] == 0, "unknown tag returned results"
    runner.check("list_questions: qtype, search and tag filters", questions_filters)

    # get_question
    def question_details():
        if not allq:
            raise SkipTest("category has no questions")
        sample = allq[0]
        plain = client.call("get_question", contextid=ctx, questionid=sample["questionid"])
        assert plain.get("content") is None, "content returned without includecontent"
        assert_summary(plain["question"])
        assert plain["question"]["questionid"] == sample["questionid"], "wrong question returned"
        assert isinstance(plain["inuse"], bool) and isinstance(plain["entryinuse"], bool), "inuse flags"
        assert is_int(plain["versiontotal"], 1) and plain["versions"], "versions"
        for version in plain["versions"]:
            assert is_int(version["questionid"], 1) and is_int(version["version"], 1)
            assert version["status"] in ("ready", "draft", "hidden")
        full = client.call("get_question", contextid=ctx, questionid=sample["questionid"], includecontent=True)
        assert isinstance(full.get("content"), dict) and CONTENT_KEYS.issubset(full["content"]), "content missing"
        paged = client.call("get_question", contextid=ctx, questionid=sample["questionid"], offset=0, limit=1)
        assert len(paged["versions"]) == 1 and paged["versiontotal"] == plain["versiontotal"], "versions paging"
        return f"question {sample['questionid']}, {plain['versiontotal']} version(s), inuse={plain['inuse']}"
    runner.check("get_question: summary, versions paging, includecontent", question_details)

    # export_xml
    def export():
        exportable = [q for q in allq if q["status"] != "hidden"][: args.export_count]
        if not exportable:
            raise SkipTest("no non-hidden questions to export")
        result = client.call("export_xml", contextid=ctx, questionids=[q["questionid"] for q in exportable])
        xml = result["xml"].encode("utf-8")
        assert hashlib.sha256(xml).hexdigest() == result["sha256"], "sha256 does not match xml"
        assert result["questioncount"] == len(exportable), "questioncount mismatch"
        root = ElementTree.fromstring(xml)
        assert root.tag == "quiz", "root is not quiz"
        records = root.findall("question")
        assert sum(1 for r in records if r.get("type") != "category") == len(exportable), "question record count"
        paths = [r.findtext("category/text") or "" for r in records if r.get("type") == "category"]
        assert paths and all(p.startswith("$module$/top") for p in paths), "category paths are not $module$/top"
        state["export"] = (xml, {q["qtype"] for q in exportable})
        return f"{len(exportable)} question(s), {len(xml)} bytes"
    runner.check("export_xml: hash, count and category paths", export)

    if args.read_only:
        def unchanged_after_reads():
            assert snapshot(client, ctx) == state["readonly_before"], "bank counts changed during read-only checks"
        runner.check("read-only: bank counts unchanged", unchanged_after_reads)
        runner.record("SKIP", "preview/import and write-function checks", "--read-only calls only read/export functions")
        return runner

    # preview_xml
    try:
        before = snapshot(client, ctx)
    except (ApiError, AssertionError) as exc:
        runner.record("FAIL", "snapshot before preview", str(exc))
        return runner

    def preview(content, filename, categoryid):
        itemid = client.upload_draft(filename, content)
        return client.call("preview_xml", contextid=ctx, categoryid=categoryid, draftitemid=itemid, filename=filename)

    def assert_preview(result, content, categoryid):
        assert result["contextid"] == ctx and result["categoryid"] == categoryid, "preview echoed wrong target"
        assert result["sha256"] == hashlib.sha256(content).hexdigest(), "preview sha256 mismatch"
        assert result["canapply"] is True, "validated preview must allow apply"
        assert is_hex64(result["previewtoken"]) and is_hex64(result["warningshash"]), "token or warnings hash format"
        assert is_int(result["expires"], 1), "expires"
        repeat = any(w["code"] == "exact_repeat" for w in result["warnings"])
        assert result["requiresduplicateoverride"] is repeat, "requiresduplicateoverride disagrees with exact_repeat"
        assert _transport.valid_import_tags(result.get("tags")), "invalid preview tag plan"
        for c in result["categories"]:
            assert c["action"] in ("reuse", "create"), "unknown category action"
            assert (c["categoryid"] == 0) == (c["action"] == "create"), "create/reuse does not match categoryid"

    def preview_file():
        path = Path(args.xml)
        if not path.is_file():
            raise SkipTest(f"{path} not found")
        content = path.read_bytes()
        result = preview(content, "qbank-api-test.xml", topid)
        assert_preview(result, content, topid)
        actions = {}
        for c in result["categories"]:
            actions[c["action"]] = actions.get(c["action"], 0) + 1
        dupes = sum(1 for w in result["warnings"] if w["code"] == "possible_existing_duplicate")
        return f"{result['questioncount']} questions, categories {actions}, {dupes} existing-duplicate warning(s)"
    runner.check("preview_xml: generated XML file into top category", preview_file)

    def preview_roundtrip():
        if "export" not in state:
            raise SkipTest("export did not run")
        xml, qtypes = state["export"]
        if not qtypes.issubset({"multichoice", "match"}):
            raise SkipTest(f"export contains types preview does not support: {sorted(qtypes)}")
        result = preview(xml, "qbank-api-roundtrip.xml", topid)
        assert_preview(result, xml, topid)
        flagged = {w["index"] for w in result["warnings"] if w["code"] == "possible_existing_duplicate"}
        assert len(flagged) == result["questioncount"], f"only {len(flagged)}/{result['questioncount']} flagged"
        assert all(c["action"] == "reuse" for c in result["categories"]), "exported categories should be reused"
        return "every re-imported question flagged as an existing duplicate"
    runner.check("preview_xml: export round trip warns about re-import", preview_roundtrip)

    def preview_in_file_duplicate():
        name = "API dup test " + uuid.uuid4().hex[:8]
        content = mc_xml(category_path(cats, tid), [name, name])
        result = preview(content, "qbank-api-dup.xml", tid)
        assert_preview(result, content, tid)
        assert any(w["code"] == "duplicate_in_file" and w["index"] == 2 for w in result["warnings"]), "no in-file warning"
        assert not any(w["code"] == "possible_existing_duplicate" for w in result["warnings"]), "unexpected match"
    runner.check("preview_xml: duplicate_in_file warning", preview_in_file_duplicate)

    def preview_create_path():
        content = mc_xml(category_path(cats, tid) + "/api-test-" + uuid.uuid4().hex[:8], ["API create test"])
        result = preview(content, "qbank-api-create.xml", tid)
        assert_preview(result, content, tid)
        assert [c["action"] for c in result["categories"]] == ["create"], "new path should be reported as create"
        state["createpreview"] = result
    runner.check("preview_xml: missing category reported as create, not created", preview_create_path)

    # import_xml guards that must reject before writing anything
    runner.expect_error("import_xml: malformed token rejected", {"invalidparameter", "previewinvalid"},
                        lambda: client.call("import_xml", previewtoken="zz", warningshash="0" * 64))
    runner.expect_error("import_xml: unknown token rejected", {"previewinvalid"},
                        lambda: client.call("import_xml", previewtoken=uuid.uuid4().hex * 2, warningshash="0" * 64))

    def wrong_acknowledgement():
        if "createpreview" not in state:
            raise SkipTest("create preview did not run")
        result = state["createpreview"]
        wrong = "0" * 64 if result["warningshash"] != "0" * 64 else "1" * 64
        client.call("import_xml", previewtoken=result["previewtoken"], warningshash=wrong)
    runner.expect_error("import_xml: wrong warnings hash rejected", {"acknowledgement"}, wrong_acknowledgement)

    # Negative and safety checks
    bad = {"invalidparameter"}
    missing = {"invalidrecord", "invalidrecordunknown", "wrongcontext"}
    call = client.call
    runner.expect_error("list_categories: limit=101 rejected", bad, lambda: call("list_categories", contextid=ctx, limit=101))
    runner.expect_error("list_categories: limit=0 rejected", bad, lambda: call("list_categories", contextid=ctx, limit=0))
    runner.expect_error("list_categories: offset=-1 rejected", bad, lambda: call("list_categories", contextid=ctx, offset=-1))
    runner.expect_error("list_categories: system context rejected", {"invalidbank"}, lambda: call("list_categories", contextid=1))
    runner.expect_error("list_questions: invalid status rejected", bad, lambda: call("list_questions", contextid=ctx, categoryid=tid, status="bogus"))
    runner.expect_error("list_questions: unknown category rejected", missing, lambda: call("list_questions", contextid=ctx, categoryid=MAX_ID))
    runner.expect_error("get_question: unknown question rejected", missing, lambda: call("get_question", contextid=ctx, questionid=MAX_ID))
    runner.expect_error("get_question: limit=101 rejected", bad, lambda: call("get_question", contextid=ctx, questionid=allq[0]["questionid"] if allq else MAX_ID, limit=101))
    runner.expect_error("export_xml: empty selection rejected", bad, lambda: call("export_xml", contextid=ctx, questionids=[]))
    runner.expect_error("export_xml: 101 IDs rejected", bad, lambda: call("export_xml", contextid=ctx, questionids=list(range(1, 102))))
    if allq:
        qid = allq[0]["questionid"]
        runner.expect_error("export_xml: duplicate IDs rejected", bad, lambda: call("export_xml", contextid=ctx, questionids=[qid, qid]))
    hidden = questions.get("hidden", [])
    if hidden:
        runner.expect_error("export_xml: hidden question rejected", {"hiddenexport"}, lambda: call("export_xml", contextid=ctx, questionids=[hidden[0]["questionid"]]))
    else:
        runner.record("SKIP", "export_xml: hidden question rejected", "no hidden questions in category")

    if args.other_contextid:
        def cross_bank():
            other = call("list_categories", contextid=args.other_contextid, limit=1)["items"]
            if not other:
                raise SkipTest("other bank has no categories")
            try:
                call("list_questions", contextid=ctx, categoryid=other[0]["categoryid"])
            except ApiError as exc:
                assert exc.errorcode == "wrongcontext", f"expected wrongcontext, got {exc.errorcode}"
                return "rejected with wrongcontext"
            raise AssertionError("category from another bank was accepted")
        runner.check("list_questions: category from another bank rejected", cross_bank)
    else:
        runner.record("SKIP", "list_questions: category from another bank rejected", "pass --other-contextid")

    decl = '<?xml version="1.0" encoding="UTF-8"?>'
    cases = [
        ("malformed XML", b"<quiz><question", {"xmlinvalid"}, topid),
        ("DOCTYPE", mc_xml("$module$/top/x", ["a"], prolog='<!DOCTYPE quiz [<!ENTITY e "x">]>'), {"xmlinvalid"}, topid),
        ("wrong root element", (decl + "<notquiz/>").encode(), {"xmlinvalid"}, topid),
        ("unsupported question type", (decl + '<quiz><question type="essay"><name><text>x</text></name></question></quiz>').encode(), {"xmltype"}, topid),
        ("path traversal segment", mc_xml("$module$/top/../evil", ["a"]), {"xmlpath"}, topid),
        ("path outside $module$/top", mc_xml("$course$/top/x", ["a"]), {"xmlpath"}, topid),
        ("invalid answer fractions", mc_xml(category_path(cats, tid), ["a"], fractions=(50, 0)), {"xmlquestion"}, tid),
        ("empty quiz", (decl + "<quiz></quiz>").encode(), {"xmllimit"}, topid),
        ("category records only, no questions", mc_xml(category_path(cats, tid), []), {"xmlquestion"}, tid),
    ]
    if target["parentid"]:
        cases.append(("path outside selected category subtree", mc_xml("$module$/top/outside-" + uuid.uuid4().hex[:8], ["a"]), {"xmlscope"}, tid))
    else:
        runner.record("SKIP", "preview_xml: path outside selected category subtree rejected", "target is top; pass a child --categoryid")
    for label, content, codes, catid in cases:
        runner.expect_error(f"preview_xml: {label} rejected", codes, lambda c=content, cid=catid: preview(c, "qbank-api-negative.xml", cid))
    runner.expect_error(
        "preview_xml: missing draft file rejected",
        {"invalidfile"},
        lambda: call("preview_xml", contextid=ctx, categoryid=topid, draftitemid=MAX_ID, filename="missing.xml"),
    )

    def unchanged():
        after = snapshot(client, ctx)
        changed = [cid for cid in set(before) | set(after) if before.get(cid) != after.get(cid)]
        assert not changed, f"categories changed during tests: {sorted(changed)[:10]}"
        return f"{len(after)} categories, entry counts identical"
    runner.check("bank unchanged after all previews and negative tests", unchanged)

    if args.allow_import:
        run_import_tests(runner, client, ctx, cats, args.import_categoryid, preview, assert_preview)
    else:
        runner.record("SKIP", "import_xml: real import tests", "pass --import-categoryid and --allow-import")
    return runner


def run_import_tests(runner, client, ctx, cats, parentid, preview, assert_preview):
    """Import into a fresh, uniquely named child category of parentid and verify every safety gate."""
    if parentid not in {c["categoryid"] for c in cats}:
        runner.record("FAIL", "--import-categoryid belongs to --contextid", f"category {parentid} not listed")
        return
    before = snapshot(client, ctx)
    suffix = uuid.uuid4().hex[:8]
    path = category_path(cats, parentid) + "/qbank-api-import-" + suffix
    first = mc_xml(path, [f"API import {suffix} A", f"API import {suffix} B"])
    second = mc_xml(path, [f"API import {suffix} C"])
    state = {}

    def call_import(result, allowduplicate=False):
        return client.call("import_xml", previewtoken=result["previewtoken"], warningshash=result["warningshash"],
                           allowduplicate=allowduplicate)

    def assert_import(result, content, count, override):
        assert is_int(result["importid"], 1) and result["contextid"] == ctx and result["categoryid"] == parentid
        assert result["sha256"] == hashlib.sha256(content).hexdigest(), "import sha256 mismatch"
        assert result["questioncount"] == count == len(result["questions"]), "import question count"
        assert result["duplicateoverride"] is override, "duplicateoverride"
        newid = result["categories"][0]["categoryid"]
        assert is_int(newid, 1) and all(q["categoryid"] == newid for q in result["questions"]), "wrong destination"
        return newid

    def import_new():
        result = preview(first, "qbank-api-import.xml", parentid)
        assert_preview(result, first, parentid)
        assert [c["action"] for c in result["categories"]] == ["create"] and not result["warnings"], "unexpected preview"
        imported = call_import(result)
        newid = assert_import(imported, first, 2, False)
        state.update(token=result, categoryid=newid)
        print(f"Created test category {newid} ({path}); delete it in the question bank UI when done", flush=True)
        listed = client.call("list_questions", contextid=ctx, categoryid=newid, status="all", limit=100)
        assert sorted(i["questionid"] for i in listed["items"]) == sorted(q["questionid"] for q in imported["questions"])
        return f"category {newid}, questions {[q['questionid'] for q in imported['questions']]}"
    if not runner.check("import_xml: preview then import creates exactly the previewed questions", import_new):
        return

    runner.expect_error("import_xml: used token rejected", {"previewused"}, lambda: call_import(state["token"]))

    def repeat_preview():
        result = preview(first, "qbank-api-import.xml", parentid)
        assert_preview(result, first, parentid)
        assert result["requiresduplicateoverride"] is True, "exact repeat not detected"
        assert result["warnings"][0]["code"] == "exact_repeat", "exact_repeat must be the first warning"
        assert [c["action"] for c in result["categories"]] == ["reuse"], "category should now be reused"
        state["repeat"] = result
    if not runner.check("preview_xml: re-preview flags exact_repeat and requires override", repeat_preview):
        return
    runner.expect_error("import_xml: exact repeat without override rejected", {"duplicateimport"},
                        lambda: call_import(state["repeat"]))

    def stale_after_change():
        pending = preview(second, "qbank-api-import-2.xml", parentid)
        assert_preview(pending, second, parentid)
        assert_import(call_import(state["repeat"], allowduplicate=True), first, 2, True)
        try:
            call_import(pending)
        except ApiError as exc:
            assert exc.errorcode == "previewstale", f"expected previewstale, got {exc.errorcode}"
            return "override import succeeded; older preview rejected as stale"
        raise AssertionError("stale preview was imported")
    runner.check("import_xml: explicit override imports; preview taken before a bank change is stale", stale_after_change)

    def only_test_category_changed():
        after = snapshot(client, ctx)
        changed = sorted(cid for cid in set(before) | set(after) if before.get(cid) != after.get(cid))
        assert changed == [state["categoryid"]], f"unexpected categories changed: {changed[:10]}"
        assert after[state["categoryid"]] == (4, 4), f"test category holds {after[state['categoryid']]}"
        return "only the test category changed (4 questions)"
    runner.check("import_xml: no other category changed", only_test_category_changed)


def is_hex64(value):
    return isinstance(value, str) and len(value) == 64 and all(c in "0123456789abcdef" for c in value)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--url", required=True, help="Moodle base URL; HTTP is allowed only for localhost/loopback")
    parser.add_argument("--contextid", type=int, required=True, help="Module context ID of the qbank/quiz question bank")
    parser.add_argument("--categoryid", type=int, help="Category in that bank to test (default: fullest category)")
    parser.add_argument("--other-contextid", type=int, help="Second bank context ID for cross-bank isolation checks")
    parser.add_argument("--xml", default=str(DEFAULT_XML), help="Moodle XML file to preview (default: generated vault sample)")
    parser.add_argument("--export-count", type=int, default=5, help="Questions to export, 1-100 (default 5)")
    parser.add_argument("--timeout", type=int, default=60, help="HTTP timeout in seconds")
    parser.add_argument("--import-categoryid", type=int,
                        help="Parent category for real import tests; a new uniquely named child is created in it")
    parser.add_argument("--allow-import", action="store_true",
                        help="Required with --import-categoryid to confirm that test questions may be created")
    parser.add_argument("--read-only", action="store_true",
                        help="Production smoke check: read/export only, with no uploads, previews or write calls")
    args = parser.parse_args(argv)
    if not 1 <= args.export_count <= 100:
        parser.error("--export-count must be between 1 and 100")
    if bool(args.import_categoryid) != args.allow_import:
        parser.error("--import-categoryid and --allow-import must be given together")
    if args.read_only and (args.allow_import or args.import_categoryid is not None):
        parser.error("--read-only cannot be combined with import options")
    try:
        client = MoodleClient(args.url, os.environ.get("MOODLE_TOKEN", ""), args.timeout)
    except ApiError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 2
    return 0 if run_tests(client, args).summary() else 1


if __name__ == "__main__":
    sys.exit(main())
