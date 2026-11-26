import contextlib
import importlib.util
import io
from pathlib import Path
from threading import Lock
from types import SimpleNamespace
import unittest
from unittest import mock


path = Path(__file__).resolve().parent.parent / "scripts" / "test-moodle-qbank-status.py"
spec = importlib.util.spec_from_file_location("qbank_status", path)
status = importlib.util.module_from_spec(spec)
spec.loader.exec_module(status)


class FakeClient:
    def __init__(self):
        self.status = "ready"
        self.previews = {}
        self.writes = 0
        self.stale_succeeds = False
        self.failure = None
        self.base, self.token, self.timeout = "https://example.test", "secret-token", 60
        self.lock = Lock()

    def call(self, function, **params):
        with self.lock:
            return self._call(function, **params)

    def _call(self, function, **params):
        if function == "list_categories":
            return {"total": 1, "items": [{"categoryid": 3, "parentid": 1, "name": "Bank",
                    "entrycount": 1 + int(self.failure == "unrelated_change" and self.writes > 0)}]}
        if function == "get_question":
            return {"question": {"questionid": 7, "entryid": 8, "categoryid": 3,
                                 "version": 1, "status": self.status},
                    "entryinuse": False, "versions": [{"questionid": 7, "version": 1, "status": self.status}]}
        if function == "preview_status":
            token = f"{len(self.previews) + 1:064x}"
            result = {"contextid": 16, "categoryid": 3, "entryid": 8, "questionid": 7, "version": 1,
                      "oldstatus": params["expectedstatus"], "newstatus": params["targetstatus"],
                      "previewtoken": token}
            self.previews[token] = (result.copy(), False)
            return result
        if function == "change_status":
            record, used = self.previews[params["previewtoken"]]
            if used:
                raise status.qbank.ApiError("used", "statuspreviewused")
            if record["oldstatus"] != self.status:
                if self.stale_succeeds:
                    self.status = record["newstatus"]
                    return {}
                raise status.qbank.ApiError("stale", "statusstale")
            self.status = record["newstatus"]
            self.previews[params["previewtoken"]] = (record, True)
            self.writes += 1
            if self.failure == "lost_response":
                raise status.qbank.ApiError("network request failed")
            return dict(record, statusid=self.writes)
        raise AssertionError(function)


ARGS = SimpleNamespace(contextid=16, categoryid=3, questionid=7, concurrent=False)


class StatusTest(unittest.TestCase):
    def test_round_trip_and_rejections(self):
        client = FakeClient()
        output = io.StringIO()
        with mock.patch("builtins.input", side_effect=["MOVE"] * 3), contextlib.redirect_stdout(output):
            status.run(client, ARGS)
        self.assertEqual((client.status, client.writes), ("ready", 3))
        self.assertIn("statuspreviewused", output.getvalue())
        self.assertIn("statusstale", output.getvalue())
        self.assertNotIn("0" * 63 + "1", output.getvalue())

    def test_cancel_does_not_change_status(self):
        for concurrent in (False, True):
            client = FakeClient()
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=concurrent))
            with self.subTest(concurrent=concurrent), mock.patch("builtins.input", return_value="no"), \
                    mock.patch.object(status.qbank, "MoodleClient") as constructor, contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(status.move.TestFailure):
                    status.run(client, args)
            constructor.assert_not_called()
            self.assertEqual((client.status, client.writes), ("ready", 0))

    def test_unexpected_stale_success_does_not_continue(self):
        client = FakeClient()
        client.stale_succeeds = True
        with mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(status.move.TestFailure):
                status.run(client, ARGS)
        self.assertEqual((client.status, client.writes), ("hidden", 1))

    def test_concurrent_status_races_restore_ready(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
        output = io.StringIO()
        with mock.patch.object(status.qbank, "MoodleClient",
                               side_effect=lambda *args: SimpleNamespace(call=client.call)) as constructor, \
                mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(output):
            status.run(client, args)
        self.assertEqual(constructor.call_count, 4)
        self.assertEqual(client.status, "ready")
        self.assertIn(client.writes, (2, 3))
        self.assertEqual(output.getvalue().count("Independent concurrent requests"), 2)
        self.assertNotIn(client.token, output.getvalue())

    def test_second_competing_status_winner_needs_no_extra_write(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))

        def second_wins(client, function, previews, rejection):
            proposed = previews[1]
            return proposed, client.call(function, previewtoken=proposed["previewtoken"], exclusive=True)

        with mock.patch.object(status.move, "race", side_effect=second_wins), \
                mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            status.run(client, args)
        self.assertEqual((client.status, client.writes), ("ready", 2))

    def test_concurrent_lost_response_or_unrelated_change_stops_without_restore(self):
        for failure in ("lost_response", "unrelated_change"):
            client = FakeClient()
            client.failure = failure
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
            with self.subTest(failure=failure), mock.patch.object(status.qbank, "MoodleClient",
                    side_effect=lambda *args: SimpleNamespace(call=client.call)), \
                    mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(status.move.TestFailure):
                    status.run(client, args)
            self.assertEqual((client.status, client.writes), ("draft", 1))


if __name__ == "__main__":
    unittest.main()
