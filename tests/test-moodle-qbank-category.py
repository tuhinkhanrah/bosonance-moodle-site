import contextlib
import importlib.util
import io
from pathlib import Path
from threading import Lock
from types import SimpleNamespace
import unittest
from unittest import mock


path = Path(__file__).resolve().parent.parent / "scripts" / "test-moodle-qbank-category.py"
spec = importlib.util.spec_from_file_location("qbank_category", path)
category = importlib.util.module_from_spec(spec)
spec.loader.exec_module(category)


class FakeClient:
    def __init__(self):
        self.categories = {3: {"categoryid": 3, "parentid": 1, "name": "Parent", "entrycount": 0}}
        self.previews = {}
        self.writes = 0
        self.failure = None
        self.busy = False
        self.lock = Lock()

    def call(self, function, **params):
        with self.lock:
            return self._call(function, **params)

    def _call(self, function, **params):
        if function == "list_categories":
            return {"total": len(self.categories), "items": [dict(item) for item in self.categories.values()]}
        if function == "preview_category":
            item = self.categories[params["categoryid"]]
            token = f"{len(self.previews) + 1:064x}"
            result = {"contextid": 16, "categoryid": params["categoryid"], "action": params["action"],
                      "parentid": item["categoryid"] if params["action"] == "create" else item["parentid"],
                      "oldname": "" if params["action"] == "create" else item["name"], "newname": params["name"],
                      "previewtoken": token}
            self.previews[token] = (result.copy(), len(self.categories), False)
            return result
        if function == "change_category":
            if self.busy:
                self.busy = False
                raise category.qbank.ApiError("busy", "importbusy")
            result, count, used = self.previews[params["previewtoken"]]
            if used:
                raise category.qbank.ApiError("used", "categorypreviewused")
            if len(self.categories) != count or (result["action"] == "rename"
                                                 and self.categories[result["categoryid"]]["name"] != result["oldname"]):
                if self.failure == "stale_succeeds":
                    return {}
                raise category.qbank.ApiError("stale", "categorystale")
            self.previews[params["previewtoken"]] = (result, count, True)
            self.writes += 1
            categoryid = result["categoryid"]
            if result["action"] == "create":
                categoryid = 4
                self.categories[categoryid] = {"categoryid": categoryid, "parentid": result["parentid"],
                                                "entrycount": 0, "name": result["newname"]}
            else:
                self.categories[categoryid]["name"] = result["newname"]
            if self.failure == "lost_response":
                raise category.qbank.ApiError("network request failed")
            if self.failure == "unrelated_change":
                self.categories[3]["entrycount"] = 1
            return dict(result, resultcategoryid=categoryid, categoryauditid=self.writes)
        raise AssertionError(function)


ARGS = SimpleNamespace(contextid=16, parentid=3, concurrent=False, check_busy=False)


class CategoryTest(unittest.TestCase):
    def test_create_rename_round_trip_and_rejections(self):
        client = FakeClient()
        output = io.StringIO()
        with mock.patch("builtins.input", side_effect=["CATEGORY"] * 3), contextlib.redirect_stdout(output):
            category.run(client, ARGS)
        self.assertEqual(client.writes, 3)
        self.assertEqual(len(client.categories), 2)
        self.assertNotIn("-renamed", client.categories[4]["name"])
        self.assertIn("categorypreviewused", output.getvalue())
        self.assertIn("categorystale", output.getvalue())
        self.assertNotIn("0" * 63 + "1", output.getvalue())

    def test_cancel_makes_no_changes(self):
        client = FakeClient()
        with mock.patch("builtins.input", return_value="no"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(category.move.TestFailure):
                category.run(client, ARGS)
        self.assertEqual((client.writes, len(client.categories)), (0, 1))

    def test_lost_response_stops_without_retry(self):
        client = FakeClient()
        client.failure = "lost_response"
        with mock.patch("builtins.input", return_value="CATEGORY"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(category.qbank.ApiError):
                category.run(client, ARGS)
        self.assertEqual(client.writes, 1)

    def test_unexpected_stale_success_or_unrelated_change_stops(self):
        for failure in ("stale_succeeds", "unrelated_change"):
            with self.subTest(failure=failure):
                client = FakeClient()
                client.failure = failure
                with mock.patch("builtins.input", return_value="CATEGORY"), contextlib.redirect_stdout(io.StringIO()):
                    with self.assertRaises(category.move.TestFailure):
                        category.run(client, ARGS)
                self.assertEqual(client.writes, 1)

    def test_missing_token_reports_cause(self):
        output = io.StringIO()
        with mock.patch.dict("os.environ", {}, clear=True), contextlib.redirect_stderr(output):
            result = category.main(["--url", "https://example.test", "--contextid", "16", "--parentid", "3",
                                    "--allow-category-change"])
        self.assertEqual(result, 1)
        self.assertIn("Set the MOODLE_TOKEN", output.getvalue())

    def test_concurrent_token_races_with_independent_clients(self):
        shared = FakeClient()
        shared.base, shared.token, shared.timeout = "https://example.test", "secret-token", 60
        clients = []

        def client_factory(*args):
            client = SimpleNamespace(call=shared.call)
            clients.append(client)
            return client

        args = SimpleNamespace(**vars(ARGS))
        args.concurrent = True
        output = io.StringIO()
        with mock.patch.object(category.qbank, "MoodleClient", side_effect=client_factory), \
                mock.patch("builtins.input", return_value="CATEGORY"), contextlib.redirect_stdout(output):
            category.run(shared, args)
        self.assertEqual(shared.writes, 3)
        self.assertEqual(len(clients), 4)
        self.assertEqual(len({id(client) for client in clients}), 4)
        self.assertEqual(output.getvalue().count("Independent concurrent requests"), 2)
        self.assertNotIn("secret-token", output.getvalue())

    def test_concurrent_transport_failure_stops_without_recovery(self):
        shared = FakeClient()
        shared.base, shared.token, shared.timeout = "https://example.test", "secret-token", 60
        shared.failure = "lost_response"
        args = SimpleNamespace(**vars(ARGS))
        args.concurrent = True
        with mock.patch.object(category.qbank, "MoodleClient", return_value=SimpleNamespace(call=shared.call)), \
                mock.patch("builtins.input", return_value="CATEGORY"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(category.move.TestFailure):
                category.run(shared, args)
        self.assertEqual(shared.writes, 1)

    def test_concurrent_cancel_makes_no_changes(self):
        shared = FakeClient()
        args = SimpleNamespace(**vars(ARGS))
        args.concurrent = True
        with mock.patch.object(category.qbank, "MoodleClient") as constructor, \
                mock.patch("builtins.input", return_value="no"), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(category.move.TestFailure):
                category.run(shared, args)
        constructor.assert_not_called()
        self.assertEqual(shared.writes, 0)

    def test_race_rejects_wrong_counts_and_wrong_error(self):
        client = SimpleNamespace(base="https://example.test", token="secret-token", timeout=60)
        preview = {"previewtoken": "preview-token"}
        for responses in ([{}, {}],
                          [category.qbank.ApiError("used", "categorypreviewused")] * 2,
                          [{}, category.qbank.ApiError("unexpected", "importbusy")]):
            with self.subTest(responses=responses):
                clients = []
                for response in responses:
                    callback = mock.Mock(side_effect=response) if isinstance(response, Exception) \
                        else mock.Mock(return_value=response)
                    clients.append(SimpleNamespace(call=callback))
                with mock.patch.object(category.qbank, "MoodleClient", side_effect=clients), \
                        contextlib.redirect_stdout(io.StringIO()):
                    with self.assertRaises(category.move.TestFailure):
                        category.move.race(client, "change_category", [preview, preview], "categorypreviewused")
                for independent in clients:
                    independent.call.assert_called_once()

    def test_busy_rejection_leaves_preview_usable_and_bank_unchanged(self):
        client = FakeClient()
        client.busy = True
        args = SimpleNamespace(**vars(ARGS))
        args.check_busy = True
        output = io.StringIO()
        with mock.patch("builtins.input", return_value="CATEGORY"), contextlib.redirect_stdout(output):
            category.run(client, args)
        self.assertEqual(client.writes, 3)
        self.assertIn("importbusy; bank unchanged", output.getvalue())

    def test_busy_requires_long_enough_http_timeout(self):
        with mock.patch.object(category.qbank, "MoodleClient") as constructor, \
                contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as raised:
                category.main(["--url", "https://example.test", "--contextid", "16", "--parentid", "3",
                               "--allow-category-change", "--check-busy", "--timeout", "30"])
        self.assertEqual(raised.exception.code, 2)
        constructor.assert_not_called()


if __name__ == "__main__":
    unittest.main()
