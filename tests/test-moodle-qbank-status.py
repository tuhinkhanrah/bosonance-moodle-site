import contextlib
import importlib.util
import io
from pathlib import Path
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

    def call(self, function, **params):
        if function == "get_question":
            return {"question": {"questionid": 7, "entryid": 8, "categoryid": 3,
                                 "version": 1, "status": self.status},
                    "entryinuse": False, "versions": [{"questionid": 7, "version": 1}]}
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
            return dict(record, statusid=self.writes)
        raise AssertionError(function)


ARGS = SimpleNamespace(contextid=16, categoryid=3, questionid=7)


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
        client = FakeClient()
        with mock.patch("builtins.input", return_value="no"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(status.move.TestFailure):
                status.run(client, ARGS)
        self.assertEqual((client.status, client.writes), ("ready", 0))

    def test_unexpected_stale_success_does_not_continue(self):
        client = FakeClient()
        client.stale_succeeds = True
        with mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(status.move.TestFailure):
                status.run(client, ARGS)
        self.assertEqual((client.status, client.writes), ("hidden", 1))


if __name__ == "__main__":
    unittest.main()
