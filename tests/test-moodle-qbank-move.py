import contextlib
import importlib.util
import io
from pathlib import Path
from threading import Lock
from types import SimpleNamespace
import unittest
from unittest import mock


path = Path(__file__).resolve().parent.parent / "scripts" / "test-moodle-qbank-move.py"
spec = importlib.util.spec_from_file_location("qbank_move", path)
move = importlib.util.module_from_spec(spec)
spec.loader.exec_module(move)


class FakeClient:
    def __init__(self):
        self.category = 3
        self.previews = {}
        self.moves = 0
        self.failure = None
        self.source_top = False
        self.base, self.token, self.timeout = "https://example.test", "secret-token", 60
        self.lock = Lock()

    def call(self, function, **params):
        with self.lock:
            return self._call(function, **params)

    def _call(self, function, **params):
        if function == "list_categories":
            return {"total": 3, "items": [
                {"categoryid": i, "parentid": 0 if self.source_top and i == 3 else 1, "name": str(i),
                 "entrycount": int(i == self.category) + int(self.failure == "unrelated_change" and self.moves > 0 and i == 3)}
                for i in (2, 3, 4)
            ]}
        if function == "get_question":
            return {"question": {"questionid": 7, "entryid": 8, "categoryid": self.category, "version": 1},
                    "entryinuse": False, "versiontotal": 1,
                    "versions": [{"questionid": 7, "version": 1}]}
        if function == "preview_move":
            token = f"{len(self.previews) + 1:064x}"
            result = {"contextid": 16, "questionid": 7, "entryid": 8, "version": 1,
                      "sourceid": self.category, "targetid": params["targetid"], "previewtoken": token,
                      "versions": [{"questionid": 7, "version": 1, "status": "ready", "timemodified": 10}]}
            self.previews[token] = (result.copy(), False)
            return result
        if function == "move_question":
            token = params["previewtoken"]
            record, used = self.previews[token]
            if used:
                raise move.qbank.ApiError("used", "previewused")
            if record["sourceid"] != self.category:
                if self.failure == "stale_succeeds":
                    self.category = record["targetid"]
                    return {}
                raise move.qbank.ApiError("stale", "previewstale")
            self.previews[token] = (record, True)
            self.category = record["targetid"]
            self.moves += 1
            if self.failure == "lost_response":
                raise move.qbank.ApiError("network request failed")
            return dict(record, moveid=self.moves)
        raise AssertionError(function)


ARGS = SimpleNamespace(contextid=16, sourceid=3, targetid=2, alternateid=4, questionid=7, concurrent=False)


class MoveTest(unittest.TestCase):
    def test_round_trip_and_rejections(self):
        client = FakeClient()
        output = io.StringIO()
        with mock.patch("builtins.input", side_effect=["MOVE", "MOVE"]), contextlib.redirect_stdout(output):
            move.run(client, ARGS)
        self.assertEqual(client.category, 3)
        self.assertEqual(client.moves, 2)
        self.assertIn("previewused", output.getvalue())
        self.assertIn("previewstale", output.getvalue())
        self.assertNotIn("0" * 63 + "1", output.getvalue())

    def test_declined_confirmation_makes_no_move(self):
        for concurrent in (False, True):
            client = FakeClient()
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=concurrent))
            with self.subTest(concurrent=concurrent), mock.patch("builtins.input", return_value="no"), \
                    mock.patch.object(move.qbank, "MoodleClient") as constructor, contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(move.TestFailure):
                    move.run(client, args)
            constructor.assert_not_called()
            self.assertEqual((client.moves, client.category), (0, 3))

    def test_unexpected_stale_success_stops_without_return_move(self):
        client = FakeClient()
        client.failure = "stale_succeeds"
        with mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(move.TestFailure):
                move.run(client, ARGS)
        self.assertEqual(client.moves, 1)
        self.assertEqual(client.category, 4)

    def test_lost_response_does_not_retry_or_move_back(self):
        client = FakeClient()
        client.failure = "lost_response"
        with mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(move.qbank.ApiError):
                move.run(client, ARGS)
        self.assertEqual(client.moves, 1)
        self.assertEqual(client.category, 2)

    def test_distinct_categories_required_before_api_calls(self):
        client = FakeClient()
        args = SimpleNamespace(**vars(ARGS))
        args.alternateid = args.targetid
        with self.assertRaises(move.TestFailure):
            move.run(client, args)
        self.assertEqual(client.moves, 0)
        self.assertFalse(client.previews)

    def test_top_source_is_rejected_before_a_move_that_cannot_be_reversed(self):
        client = FakeClient()
        client.source_top = True
        with self.assertRaises(move.TestFailure):
            move.run(client, ARGS)
        self.assertEqual(client.moves, 0)
        self.assertFalse(client.previews)

    def test_concurrent_races_return_entry_to_source(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
        output = io.StringIO()
        with mock.patch.object(move.qbank, "MoodleClient",
                               side_effect=lambda *args: SimpleNamespace(call=client.call)) as constructor, \
                mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(output):
            move.run(client, args)
        self.assertEqual(constructor.call_count, 4)
        self.assertEqual(client.category, 3)
        self.assertIn(client.moves, (2, 3))
        self.assertEqual(output.getvalue().count("Independent concurrent requests"), 2)
        self.assertNotIn(client.token, output.getvalue())

    def test_second_competing_move_winner_is_returned_from_alternate(self):
        client = FakeClient()
        args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))

        def second_wins(client, function, previews, rejection):
            proposed = previews[1]
            return proposed, client.call(function, previewtoken=proposed["previewtoken"], exclusive=True)

        with mock.patch.object(move, "race", side_effect=second_wins), \
                mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
            move.run(client, args)
        self.assertEqual((client.category, client.moves), (3, 3))

    def test_concurrent_lost_response_or_unrelated_change_stops_without_return(self):
        for failure in ("lost_response", "unrelated_change"):
            client = FakeClient()
            client.failure = failure
            args = SimpleNamespace(**dict(vars(ARGS), concurrent=True))
            with self.subTest(failure=failure), mock.patch.object(move.qbank, "MoodleClient",
                    side_effect=lambda *args: SimpleNamespace(call=client.call)), \
                    mock.patch("builtins.input", return_value="MOVE"), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(move.TestFailure):
                    move.run(client, args)
            self.assertEqual((client.category, client.moves), (2, 1))


if __name__ == "__main__":
    unittest.main()
