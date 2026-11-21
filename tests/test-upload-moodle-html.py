import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
from urllib.parse import parse_qs


SCRIPT = Path(__file__).resolve().parent.parent / "scripts" / "upload-moodle-html.py"
SPEC = importlib.util.spec_from_file_location("upload_moodle_html", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
uploader = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(uploader)


class FakeClient:
    def __init__(self):
        self.calls = []
        self.course_shortname = "course_old_name"
        self.course_warnings = []

    def call(self, function, parameters):
        self.calls.append((function, parameters))
        if function == "core_course_get_courses":
            return [{"id": 42, "shortname": self.course_shortname, "summary": "<p>old course</p>"}]
        if function == "core_course_get_categories":
            return [{"id": 7, "name": "Sample category", "description": "<p>old category</p>"}]
        if function == "core_course_update_courses":
            return {"warnings": self.course_warnings}
        if function == "core_course_update_categories":
            return None
        raise AssertionError(f"Unexpected function {function}")


class UploadMoodleHtmlTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        base = self.root / uploader.CATEGORIES / "sample"
        self.category = base / "description.html"
        self.course = base / "courses" / "course-name" / "description.html"
        self.general = self.course.with_name("general.html")
        for path in (self.category, self.course, self.general):
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<p>new HTML &amp; text</p>\n", encoding="utf-8")
        self.manifest = self.root / "mapping.json"
        self.entries = [
            {"kind": "category", "id": 7, "expected": "Sample category",
             "file": str(self.category.relative_to(self.root))},
            {"kind": "course", "id": 42, "expected": "course_old_name",
             "file": str(self.course.relative_to(self.root))},
        ]

    def load(self, entries):
        self.manifest.write_text(json.dumps({"items": entries}), encoding="utf-8")
        return uploader.load_manifest(self.manifest, self.root)

    def test_explicit_mappings_use_exact_moodle_api_fields(self):
        items = self.load(self.entries)
        client = FakeClient()
        with contextlib.redirect_stdout(io.StringIO()):
            uploader.preflight(items, client)
            uploader.apply(items, client)
        self.assertEqual(
            [call[0] for call in client.calls],
            ["core_course_get_categories", "core_course_get_courses",
             "core_course_update_categories", "core_course_update_courses"],
        )
        self.assertEqual(client.calls[2][1], {
            "categories[0][id]": 7,
            "categories[0][description]": "<p>new HTML &amp; text</p>\n",
            "categories[0][descriptionformat]": 1,
        })
        self.assertEqual(client.calls[3][1], {
            "courses[0][id]": 42,
            "courses[0][summary]": "<p>new HTML &amp; text</p>\n",
            "courses[0][summaryformat]": 1,
        })

    def test_wrong_identity_stops_before_any_updates(self):
        items = self.load(self.entries)
        client = FakeClient()
        client.course_shortname = "different_course"
        with mock.patch.object(uploader, "load_manifest", return_value=items), \
                mock.patch.object(uploader, "MoodleClient", return_value=client), \
                mock.patch.dict(os.environ, {"MOODLE_TOKEN": "secret"}, clear=True), \
                contextlib.redirect_stdout(io.StringIO()), \
                self.assertRaisesRegex(uploader.UploadError, "shortname"):
            uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test",
                           "--apply", "--backup-confirmed"])
        self.assertFalse(any("_update_" in name for name, _ in client.calls))

    def test_unsupported_sections_duplicates_and_paths_are_rejected(self):
        section = dict(self.entries[1], file=str(self.general.relative_to(self.root)))
        duplicate = [self.entries[0], dict(self.entries[0])]
        outside = dict(self.entries[0], file="../outside/description.html")
        wrong_kind = dict(self.entries[1], kind="category")
        for entries in ([section], duplicate, [outside], [wrong_kind]):
            with self.subTest(entries=entries), self.assertRaises(uploader.UploadError):
                self.load(entries)

    def test_dry_run_and_backup_confirmation(self):
        items = self.load(self.entries)
        client = FakeClient()
        with mock.patch.object(uploader, "ROOT", self.root), \
                mock.patch.object(uploader, "load_manifest", return_value=items), \
                mock.patch.object(uploader, "MoodleClient", return_value=client), \
                mock.patch.dict(os.environ, {"MOODLE_TOKEN": "secret"}, clear=True), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test"])
        self.assertIn("Dry run", output.getvalue())
        self.assertEqual(len(client.calls), 2)
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test", "--apply"])

    def test_selecting_targets_limits_preflight_and_updates(self):
        items = self.load(self.entries)
        for option, target_id, kind in (("--category-ids", "7", "categories"),
                                        ("--course-ids", "42", "courses")):
            with self.subTest(option=option):
                client = FakeClient()
                with mock.patch.object(uploader, "load_manifest", return_value=items), \
                        mock.patch.object(uploader, "MoodleClient", return_value=client), \
                        contextlib.redirect_stdout(io.StringIO()):
                    uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test",
                                   option, target_id, "--apply", "--backup-confirmed"])
                self.assertEqual([name for name, _ in client.calls],
                                 [f"core_course_get_{kind}", f"core_course_update_{kind}"])

    def test_selection_distinguishes_overlapping_ids_and_accepts_lists(self):
        items = self.load(self.entries)
        category = items[0]
        category_42 = uploader.Item("category", 42, category.expected, category.file, category.html)
        items.append(category_42)
        self.assertEqual(uploader.select_items(items, [42], []), [category_42])
        self.assertEqual(uploader.select_items(items, [], [42]), [items[1]])
        self.assertEqual(uploader.select_items(items, [7, 42], [42]), items)

    def test_missing_or_invalid_selected_ids_stop_before_api_calls(self):
        items = self.load(self.entries)
        for args, message in ((["--category-ids", "7", "999"], "category 999"),
                              (["--course-ids", "7"], "course 7"),
                              (["--course-ids", "0"], "positive integers")):
            with self.subTest(args=args), \
                    mock.patch.object(uploader, "load_manifest", return_value=items), \
                    mock.patch.object(uploader, "MoodleClient") as client, \
                    self.assertRaisesRegex(uploader.UploadError, message):
                uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test",
                               "--apply", "--backup-confirmed"] + args)
            client.assert_not_called()

    def test_combining_and_repeating_selection_flags(self):
        items = self.load(self.entries)
        client = FakeClient()
        with mock.patch.object(uploader, "load_manifest", return_value=items), \
                mock.patch.object(uploader, "MoodleClient", return_value=client), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            uploader.main(["--manifest", str(self.manifest), "--url", "https://example.test",
                           "--category-ids", "7", "--category-ids", "7", "--course-ids", "42"])
        self.assertIn("Dry run: 2 target(s)", output.getvalue())
        self.assertEqual([name for name, _ in client.calls],
                         ["core_course_get_categories", "core_course_get_courses"])

    def test_warning_stops_batch_and_reports_partial_update(self):
        items = self.load(self.entries)
        client = FakeClient()
        client.course_warnings = [{"message": "Permission denied"}]
        with contextlib.redirect_stdout(io.StringIO()), \
                self.assertRaisesRegex(uploader.UploadError, r"1 earlier update\(s\) completed"):
            uploader.apply(items, client)

    def test_rest_posts_token_and_nested_fields_without_redirects(self):
        client = uploader.MoodleClient("https://example.test/moodle", "secret")
        client.opener.open = mock.Mock(return_value=io.BytesIO(b'{"warnings": []}'))
        result = client.call("core_course_update_courses", {"courses[0][id]": 42})
        request = client.opener.open.call_args.args[0]
        self.assertEqual(result, {"warnings": []})
        self.assertEqual(request.full_url, "https://example.test/moodle/webservice/rest/server.php")
        self.assertNotIn("secret", request.full_url)
        fields = parse_qs(request.data.decode("utf-8"))
        self.assertEqual(fields["wstoken"], ["secret"])
        self.assertEqual(fields["courses[0][id]"], ["42"])
        with self.assertRaises(uploader.UploadError):
            uploader.MoodleClient("http://example.test", "secret")

    def test_api_errors_are_reported_without_exposing_token(self):
        client = uploader.MoodleClient("https://example.test", "secret")
        client.opener.open = mock.Mock(return_value=io.BytesIO(
            b'{"exception":"error","errorcode":"forbidden","message":"secret not allowed"}'
        ))
        with self.assertRaises(uploader.UploadError) as raised:
            client.call("core_course_update_courses", {})
        self.assertIn("forbidden", str(raised.exception))
        self.assertNotIn("secret", str(raised.exception))

    def test_redirect_is_refused(self):
        handler = uploader.NoRedirect()
        request = uploader.Request("https://example.test/webservice/rest/server.php")
        with self.assertRaises(uploader.HTTPError):
            handler.redirect_request(request, None, 302, "Found", {}, "https://other.test/")
