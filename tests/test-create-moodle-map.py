import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock


SCRIPT = Path(__file__).resolve().parent.parent / "scripts" / "create-moodle-map.py"
SPEC = importlib.util.spec_from_file_location("create_moodle_map", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
mapper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mapper)


class FakeClient:
    def __init__(self):
        self.calls = []
        self.categories = [
            {"id": 7, "name": "Online Test Series", "parent": 99},
            {"id": 8, "name": "Class 12+", "parent": 7},
            {"id": 9, "name": "Class 12+", "parent": 99},
        ]
        self.courses = [
            {"id": 42, "shortname": "neet_c12_plus_ots_en", "categoryid": 8},
            {"id": 43, "shortname": "neet_c12_plus_ots_en", "categoryid": 9},
        ]

    def call(self, function, parameters):
        self.calls.append((function, parameters))
        if function == "core_course_get_categories":
            return self.categories
        if function == "core_course_get_courses":
            return self.courses
        raise AssertionError(f"Unexpected function {function}")


class CreateMoodleMapTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        base = self.root / mapper.uploader.CATEGORIES
        for folder in (
            "online-test-series",
            "online-test-series/class12-plus",
            "online-test-series/class12-plus/courses/neet-c12-plus-ots-en",
        ):
            target = base / folder / "description.html"
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("<p>Test description</p>", encoding="utf-8")
        self.client = FakeClient()
        self.overrides = {"categories": {}, "courses": {}}
        self.output = self.root / "mapping.json"

    def make_map(self):
        return mapper.make_map(self.root, self.client, self.overrides)

    def test_map_is_read_only_scoped_and_compatible_with_uploader(self):
        with mock.patch.object(mapper.uploader, "ROOT", self.root), \
                mock.patch.object(mapper.uploader, "MoodleClient", return_value=self.client), \
                mock.patch.dict(os.environ, {"MOODLE_TOKEN": "secret"}, clear=True), \
                contextlib.redirect_stdout(io.StringIO()):
            mapper.main(["--url", "https://example.test", "--output", str(self.output)])
        self.assertEqual(self.client.calls, [
            ("core_course_get_categories", {}), ("core_course_get_courses", {}),
        ])
        items = mapper.uploader.load_manifest(self.output, self.root)
        self.assertEqual([(item.kind, item.id, item.expected) for item in items], [
            ("category", 7, "Online Test Series"),
            ("category", 8, "Class 12+"),
            ("course", 42, "neet_c12_plus_ots_en"),
        ])

    def test_ambiguous_match_requires_override_and_does_not_write(self):
        self.client.courses.append({"id": 44, "shortname": "neet-c12-plus-ots-en", "categoryid": 8})
        with mock.patch.object(mapper.uploader, "ROOT", self.root), \
                mock.patch.object(mapper.uploader, "MoodleClient", return_value=self.client), \
                mock.patch.dict(os.environ, {"MOODLE_TOKEN": "secret"}, clear=True), \
                self.assertRaisesRegex(mapper.uploader.UploadError, "2 matches.*42:.*44:"):
            mapper.main(["--url", "https://example.test", "--output", str(self.output)])
        self.assertFalse(self.output.exists())
        self.overrides["courses"]["online-test-series/class12-plus/courses/neet-c12-plus-ots-en"] = 44
        self.assertEqual(self.make_map()["items"][-1]["id"], 44)

    def test_unmatched_and_unresolved_parent_are_reported(self):
        self.client.categories[0]["name"] = "Different root"
        with self.assertRaises(mapper.uploader.UploadError) as raised:
            self.make_map()
        self.assertIn("0 matches", str(raised.exception))
        self.assertIn("7: Different root", str(raised.exception))
        self.assertIn("parent online-test-series is unresolved", str(raised.exception))
        self.overrides["categories"]["online-test-series"] = 7
        self.assertEqual(self.make_map()["items"][0]["expected"], "Different root")

    def test_category_collision_needs_explicit_selection(self):
        self.client.categories.append({"id": 10, "name": "Online-Test-Series", "parent": 0})
        with self.assertRaisesRegex(mapper.uploader.UploadError, "2 matches.*7:.*10:"):
            self.make_map()
        self.overrides["categories"]["online-test-series"] = 7
        self.assertEqual(self.make_map()["items"][0]["id"], 7)

    def test_overrides_cannot_select_wrong_parent_or_unknown_ids(self):
        category = "online-test-series/class12-plus"
        course = category + "/courses/neet-c12-plus-ots-en"
        for kind, path, target in (("categories", category, 9), ("courses", course, 43),
                                   ("courses", course, 999)):
            with self.subTest(kind=kind, target=target):
                self.overrides = {"categories": {}, "courses": {}}
                self.overrides[kind][path] = target
                with self.assertRaisesRegex(mapper.uploader.UploadError, "override ID"):
                    self.make_map()
        self.overrides = {"categories": {"missing": 7}, "courses": {}}
        with self.assertRaisesRegex(mapper.uploader.UploadError, "Unknown categories override"):
            self.make_map()

    def test_invalid_api_response_or_override_is_rejected(self):
        self.client.courses[0]["id"] = True
        with self.assertRaisesRegex(mapper.uploader.UploadError, "invalid courses record"):
            self.make_map()
        path = self.root / "overrides.json"
        path.write_text(json.dumps({"courses": {"some/path": True}}), encoding="utf-8")
        with self.assertRaisesRegex(mapper.uploader.UploadError, "positive IDs"):
            mapper.load_overrides(path)
        path.write_text('{"categories":{"one":7,"one":8}}', encoding="utf-8")
        with self.assertRaisesRegex(mapper.uploader.UploadError, "Duplicate key"):
            mapper.load_overrides(path)

    def test_api_failure_does_not_create_map(self):
        self.client.call = mock.Mock(side_effect=mapper.uploader.UploadError("Access denied"))
        with mock.patch.object(mapper.uploader, "ROOT", self.root), \
                mock.patch.object(mapper.uploader, "MoodleClient", return_value=self.client), \
                self.assertRaisesRegex(mapper.uploader.UploadError, "Access denied"):
            mapper.main(["--url", "https://example.test", "--output", str(self.output)])
        self.assertFalse(self.output.exists())

    def test_existing_output_is_not_overwritten_or_queried(self):
        self.output.write_text("existing", encoding="utf-8")
        with mock.patch.object(mapper.uploader, "MoodleClient") as client, \
                self.assertRaisesRegex(mapper.uploader.UploadError, "overwrite"):
            mapper.main(["--url", "https://example.test", "--output", str(self.output)])
        client.assert_not_called()
        self.assertEqual(self.output.read_text(encoding="utf-8"), "existing")


if __name__ == "__main__":
    unittest.main()
