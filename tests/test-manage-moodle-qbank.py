import copy
from contextlib import redirect_stderr, redirect_stdout
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import Mock, patch
from urllib.parse import parse_qs


spec = importlib.util.spec_from_file_location(
    "manage_qbank", Path(__file__).resolve().parents[1] / "scripts/manage-moodle-qbank.py"
)
cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cli)
URL = "https://moodle.example.com"
TOKEN = "a" * 64
XML = (
    b'<?xml version="1.0" encoding="UTF-8"?>\n<quiz>'
    b'<question type="category"><category><text>$module$/top/Bank</text></category></question>'
    b'<question type="multichoice"><name><text>CLI test</text></name>'
    b'<questiontext format="html"><text>API test question</text></questiontext><single>true</single>'
    b'<answer fraction="100"><text>Option 0</text></answer>'
    b'<answer fraction="0"><text>Option 1</text></answer></question></quiz>'
)
SHA = hashlib.sha256(XML).hexdigest()


def summary(questionid=6):
    return {"questionid": questionid, "entryid": questionid - 1, "categoryid": 3, "version": 1,
            "status": "ready", "name": "CLI test", "qtype": "multichoice", "timemodified": 1}


def question_result():
    return {"question": summary(), "inuse": False, "entryinuse": False, "versiontotal": 1,
            "versions": [{"questionid": 6, "version": 1, "status": "ready"}]}


def previews():
    common = {"contextid": 16, "previewtoken": TOKEN, "expires": int(time.time()) + 1800}
    question = {"questionid": 6, "entryid": 5, "version": 1}
    return {
        "preview_xml": dict(common, categoryid=3, sha256=SHA, questioncount=1,
                            warningshash="b" * 64, canapply=True, requiresduplicateoverride=False,
                            categories=[{"path": "$module$/top/Bank", "categoryid": 3, "action": "reuse"}],
                            tags=[], warnings=[]),
        "preview_move": dict(common, **question, sourceid=3, targetid=4,
                             versions=[{"questionid": 6, "version": 1, "status": "ready", "timemodified": 1}]),
        "preview_status": dict(common, **question, categoryid=3, oldstatus="ready", newstatus="draft"),
        "preview_tags": dict(common, **question, categoryid=3, oldtags=[],
                             newtags=[{"id": 1, "name": "one"}, {"id": 2, "name": "two"}]),
        "preview_category": dict(common, categoryid=3, parentid=3, action="create", oldname="", newname="Physics"),
        "preview_delete_question": dict(common, **question, kind="question", categoryid=3, parentid=2,
                                        questioncount=1, name="CLI test", versions=[
                                            {"questionid": 6, "version": 1, "status": "ready", "timemodified": 1}]),
        "preview_delete_category": dict(common, kind="category", categoryid=12, parentid=3, entryid=0,
                                        questionid=0, version=0, questioncount=0, versions=[], name="Disposable"),
    }


PARAMETERS = {
    "preview_xml": {"contextid": 16, "categoryid": 3, "draftitemid": 9001, "filename": "qbank.xml"},
    "preview_move": {"contextid": 16, "questionid": 6, "expectedversion": 1, "targetid": 4},
    "preview_status": {"contextid": 16, "questionid": 6, "expectedversion": 1,
                       "expectedstatus": "ready", "targetstatus": "draft"},
    "preview_tags": {"contextid": 16, "questionid": 6, "expectedversion": 1, "tagids": [1, 2]},
    "preview_category": {"contextid": 16, "categoryid": 3, "action": "create", "name": "Physics"},
    "preview_delete_question": {"contextid": 16, "questionid": 6, "expectedversion": 1},
    "preview_delete_category": {"contextid": 16, "categoryid": 12},
}


def save_preview(path, preview_function, result=None, url=URL):
    path.write_text(json.dumps({
        "format": 1, "url": url, "operation": cli.PREVIEW_APPLY[preview_function],
        "parameters": PARAMETERS[preview_function], "preview": result or previews()[preview_function],
    }), encoding="utf-8")
    path.chmod(0o600)


def applied(function, preview):
    result = {key: value for key, value in preview.items() if key not in ("previewtoken", "expires")}
    if function == "import_xml":
        result = {key: value for key, value in result.items()
                  if key in ("contextid", "categoryid", "sha256", "questioncount", "categories")}
        result.update(duplicateoverride=False, questions=[
            {"index": 1, "questionid": 7, "categoryid": 3, "name": "CLI test", "qtype": "multichoice"}
        ])
    if function == "change_category":
        result["resultcategoryid"] = 20
    if function in ("delete_question", "delete_category"):
        result.pop("name")
        result["deleted"] = True
        if function == "delete_category" and "categories" in result:
            result["categories"] = [{key: item[key] for key in ("categoryid", "parentid")}
                                    for item in result["categories"]]
        if function == "delete_category" and "questionentries" in result:
            result["questionentries"] = [{key: value for key, value in item.items() if key != "name"}
                                         for item in result["questionentries"]]
    result[cli.AUDIT_IDS[function]] = 10
    return result


class CliTest(unittest.TestCase):
    def test_cli_and_smoke_script_each_run_with_only_the_shared_utility(self):
        scripts = Path(cli.__file__).parent
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            working = root / "working"
            working.mkdir()
            for name, expected_help in (("manage-moodle-qbank.py", "list-categories"),
                                        ("test-moodle-qbank-api.py", "--read-only")):
                with self.subTest(script=name):
                    destination = root / name.removesuffix(".py")
                    destination.mkdir()
                    for filename in (name, "moodle_qbank_transport.py"):
                        shutil.copyfile(scripts / filename, destination / filename)
                    result = subprocess.run(
                        [sys.executable, "-B", str(destination / name), "--help"],
                        cwd=working, capture_output=True, text=True, timeout=10, check=False,
                    )
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertIn(expected_help, result.stdout)

    def client(self, result):
        client = Mock()
        client.base = URL
        client.call.return_value = result
        client.upload_draft.return_value = 9001
        return client

    def run_cli(self, argv, client):
        output, errors = io.StringIO(), io.StringIO()
        with patch.dict(os.environ, MOODLE_URL=URL, MOODLE_TOKEN="test-ws-secret"), \
                patch.object(cli.qbank, "MoodleClient", return_value=client), \
                redirect_stdout(output), redirect_stderr(errors):
            code = cli.main(argv)
        return code, output.getvalue(), errors.getvalue()

    def test_all_current_operations_route_exact_parameters(self):
        all_previews = previews()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "input.xml"
            source.write_bytes(XML)
            cases = [
                ("list_categories", ["--contextid", "16"], {"contextid": 16, "offset": 0, "limit": 50},
                 {"total": 1, "items": [{"categoryid": 3, "parentid": 2, "entrycount": 1, "name": "Bank"}]}),
                ("list_questions", ["--contextid", "16", "--categoryid", "3"],
                 {"contextid": 16, "categoryid": 3, "offset": 0, "limit": 50, "status": "ready",
                  "qtype": "", "search": "", "tag": ""}, {"total": 1, "items": [summary()]}),
                ("get_question", ["--contextid", "16", "--questionid", "6"],
                 {"contextid": 16, "questionid": 6, "offset": 0, "limit": 50, "includecontent": False},
                 question_result()),
                ("export_xml", ["--contextid", "16", "--questionids", "6", "--output", str(root / "export.xml")],
                 {"contextid": 16, "questionids": [6]}, {"xml": XML.decode(), "sha256": SHA, "questioncount": 1}),
            ]
            for function, parameters in PARAMETERS.items():
                argv = []
                for key, value in parameters.items():
                    if key in ("draftitemid", "filename"):
                        continue
                    argv.append("--" + key)
                    if isinstance(value, list):
                        argv.extend(str(item) for item in value)
                    else:
                        argv.append(str(value))
                if function == "preview_xml":
                    argv += ["--file", str(source)]
                argv += ["--output", str(root / f"{function}.json")]
                cases.append((function, argv, parameters, all_previews[function]))
                apply = cli.PREVIEW_APPLY[function]
                saved = root / f"{apply}-input.json"
                save_preview(saved, function)
                apply_parameters = {"previewtoken": TOKEN}
                if apply == "import_xml":
                    apply_parameters.update(warningshash="b" * 64, allowduplicate=False)
                else:
                    apply_parameters["exclusive"] = True
                gates = ["--confirm-delete"] if apply in ("delete_question", "delete_category") else []
                cases.append((apply, ["--preview-file", str(saved), "--confirm", "--backup-confirmed", "--exclusive", *gates],
                              apply_parameters, applied(apply, all_previews[function])))
            for function, argv, parameters, result in cases:
                with self.subTest(function=function):
                    client = self.client(copy.deepcopy(result))
                    code, output, errors = self.run_cli([function.replace("_", "-"), *argv], client)
                    self.assertEqual(code, 0, errors)
                    self.assertNotIn(TOKEN, output + errors)
                    self.assertNotIn("test-ws-secret", output + errors)
                    if function == "preview_xml":
                        filename, uploaded = client.upload_draft.call_args.args
                        self.assertEqual(uploaded, XML)
                        client.call.assert_called_once_with(function, **dict(parameters, filename=filename))
                    else:
                        client.upload_draft.assert_not_called()
                        client.call.assert_called_once_with(function, **parameters)
                    if function == "export_xml":
                        self.assertEqual((root / "export.xml").read_bytes(), XML)

    def test_preview_files_are_private_and_keep_secret_out_of_console(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "preview.json"
            client = self.client(previews()["preview_move"])
            code, output, errors = self.run_cli([
                "preview-move", "--contextid", "16", "--questionid", "6", "--expectedversion", "1",
                "--targetid", "4", "--output", str(path),
            ], client)
            self.assertEqual(code, 0, errors)
            self.assertNotIn(TOKEN, output + errors)
            document = json.loads(path.read_text())
            self.assertEqual(document["preview"]["previewtoken"], TOKEN)
            self.assertEqual(document["operation"], "move_question")
            self.assertEqual(document["url"], URL)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

    def test_all_apply_gates_are_required_before_network(self):
        client = self.client({})
        for gate in ("--confirm", "--backup-confirmed", "--exclusive"):
            with self.subTest(gate=gate), self.assertRaises(SystemExit) as error:
                self.run_cli(["move-question", "--preview-file", "absent.json",
                              *[flag for flag in ("--confirm", "--backup-confirmed", "--exclusive") if flag != gate]], client)
            self.assertEqual(error.exception.code, 2)
        client.call.assert_not_called()

    def test_xml_preview_saves_multiple_missing_tag_plans(self):
        result = previews()["preview_xml"]
        result["tags"] = [
            {"id": 0, "name": "subject:physics", "isstandard": False, "collectionid": 1, "action": "create"},
            {"id": 0, "name": "lang:en", "isstandard": False, "collectionid": 1, "action": "create"},
            {"id": 4, "name": "Existing", "isstandard": True, "collectionid": 1, "action": "reuse"},
        ]
        with tempfile.TemporaryDirectory() as directory:
            source, output = Path(directory) / "input.xml", Path(directory) / "preview.json"
            source.write_bytes(XML)
            code, console, errors = self.run_cli([
                "preview-xml", "--contextid", "16", "--categoryid", "3", "--file", str(source),
                "--output", str(output),
            ], self.client(result))
            self.assertEqual(code, 0, errors)
            self.assertEqual(json.loads(output.read_text())["preview"]["tags"], result["tags"])
            self.assertIn('"action": "create"', console)
            self.assertNotIn(TOKEN, console)

    def test_xml_preview_rejects_inconsistent_creation_plans(self):
        preview = previews()["preview_xml"]
        create = {"id": 0, "name": "New", "isstandard": False, "collectionid": 1, "action": "create"}
        for tags in ([dict(create, id=2)], [dict(create, action="reuse")],
                     [dict(create, isstandard=True)], [create, dict(create, name="NEW")]):
            with self.subTest(tags=tags), self.assertRaises(cli.CliError):
                cli.validate_preview("preview_xml", dict(preview, tags=tags), PARAMETERS["preview_xml"])

    def test_deletion_requires_extra_acknowledgement_before_network(self):
        client = self.client({})
        for command in ("delete-question", "delete-category"):
            with self.subTest(command=command), self.assertRaises(SystemExit) as error:
                self.run_cli([command, "--preview-file", "absent.json",
                              "--confirm", "--backup-confirmed", "--exclusive"], client)
            self.assertEqual(error.exception.code, 2)
        client.call.assert_not_called()

    def test_deletion_refuses_hidden_or_unconfirmed_success(self):
        preview = previews()["preview_delete_question"]
        result = applied("delete_question", preview)
        for value in (False, 0, None, "true"):
            with self.subTest(deleted=value), self.assertRaises(cli.CliError):
                cli.validate_applied("delete_question", dict(result, deleted=value), preview)

    def test_deletion_preview_rejects_wrong_kind_and_incomplete_versions(self):
        for function in ("preview_delete_question", "preview_delete_category"):
            preview = previews()[function]
            with self.subTest(function=function), self.assertRaises(cli.CliError):
                cli.validate_preview(function, dict(preview, kind="other"), PARAMETERS[function])
        preview = previews()["preview_delete_question"]
        with self.assertRaises(cli.CliError):
            cli.validate_preview("preview_delete_question", dict(preview, questioncount=2),
                                 PARAMETERS["preview_delete_question"])

    def recursive_preview(self):
        return dict(previews()["preview_delete_category"], recursive=True, categorycount=3, categories=[
            {"categoryid": 12, "parentid": 3, "name": "Root"},
            {"categoryid": 13, "parentid": 14, "name": "Older child"},
            {"categoryid": 14, "parentid": 12, "name": "Newer parent"},
        ])

    def test_recursive_category_preview_and_apply_use_the_exact_saved_scope(self):
        preview = self.recursive_preview()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "recursive.json"
            client = self.client(preview)
            code, output, errors = self.run_cli([
                "preview-delete-category", "--contextid", "16", "--categoryid", "12",
                "--recursive", "--output", str(path),
            ], client)
            self.assertEqual(code, 0, errors)
            client.call.assert_called_once_with("preview_delete_category", contextid=16, categoryid=12, recursive=True)
            self.assertIn('"categorycount": 3', output)
            client = self.client(applied("delete_category", preview))
            code, _, errors = self.run_cli([
                "delete-category", "--preview-file", str(path), "--confirm",
                "--backup-confirmed", "--exclusive", "--confirm-delete",
            ], client)
            self.assertEqual(code, 0, errors)
            client.call.assert_called_once_with("delete_category", previewtoken=TOKEN, exclusive=True)

    def test_recursive_preview_rejects_legacy_partial_disconnected_or_cyclic_scopes(self):
        preview = self.recursive_preview()
        cases = [
            previews()["preview_delete_category"], dict(preview, recursive=False),
            {key: value for key, value in preview.items() if key != "categorycount"},
            dict(preview, categorycount=4),
            dict(preview, categories=preview["categories"] + [preview["categories"][0]], categorycount=4),
            dict(preview, categories=[preview["categories"][0],
                                     {"categoryid": 13, "parentid": 999, "name": "Outside"}], categorycount=2),
            dict(preview, categories=[preview["categories"][0],
                                     {"categoryid": 13, "parentid": 14, "name": "Cycle A"},
                                     {"categoryid": 14, "parentid": 13, "name": "Cycle B"}]),
        ]
        for result in cases:
            with self.subTest(result=result), self.assertRaises(cli.CliError):
                cli.validate_preview("preview_delete_category", result,
                                     dict(PARAMETERS["preview_delete_category"], recursive=True))
        result = applied("delete_category", preview)
        result["categories"].pop()
        with self.assertRaises(cli.CliError):
            cli.validate_applied("delete_category", result, preview)

    def test_recursive_preview_limit_counts_the_root(self):
        categories = [{"categoryid": 12, "parentid": 3, "name": "Root"}] + [
            {"categoryid": index, "parentid": 12, "name": "Child"} for index in range(100, 1099)
        ]
        preview = dict(self.recursive_preview(), categorycount=len(categories), categories=categories)
        parameters = dict(PARAMETERS["preview_delete_category"], recursive=True)
        cli.validate_preview("preview_delete_category", preview, parameters)
        preview["categories"].append({"categoryid": 1099, "parentid": 12, "name": "Extra"})
        preview["categorycount"] += 1
        with self.assertRaises(cli.CliError):
            cli.validate_preview("preview_delete_category", preview, parameters)

    def unused_questions_preview(self):
        first = {"entryid": 51, "categoryid": 12, "questionid": 61, "version": 2, "qtype": "multichoice",
                 "questioncount": 2,
                 "name": "Latest question", "versions": [
                     {"questionid": 60, "version": 1, "status": "hidden", "timemodified": 1},
                     {"questionid": 61, "version": 2, "status": "ready", "timemodified": 2},
                 ]}
        second = {"entryid": 52, "categoryid": 13, "questionid": 62, "version": 1, "qtype": "shortanswer",
                  "questioncount": 1,
                  "name": "Nested question", "versions": [
                      {"questionid": 62, "version": 1, "status": "draft", "timemodified": 3},
                  ]}
        return dict(self.recursive_preview(), deleteunusedquestions=True, entrycount=2, questioncount=3,
                    questionentries=[first, second])

    def test_explicit_unused_question_cascade_preview_apply_round_trip(self):
        preview = self.unused_questions_preview()
        parameters = dict(PARAMETERS["preview_delete_category"], deleteunusedquestions=True)
        cli.validate_preview("preview_delete_category", preview, parameters)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "cascade.json"
            client = self.client(preview)
            code, output, errors = self.run_cli([
                "preview-delete-category", "--contextid", "16", "--categoryid", "12",
                "--delete-unused-questions", "--output", str(path),
            ], client)
            self.assertEqual(code, 0, errors)
            client.call.assert_called_once_with("preview_delete_category", contextid=16, categoryid=12,
                                                deleteunusedquestions=True)
            self.assertIn('"entrycount": 2', output)
            self.assertIn('"name": "Nested question"', output)
            document = json.loads(path.read_text())
            self.assertEqual(document["parameters"], parameters)
            client = self.client(applied("delete_category", preview))
            code, _, errors = self.run_cli([
                "delete-category", "--preview-file", str(path), "--confirm",
                "--backup-confirmed", "--exclusive", "--confirm-delete",
            ], client)
            self.assertEqual(code, 0, errors)
            client.call.assert_called_once_with("delete_category", previewtoken=TOKEN, exclusive=True)

    def test_unused_question_cascade_requires_complete_bounded_consistent_scope(self):
        preview = self.unused_questions_preview()
        parameters = dict(PARAMETERS["preview_delete_category"], deleteunusedquestions=True)
        cases = [
            dict(preview, deleteunusedquestions=False),
            dict(preview, entrycount=1),
            dict(preview, questioncount=4),
            dict(preview, questionentries=preview["questionentries"][:1]),
            dict(preview, questionentries=[dict(preview["questionentries"][0], categoryid=999)]),
            dict(preview, questionentries=[preview["questionentries"][0],
                                          dict(preview["questionentries"][1], entryid=51)]),
            dict(preview, questionentries=[dict(preview["questionentries"][0], version=1)]),
        ]
        for result in cases:
            with self.subTest(result=result), self.assertRaises(cli.CliError):
                cli.validate_preview("preview_delete_category", result, parameters)
        result = applied("delete_category", preview)
        result["questionentries"][0]["questioncount"] = 1
        with self.assertRaises(cli.CliError):
            cli.validate_applied("delete_category", result, preview)

    def test_unused_question_cascade_rejects_entry_and_total_version_overflow(self):
        parameters = dict(PARAMETERS["preview_delete_category"], deleteunusedquestions=True)
        preview = self.unused_questions_preview()
        entry = preview["questionentries"][0]
        preview["questionentries"] = [dict(entry, entryid=100 + index, questionid=500 + index,
                                           categoryid=12, version=1, questioncount=1,
                                           versions=[{"questionid": 500 + index, "version": 1,
                                                      "status": "ready", "timemodified": index}])
                                      for index in range(101)]
        preview["entrycount"] = 101
        preview["questioncount"] = 101
        with self.assertRaises(cli.CliError):
            cli.validate_preview("preview_delete_category", preview, parameters)

        entries = []
        next_questionid = 1000
        for index in range(100):
            count = 11 if index == 0 else 10
            versions = []
            for version in range(1, count + 1):
                versions.append({"questionid": next_questionid, "version": version,
                                 "status": "ready", "timemodified": version})
                next_questionid += 1
            entries.append({"entryid": 200 + index, "categoryid": 12,
                            "questionid": versions[-1]["questionid"], "version": count,
                            "qtype": "multichoice", "questioncount": count, "name": "Question",
                            "versions": versions})
        preview["questionentries"] = entries
        preview["entrycount"] = 100
        preview["questioncount"] = 1001
        with self.assertRaises(cli.CliError):
            cli.validate_preview("preview_delete_category", preview, parameters)

    def test_recursive_empty_mode_rejects_question_cascade_flag_combination(self):
        client = self.client({})
        with patch.dict(os.environ, MOODLE_URL=URL, MOODLE_TOKEN="test-ws-secret"), \
                patch.object(cli.qbank, "MoodleClient", return_value=client), \
                redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()), \
                self.assertRaises(SystemExit) as error:
            cli.main([
                "preview-delete-category", "--contextid", "16", "--categoryid", "12",
                "--recursive", "--delete-unused-questions", "--output", "unused.json",
            ])
        self.assertEqual(error.exception.code, 2)
        client.call.assert_not_called()

    def test_invalid_selections_and_paging_are_rejected_before_network(self):
        client = self.client({})
        for argv in (
            ["list-categories", "--contextid", "-1"],
            ["list-categories", "--contextid", "16", "--limit", "101"],
            ["list-categories", "--contextid", "16", "--all", "--offset", "1"],
            ["export-xml", "--contextid", "16", "--questionids", "6", "6", "--output", "unused.xml"],
            ["export-xml", "--contextid", "16", "--questionids", *map(str, range(1, 102)), "--output", "unused.xml"],
            ["preview-tags", "--contextid", "16", "--questionid", "6", "--expectedversion", "1",
             "--tagids", *map(str, range(1, 52)), "--output", "unused.json"],
            ["preview-tags", "--contextid", "16", "--questionid", "6", "--expectedversion", "1",
             "--tagids", "1", "--clear", "--output", "unused.json"],
        ):
            with self.subTest(argv=argv), self.assertRaises(SystemExit):
                self.run_cli(argv, client)
        client.call.assert_not_called()
        client.upload_draft.assert_not_called()

    def test_apply_rejects_other_site_operation_expiry_and_malformed_files(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "preview.json"
            base = previews()["preview_move"]
            for problem in ("site", "operation", "expiry", "token", "json"):
                with self.subTest(problem=problem):
                    data = dict(base)
                    if problem == "expiry":
                        data["expires"] = 1
                    if problem == "token":
                        data["previewtoken"] = "bad"
                    save_preview(path, "preview_status" if problem == "operation" else "preview_move",
                                 data, "https://other.example.com" if problem == "site" else URL)
                    if problem == "json":
                        path.write_text("{", encoding="utf-8")
                    client = self.client({})
                    code, _, errors = self.run_cli(["move-question", "--preview-file", str(path),
                                                   "--confirm", "--backup-confirmed", "--exclusive"], client)
                    self.assertEqual(code, 1, errors)
                    client.call.assert_not_called()

    def test_exact_repeat_requires_explicit_override_and_acknowledges_saved_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "preview.json"
            preview = dict(previews()["preview_xml"], requiresduplicateoverride=True)
            save_preview(path, "preview_xml", preview)
            client = self.client(applied("import_xml", preview))
            argv = ["import-xml", "--preview-file", str(path), "--confirm", "--backup-confirmed", "--exclusive"]
            self.assertEqual(self.run_cli(argv, client)[0], 1)
            client.call.assert_not_called()
            code, _, errors = self.run_cli([*argv, "--allow-duplicate"], client)
            self.assertEqual(code, 0, errors)
            client.call.assert_called_once_with("import_xml", previewtoken=TOKEN,
                                                warningshash="b" * 64, allowduplicate=True)

    def test_output_collision_prevents_upload_and_apply(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "existing.json"
            path.write_bytes(b"keep")
            for argv in (
                ["preview-xml", "--contextid", "16", "--categoryid", "3", "--file", "absent.xml"],
                ["move-question", "--preview-file", "absent.json", "--confirm", "--backup-confirmed", "--exclusive"],
            ):
                client = self.client({})
                code, _, _ = self.run_cli([*argv, "--output", str(path)], client)
                self.assertEqual(code, 1)
                self.assertEqual(path.read_bytes(), b"keep")
                client.call.assert_not_called()
                client.upload_draft.assert_not_called()

    def test_clearing_tags_omits_tagids_in_actual_rest_request(self):
        with tempfile.TemporaryDirectory() as directory:
            result = dict(previews()["preview_tags"], newtags=[])
            opener = Mock()
            opener.open.return_value = io.BytesIO(json.dumps(result).encode())
            with patch.object(cli.qbank, "build_opener", return_value=opener), \
                    patch.dict(os.environ, MOODLE_URL=URL, MOODLE_TOKEN="test-ws-secret"), \
                    redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                code = cli.main(["preview-tags", "--contextid", "16", "--questionid", "6",
                                 "--expectedversion", "1", "--clear", "--output", str(Path(directory) / "preview.json")])
            self.assertEqual(code, 0)
            fields = parse_qs(opener.open.call_args.args[0].data.decode())
            self.assertEqual(fields["clear"], ["1"])
            self.assertEqual(fields["wsfunction"], ["local_bosonanceqbank_preview_tags"])
            self.assertFalse(any(key.startswith("tagids") for key in fields))

    def test_paging_filters_and_drift_detection(self):
        client = self.client({})
        client.call.side_effect = [
            {"total": 2, "items": [summary()]},
            {"total": 2, "items": [summary(7)]},
        ]
        argv = ["list-questions", "--contextid", "16", "--categoryid", "3", "--all", "--limit", "1",
                "--status", "all", "--qtype", "match", "--search", "term", "--tag", "one"]
        code, output, errors = self.run_cli(argv, client)
        self.assertEqual(code, 0, errors)
        self.assertEqual(len(json.loads(output)["items"]), 2)
        self.assertEqual(client.call.call_args.kwargs, {
            "contextid": 16, "categoryid": 3, "offset": 1, "limit": 1, "status": "all",
            "qtype": "match", "search": "term", "tag": "one",
        })
        for second in ({"total": 3, "items": [summary(7)]},
                       {"total": 2, "items": [summary()]},
                       {"total": 2, "items": []}):
            client.call.side_effect = [{"total": 2, "items": [summary()]}, second]
            self.assertEqual(self.run_cli(argv, client)[0], 1)

    def test_export_checks_hash_and_actual_xml_question_count_and_cleans_failed_output(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "export.xml"
            for result in (
                {"xml": XML.decode(), "sha256": "c" * 64, "questioncount": 1},
                {"xml": "<quiz/>", "sha256": hashlib.sha256(b"<quiz/>").hexdigest(), "questioncount": 1},
                {"xml": "<broken", "sha256": hashlib.sha256(b"<broken").hexdigest(), "questioncount": 1},
            ):
                client = self.client(result)
                code, output, _ = self.run_cli(["export-xml", "--contextid", "16",
                                               "--questionids", "6", "--output", str(path)], client)
                self.assertEqual(code, 1)
                self.assertFalse(path.exists())
                self.assertNotIn("questiontext", output)

    def test_content_is_opt_in_and_file_only(self):
        with tempfile.TemporaryDirectory() as directory:
            client = self.client(dict(question_result(), content={
                "questiontext": "private text", "questiontextformat": 1,
                "generalfeedback": "", "generalfeedbackformat": 1,
            }))
            argv = ["get-question", "--contextid", "16", "--questionid", "6"]
            with self.assertRaises(SystemExit):
                self.run_cli([*argv, "--include-content"], client)
            client.call.assert_not_called()
            self.assertEqual(self.run_cli(argv, client)[0], 1)
            path = Path(directory) / "question.json"
            code, output, errors = self.run_cli([*argv, "--include-content", "--output", str(path)], client)
            self.assertEqual(code, 0, errors)
            self.assertNotIn("private text", output + errors)
            self.assertEqual(json.loads(path.read_text())["content"]["questiontext"], "private text")

    def test_failed_apply_is_not_retried_and_redacts_both_tokens(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "preview.json"
            save_preview(path, "preview_move")
            client = self.client({})
            client.call.side_effect = cli.qbank.ApiError(f"lost response {TOKEN} test-ws-secret")
            code, output, errors = self.run_cli(["move-question", "--preview-file", str(path),
                                               "--confirm", "--backup-confirmed", "--exclusive"], client)
            self.assertEqual(code, 1)
            self.assertNotIn(TOKEN, output + errors)
            self.assertNotIn("test-ws-secret", output + errors)
            self.assertIn("may have committed", errors)
            client.call.assert_called_once()
            self.assertTrue(path.exists())

    def test_xml_upload_is_bounded_and_mismatched_preview_is_not_saved(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source, output = root / "input.xml", root / "preview.json"
            argv = ["preview-xml", "--contextid", "16", "--categoryid", "3",
                    "--file", str(source), "--output", str(output)]
            for content in (b"", b"x" * (cli.MAX_XML_BYTES + 1)):
                source.write_bytes(content)
                client = self.client(previews()["preview_xml"])
                self.assertEqual(self.run_cli(argv, client)[0], 1)
                client.upload_draft.assert_not_called()
                self.assertFalse(output.exists())
            source.write_bytes(XML)
            client = self.client(dict(previews()["preview_xml"], sha256="c" * 64))
            self.assertEqual(self.run_cli(argv, client)[0], 1)
            self.assertFalse(output.exists())


if __name__ == "__main__":
    unittest.main()
