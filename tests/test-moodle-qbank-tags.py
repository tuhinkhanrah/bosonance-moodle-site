import contextlib
import importlib.util
import io
from pathlib import Path
from threading import Lock
from types import SimpleNamespace
import unittest
from unittest import mock
from urllib.parse import parse_qs


path = Path(__file__).resolve().parent.parent / "scripts" / "test-moodle-qbank-tags.py"
spec = importlib.util.spec_from_file_location("qbank_tags", path)
tags = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tags)


class FakeClient:
    def __init__(self, initial=()):
        self.tagids = list(initial)
        self.previews = {}
        self.writes = 0
        self.failure = None
        self.base, self.token, self.timeout = "https://example.test", "secret-token", 60
        self.lock = Lock()

    @staticmethod
    def tag_info(ids):
        return [{"id": tagid, "name": f"tag {tagid}"} for tagid in ids]

    def call(self, function, **params):
        with self.lock:
            return self._call(function, **params)

    def _call(self, function, **params):
        if function == "list_categories":
            return {"total": 1, "items": [{"categoryid": 3, "parentid": 1, "name": "Bank",
                    "entrycount": 1 + int(self.failure == "unrelated_change" and self.writes > 0)}]}
        if function == "get_question":
            return {"question": {"questionid": 7, "entryid": 8, "categoryid": 3, "version": 1},
                    "entryinuse": False, "versions": [{"questionid": 7, "version": 1}]}
        if function == "preview_tags":
            target = [] if params.get("clear") else params["tagids"]
            if target == self.tagids:
                raise tags.qbank.ApiError("unchanged", "invalidparameter")
            token = f"{len(self.previews) + 1:064x}"
            result = {"contextid": 16, "categoryid": 3, "entryid": 8, "questionid": 7, "version": 1,
                      "oldtags": self.tag_info(self.tagids), "newtags": self.tag_info(target),
                      "previewtoken": token}
            self.previews[token] = (result.copy(), False)
            return result
        if function == "change_tags":
            self.assert_exclusive(params)
            record, used = self.previews[params["previewtoken"]]
            if used:
                raise tags.qbank.ApiError("used", "tagspreviewused")
            if record["oldtags"] != self.tag_info(self.tagids):
                if self.failure == "stale_succeeds":
                    self.tagids = [tag["id"] for tag in record["newtags"]]
                    return {}
                raise tags.qbank.ApiError("stale", "tagsstale")
            self.previews[params["previewtoken"]] = (record, True)
            self.tagids = [tag["id"] for tag in record["newtags"]]
            self.writes += 1
            if self.failure == "lost_response":
                raise tags.qbank.ApiError("network request failed")
            return dict(record, tagsid=self.writes)
        raise AssertionError(function)

    @staticmethod
    def assert_exclusive(params):
        if params["exclusive"] is not True:
            raise AssertionError("Exclusive access was not confirmed")


ARGS = SimpleNamespace(contextid=16, categoryid=3, questionid=7, tagids=[11, 12], concurrent=False)


class TagsTest(unittest.TestCase):
    def test_round_trip_and_rejections(self):
        client = FakeClient()
        output = io.StringIO()
        with mock.patch("builtins.input", side_effect=["TAGS", "TAGS"]), contextlib.redirect_stdout(output):
            tags.run(client, ARGS)
        self.assertEqual((client.tagids, client.writes), ([], 2))
        self.assertIn("tagspreviewused", output.getvalue())
        self.assertIn("tagsstale", output.getvalue())
        self.assertNotIn("0" * 63 + "1", output.getvalue())

    def test_nonempty_question_and_cancellation_do_not_write(self):
        for client, confirmation in ((FakeClient([11]), "TAGS"), (FakeClient(), "no")):
            with self.subTest(initial=client.tagids), mock.patch("builtins.input", return_value=confirmation):
                with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(tags.move.TestFailure):
                    tags.run(client, ARGS)
                self.assertEqual(client.writes, 0)

    def test_lost_response_stops_without_retry_or_clear(self):
        client = FakeClient()
        client.failure = "lost_response"
        with mock.patch("builtins.input", return_value="TAGS"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(tags.qbank.ApiError):
                tags.run(client, ARGS)
        self.assertEqual((client.tagids, client.writes), ([11, 12], 1))

    def test_unexpected_stale_success_stops_without_clear(self):
        client = FakeClient()
        client.failure = "stale_succeeds"
        with mock.patch("builtins.input", return_value="TAGS"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(tags.move.TestFailure):
                tags.run(client, ARGS)
        self.assertEqual((client.tagids, client.writes), ([11, 12], 1))

    def test_clear_uses_explicit_flag_in_rest_form(self):
        client = tags.qbank.MoodleClient("https://example.test", "token")
        with mock.patch.object(client, "_send", return_value={}) as send:
            client.call("preview_tags", contextid=16, questionid=7, expectedversion=1, clear=True)
        fields = parse_qs(send.call_args.args[0].data.decode())
        self.assertEqual(fields["clear"], ["1"])
        self.assertNotIn("tagids", fields)

    def test_missing_token_reports_cause_without_connecting(self):
        argv = ["--url", "https://example.test", "--contextid", "16", "--categoryid", "3",
                "--questionid", "7", "--tagids", "11", "--allow-tag-change"]
        output = io.StringIO()
        with mock.patch.dict("os.environ", {}, clear=True), contextlib.redirect_stderr(output):
            self.assertEqual(tags.main(argv), 1)
        self.assertIn("Set the MOODLE_TOKEN environment variable", output.getvalue())

    def test_transport_error_reports_cause_without_token(self):
        argv = ["--url", "https://example.test", "--contextid", "16", "--categoryid", "3",
                "--questionid", "7", "--tagids", "11", "--allow-tag-change"]
        output = io.StringIO()
        with mock.patch.dict("os.environ", {"MOODLE_TOKEN": "secret-token"}), \
                mock.patch.object(tags, "run", side_effect=tags.qbank.ApiError("get_question: network request failed")), \
                contextlib.redirect_stderr(output):
            self.assertEqual(tags.main(argv), 1)
        self.assertIn("get_question: network request failed", output.getvalue())
        self.assertNotIn("secret-token", output.getvalue())

    def test_api_error_code_does_not_hide_explanation(self):
        argv = ["--url", "https://example.test", "--contextid", "16", "--categoryid", "3",
                "--questionid", "7", "--tagids", "11", "--allow-tag-change"]
        error = tags.qbank.ApiError(
            "preview_tags: invalidparameter: The selected tag assignments are already in the requested order.",
            "invalidparameter",
        )
        output = io.StringIO()
        with mock.patch.dict("os.environ", {"MOODLE_TOKEN": "secret-token"}), \
                mock.patch.object(tags, "run", side_effect=error), contextlib.redirect_stderr(output):
            self.assertEqual(tags.main(argv), 1)
        self.assertIn(str(error), output.getvalue())
        self.assertNotIn("secret-token", output.getvalue())

    def test_concurrent_tag_races_finish_untagged(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
        output = io.StringIO()
        with mock.patch.object(tags.qbank, "MoodleClient",
                               side_effect=lambda *args: SimpleNamespace(call=client.call)) as constructor, \
                mock.patch("builtins.input", return_value="TAGS"), contextlib.redirect_stdout(output):
            tags.run(client, args)
        self.assertEqual(constructor.call_count, 4)
        self.assertEqual(client.tagids, [])
        self.assertIn(client.writes, (2, 3))
        self.assertEqual(output.getvalue().count("Independent concurrent requests"), 2)
        self.assertNotIn(client.token, output.getvalue())

    def test_second_competing_tag_winner_needs_no_extra_clear(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))

        def second_wins(client, function, previews, rejection):
            proposed = previews[1]
            return proposed, client.call(function, previewtoken=proposed["previewtoken"], exclusive=True)

        with mock.patch.object(tags.move, "race", side_effect=second_wins), \
                mock.patch("builtins.input", return_value="TAGS"), contextlib.redirect_stdout(io.StringIO()):
            tags.run(client, args)
        self.assertEqual((client.tagids, client.writes), ([], 2))

    def test_concurrent_cancel_or_too_few_tags_does_not_write(self):
        for ids, confirmation in (([11], "TAGS"), ([11, 12], "no")):
            client = FakeClient()
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=True, tagids=ids))
            with self.subTest(ids=ids), mock.patch.object(tags.qbank, "MoodleClient") as constructor, \
                    mock.patch("builtins.input", return_value=confirmation), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(tags.move.TestFailure):
                    tags.run(client, args)
            constructor.assert_not_called()
            self.assertEqual((client.tagids, client.writes), ([], 0))

    def test_concurrent_lost_response_or_unrelated_change_stops_without_clear(self):
        for failure in ("lost_response", "unrelated_change"):
            client = FakeClient()
            client.failure = failure
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
            with self.subTest(failure=failure), mock.patch.object(tags.qbank, "MoodleClient",
                    side_effect=lambda *args: SimpleNamespace(call=client.call)), \
                    mock.patch("builtins.input", return_value="TAGS"), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(tags.move.TestFailure):
                    tags.run(client, args)
            self.assertEqual((client.tagids, client.writes), ([11, 12], 1))


if __name__ == "__main__":
    unittest.main()
