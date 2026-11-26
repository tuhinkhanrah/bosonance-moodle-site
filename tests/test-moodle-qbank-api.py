import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest import mock
from urllib.parse import parse_qs
from xml.etree import ElementTree
from xml.sax.saxutils import escape


SCRIPT = Path(__file__).resolve().parent.parent / "scripts" / "test-moodle-qbank-api.py"
SPEC = importlib.util.spec_from_file_location("test_moodle_qbank_api", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
qbank = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(qbank)

CTX = 50


def error(code):
    return qbank.ApiError(f"fake: {code}", code)


class FakeMoodle:
    """In-memory model of the local_bosonanceqbank contract."""

    def __init__(self, mutate_on_preview=False, ignore_repeats=False):
        self.categories = [
            {"categoryid": 1, "parentid": 0, "name": "top"},
            {"categoryid": 2, "parentid": 1, "name": "chapter"},
            {"categoryid": 3, "parentid": 9, "name": "other bank"},
        ]
        self.questions = [
            {"questionid": 10 + i, "entryid": 100 + i, "categoryid": 2, "version": 1, "status": status,
             "name": f"Question {i}", "qtype": "multichoice", "timemodified": 1}
            for i, status in enumerate(["ready", "ready", "draft", "hidden"])
        ]
        self.drafts = {}
        self.previews = {}
        self.imports = []
        self.mutate_on_preview = mutate_on_preview
        self.ignore_repeats = ignore_repeats

    def bank_categories(self, contextid):
        if contextid == 1:
            raise error("invalidbank")
        return [c for c in self.categories if (c["categoryid"] == 3) == (contextid != CTX)]

    def category(self, contextid, categoryid):
        if categoryid not in {c["categoryid"] for c in self.categories}:
            raise error("invalidrecordunknown")
        if categoryid not in {c["categoryid"] for c in self.bank_categories(contextid)}:
            raise error("wrongcontext")
        return next(c for c in self.categories if c["categoryid"] == categoryid)

    @staticmethod
    def page(items, offset, limit):
        if offset < 0 or not 1 <= limit <= 100:
            raise error("invalidparameter")
        return {"total": len(items), "items": items[offset:offset + limit]}

    def path(self, categoryid):
        names, current = [], self.category(CTX, categoryid)
        while current["parentid"]:
            names.insert(0, current["name"])
            current = self.category(CTX, current["parentid"])
        return "/".join(["$module$", "top", *names])

    def call(self, function, contextid=None, offset=0, limit=50, **params):
        if function == "import_xml":
            return self.import_xml(**params)
        cats = self.bank_categories(contextid)
        if function == "list_categories":
            items = [dict(c, entrycount=sum(q["categoryid"] == c["categoryid"] for q in self.questions)) for c in cats]
            return self.page(items, offset, limit)
        if function == "list_questions":
            self.category(contextid, params["categoryid"])
            status = params.get("status", "ready")
            if status not in qbank.STATUSES:
                raise error("invalidparameter")
            items = [
                q for q in self.questions
                if q["categoryid"] == params["categoryid"] and status in ("all", q["status"])
                and params.get("qtype", "") in ("", q["qtype"]) and params.get("search", "") in q["name"]
                and not params.get("tag")
            ]
            return self.page(items, offset, limit)
        if function == "get_question":
            question = next((q for q in self.questions if q["questionid"] == params["questionid"]), None)
            if question is None:
                raise error("invalidrecordunknown")
            result = self.page([{"questionid": question["questionid"], "version": 1, "status": question["status"]}], offset, limit)
            response = {"question": question, "inuse": False, "entryinuse": False,
                        "versiontotal": result["total"], "versions": result["items"]}
            if params.get("includecontent"):
                response["content"] = {"questiontext": "x", "questiontextformat": 1,
                                       "generalfeedback": "", "generalfeedbackformat": 1}
            return response
        if function == "export_xml":
            ids = params["questionids"]
            if not 1 <= len(ids) <= 100 or len(set(ids)) != len(ids):
                raise error("invalidparameter")
            selected = [q for q in self.questions if q["questionid"] in ids]
            if any(q["status"] == "hidden" for q in selected):
                raise error("hiddenexport")
            body = "".join(
                f'<question type="category"><category><text>{self.path(q["categoryid"])}</text></category></question>'
                f'<question type="multichoice"><name><text>{escape(q["name"])}</text></name>'
                '<questiontext><text>x</text></questiontext><single>true</single>'
                '<answer fraction="100"><text>a</text></answer><answer fraction="0"><text>b</text></answer></question>'
                for q in selected
            )
            xml = '<?xml version="1.0" encoding="UTF-8"?><quiz>' + body + "</quiz>"
            return {"xml": xml, "sha256": hashlib.sha256(xml.encode()).hexdigest(), "questioncount": len(selected)}
        if function == "preview_xml":
            return self.preview(contextid, params["categoryid"], params["draftitemid"], params["filename"])
        raise AssertionError(function)

    def upload_draft(self, filename, content):
        itemid = len(self.drafts) + 1
        self.drafts[itemid] = (filename, content)
        return itemid

    def preview(self, contextid, categoryid, itemid, filename):
        self.category(contextid, categoryid)
        if self.drafts.get(itemid, (None,))[0] != filename:
            raise error("invalidfile")
        content = self.drafts[itemid][1]
        if b"DOCTYPE" in content:
            raise error("xmlinvalid")
        try:
            root = ElementTree.fromstring(content)
        except ElementTree.ParseError:
            raise error("xmlinvalid") from None
        if root.tag != "quiz":
            raise error("xmlinvalid")
        if not len(root):
            raise error("xmllimit")
        rootpath, paths, questions, current = self.path(categoryid), [], [], ""
        for record in root:
            if record.get("type") == "category":
                current = record.findtext("category/text")
                segments = current.split("/")
                if segments[:2] != ["$module$", "top"] or ".." in segments:
                    raise error("xmlpath")
                if current != rootpath and not current.startswith(rootpath + "/"):
                    raise error("xmlscope")
                paths.append(current)
                continue
            if record.get("type") not in ("multichoice", "matching"):
                raise error("xmltype")
            fractions = [float(a.get("fraction")) for a in record.findall("answer")]
            if fractions.count(100.0) != 1:
                raise error("xmlquestion")
            questions.append((current, record.findtext("name/text")))
        if not questions:
            raise error("xmlquestion")
        categories = []
        for path in paths:
            match = next((c for c in self.bank_categories(contextid) if self.path(c["categoryid"]) == path), None)
            categories.append({"path": path, "categoryid": match["categoryid"] if match else 0,
                               "action": "reuse" if match else "create"})
        warnings = []
        sha256 = hashlib.sha256(content).hexdigest()
        repeats = [i for i in self.imports if i["sha256"] == sha256 and i["categoryid"] == categoryid]
        if repeats and not self.ignore_repeats:
            ids = [qid for i in repeats for qid in i["questionids"]]
            warnings.append({"code": "exact_repeat", "index": 0, "matchcount": len(repeats), "questionids": ids[:20]})
        seen = set()
        for index, (path, name) in enumerate(questions, 1):
            if (path, name) in seen:
                warnings.append({"code": "duplicate_in_file", "index": index, "matchcount": 1, "questionids": []})
            seen.add((path, name))
            existing = [q["questionid"] for q in self.questions if q["name"] == name and self.path(q["categoryid"]) == path]
            if existing:
                warnings.append({"code": "possible_existing_duplicate", "index": index,
                                 "matchcount": len(existing), "questionids": existing})
        if self.mutate_on_preview:
            self.questions.append(dict(self.questions[0], questionid=999, entryid=999))
        token = hashlib.sha256(f"token{len(self.previews)}".encode()).hexdigest()
        warningshash = hashlib.sha256(json.dumps(warnings).encode()).hexdigest()
        self.previews[token] = {"warningshash": warningshash, "state": self.state(), "used": False,
                                "exact": bool(repeats), "sha256": sha256, "categoryid": categoryid,
                                "categories": categories, "questions": questions}
        return {"contextid": contextid, "categoryid": categoryid, "sha256": sha256,
                "questioncount": len(questions), "categories": categories, "tags": [], "warnings": warnings,
                "warningshash": warningshash, "previewtoken": token, "expires": 2000000000, "canapply": True,
                "requiresduplicateoverride": bool(repeats) and not self.ignore_repeats}

    def state(self):
        return len(self.categories), len(self.questions)

    def import_xml(self, previewtoken, warningshash, allowduplicate=False):
        if not qbank.is_hex64(previewtoken) or not qbank.is_hex64(warningshash):
            raise error("invalidparameter")
        preview = self.previews.get(previewtoken)
        if preview is None:
            raise error("previewinvalid")
        if preview["used"]:
            raise error("previewused")
        if preview["warningshash"] != warningshash:
            raise error("acknowledgement")
        if preview["state"] != self.state():
            raise error("previewstale")
        if preview["exact"] and not allowduplicate:
            raise error("duplicateimport")
        categories = []
        for item in preview["categories"]:
            item = dict(item)
            if item["action"] == "create":
                parent, name = item["path"].rsplit("/", 1)
                parentid = next(c["categoryid"] for c in self.bank_categories(CTX) if self.path(c["categoryid"]) == parent)
                item["categoryid"] = 1000 + len(self.categories)
                self.categories.append({"categoryid": item["categoryid"], "parentid": parentid, "name": name})
            categories.append(item)
        byid = {c["path"]: c["categoryid"] for c in categories}
        created = []
        for index, (path, name) in enumerate(preview["questions"], 1):
            qid = 2000 + len(self.questions)
            catid = byid.get(path, preview["categoryid"])
            self.questions.append({"questionid": qid, "entryid": qid, "categoryid": catid, "version": 1,
                                   "status": "ready", "name": name, "qtype": "multichoice", "timemodified": 1})
            created.append({"index": index, "questionid": qid, "categoryid": catid, "name": name, "qtype": "multichoice"})
        preview["used"] = True
        self.imports.append({"sha256": preview["sha256"], "categoryid": preview["categoryid"],
                             "questionids": [q["questionid"] for q in created]})
        return {"importid": len(self.imports), "contextid": CTX, "categoryid": preview["categoryid"],
                "sha256": preview["sha256"], "questioncount": len(created), "duplicateoverride": preview["exact"],
                "categories": categories, "questions": created}


class Args:
    contextid = CTX
    categoryid = None
    other_contextid = 60
    export_count = 5
    xml = str(Path(__file__).resolve().parent / "missing-sample.xml")
    import_categoryid = None
    allow_import = False
    read_only = False


class ImportArgs(Args):
    import_categoryid = 2
    allow_import = True


def run(fake, args=Args):
    with contextlib.redirect_stdout(io.StringIO()) as output:
        runner = qbank.run_tests(fake, args)
    return runner, output.getvalue()


class LiveQbankScriptTest(unittest.TestCase):
    def test_compliant_plugin_passes_every_check(self):
        runner, output = run(FakeMoodle())
        statuses = [status for status, _ in runner.results]
        self.assertNotIn("FAIL", statuses, output)
        self.assertEqual(statuses.count("SKIP"), 2, output)
        self.assertIn("[PASS] export_xml: hidden question rejected", output)
        self.assertIn("[PASS] preview_xml: path outside selected category subtree rejected", output)
        self.assertIn("[PASS] preview_xml: export round trip warns about re-import", output)
        self.assertIn("[PASS] import_xml: wrong warnings hash rejected", output)
        self.assertIn("[SKIP] import_xml: real import tests", output)

    def test_opt_in_import_exercises_every_gate(self):
        fake = FakeMoodle()
        runner, output = run(fake, ImportArgs)
        self.assertNotIn("FAIL", [status for status, _ in runner.results], output)
        for name in ("used token rejected", "exact repeat without override rejected", "no other category changed"):
            self.assertIn(f"[PASS] import_xml: {name}", output)
        self.assertEqual(len(fake.imports), 2)

    def test_read_only_uses_only_read_functions_and_never_uploads(self):
        class ReadArgs(Args):
            read_only = True

        fake = FakeMoodle()
        with mock.patch.object(fake, "upload_draft") as upload, \
                mock.patch.object(fake, "call", wraps=fake.call) as call:
            runner, output = run(fake, ReadArgs)
        self.assertNotIn("FAIL", [status for status, _ in runner.results], output)
        upload.assert_not_called()
        self.assertEqual({request.args[0] for request in call.call_args_list},
                         {"list_categories", "list_questions", "get_question", "export_xml"})
        self.assertIn(("PASS", "read-only: bank counts unchanged"), runner.results)

    def test_read_only_rejects_import_options_before_connecting(self):
        with mock.patch.object(qbank, "MoodleClient") as client, \
                contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            qbank.main(["--url", "https://moodle.example", "--contextid", "5", "--read-only",
                        "--import-categoryid", "2", "--allow-import"])
        client.assert_not_called()

    def test_read_only_detects_bank_changes_during_checks(self):
        class ReadArgs(Args):
            read_only = True

        class Changing(FakeMoodle):
            def call(self, function, **params):
                result = super().call(function, **params)
                if function == "export_xml":
                    self.questions.append(dict(self.questions[0], questionid=999, entryid=999))
                return result

        runner, output = run(Changing(), ReadArgs)
        self.assertIn(("FAIL", "read-only: bank counts unchanged"), runner.results, output)

    def test_import_that_allows_silent_repeat_fails(self):
        runner, output = run(FakeMoodle(ignore_repeats=True), ImportArgs)
        self.assertIn(("FAIL", "preview_xml: re-preview flags exact_repeat and requires override"), runner.results, output)

    def test_import_flags_must_be_given_together(self):
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            qbank.main(["--url", "https://moodle.example", "--contextid", "5", "--import-categoryid", "2"])

    def test_bank_change_during_preview_fails(self):
        runner, output = run(FakeMoodle(mutate_on_preview=True))
        self.assertIn(("FAIL", "bank unchanged after all previews and negative tests"), runner.results, output)

    def test_missing_or_malformed_preview_tags_fail(self):
        valid = {"id": 1, "collectionid": 1, "name": "Existing", "isstandard": False}
        for tags in (None, [valid, valid], [dict(valid, isstandard="0")]):
            with self.subTest(tags=tags):
                class BadTags(FakeMoodle):
                    def preview(self, *args):
                        result = super().preview(*args)
                        if tags is None:
                            del result["tags"]
                        else:
                            result["tags"] = tags
                        return result

                runner, output = run(BadTags())
                self.assertIn(("FAIL", "preview_xml: export round trip warns about re-import"), runner.results, output)

    def test_missing_tags_are_valid_preview_plans(self):
        class MissingTags(FakeMoodle):
            def preview(self, *args):
                result = super().preview(*args)
                result["tags"] = [
                    {"id": 0, "collectionid": 1, "name": "New one", "isstandard": False, "action": "create"},
                    {"id": 0, "collectionid": 1, "name": "New two", "isstandard": False, "action": "create"},
                ]
                return result

        runner, output = run(MissingTags())
        self.assertIn(("PASS", "preview_xml: export round trip warns about re-import"), runner.results, output)

    def test_unknown_category_argument_stops(self):
        class Bad(Args):
            categoryid = 3

        runner, _ = run(FakeMoodle(), Bad)
        self.assertEqual(runner.results[-1], ("FAIL", "--categoryid belongs to --contextid"))

    def test_client_allows_loopback_http_but_rejects_remote_http(self):
        for url in ("http://localhost", "http://127.0.0.1:8080", "http://[::1]:8080"):
            with self.subTest(url=url):
                client = qbank.MoodleClient(url, "token")
                self.assertEqual(client.base, url)
        for url in ("http://moodle.example", "http://localhost.example", "http://192.168.1.2"):
            with self.subTest(url=url), self.assertRaises(qbank.ApiError):
                qbank.MoodleClient(url, "token")
        with self.assertRaises(qbank.ApiError):
            qbank.MoodleClient("ftp://localhost", "token")
        with self.assertRaises(qbank.ApiError):
            qbank.MoodleClient("https://moodle.example", " ")

    def test_client_encodes_arrays_redacts_errors_and_uploads_multipart(self):
        client = qbank.MoodleClient("https://moodle.example/", "secret123")
        requests = []

        def respond(body):
            def opener(request, timeout):
                requests.append(request)
                response = mock.MagicMock()
                response.__enter__.return_value.read.return_value = json.dumps(body).encode()
                return response
            return opener

        client.opener = mock.Mock(open=respond({"xml": ""}))
        client.call("export_xml", contextid=5, questionids=[7, 8], includecontent=True)
        fields = parse_qs(requests[-1].data.decode())
        self.assertEqual(requests[-1].full_url, "https://moodle.example/webservice/rest/server.php")
        self.assertEqual(fields["questionids[0]"], ["7"])
        self.assertEqual(fields["questionids[1]"], ["8"])
        self.assertEqual(fields["includecontent"], ["1"])
        self.assertEqual(fields["wsfunction"], ["local_bosonanceqbank_export_xml"])

        client.opener = mock.Mock(open=respond({"exception": "x", "errorcode": "invalidtoken", "message": "bad secret123"}))
        with self.assertRaises(qbank.ApiError) as caught:
            client.call("list_categories", contextid=5)
        self.assertEqual(caught.exception.errorcode, "invalidtoken")
        self.assertNotIn("secret123", str(caught.exception))

        client.opener = mock.Mock(open=respond([{"itemid": 42, "filename": "a.xml"}]))
        self.assertEqual(client.upload_draft("a.xml", b"<quiz/>"), 42)
        upload = requests[-1]
        self.assertEqual(upload.full_url, "https://moodle.example/webservice/upload.php")
        self.assertIn(b'name="filearea"\r\n\r\ndraft', upload.data)
        self.assertIn(b'filename="a.xml"\r\nContent-Type: text/xml\r\n\r\n<quiz/>', upload.data)

        client.opener = mock.Mock(open=respond([{"filename": "a.xml", "errortype": "filenameexist", "error": "exists"}]))
        with self.assertRaisesRegex(qbank.ApiError, "filenameexist"):
            client.upload_draft("a.xml", b"<quiz/>")


if __name__ == "__main__":
    unittest.main()
