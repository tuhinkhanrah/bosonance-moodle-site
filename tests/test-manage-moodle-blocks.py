import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest import mock
from urllib.parse import parse_qs


SCRIPT = Path(__file__).resolve().parent.parent / "scripts" / "manage-moodle-blocks.py"
SPEC = importlib.util.spec_from_file_location("manage_moodle_blocks", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
manager = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manager)


def make_block(instanceid=17, blockname="html", content="<p>Current</p>"):
    block = {
        "instanceid": instanceid,
        "blockname": blockname,
        "contextid": 29,
        "parentcontextid": 5,
        "pagetypepattern": "*",
        "subpagepattern": None,
        "showinsubcontexts": False,
        "defaultregion": "side-pre",
        "defaultweight": 0,
    }
    if blockname == "html":
        block["html"] = {
            "title": "Sample",
            "content": content,
            "format": 1,
            "contenthash": hashlib.sha256(content.encode("utf-8")).hexdigest(),
        }
    return block


class FakeClient:
    def __init__(self, blocks):
        self.blocks = blocks
        self.calls = []

    def call(self, function, parameters):
        self.calls.append((function, parameters))
        if function == manager.LIST_FUNCTION:
            return self.blocks
        if function == manager.UPDATE_FUNCTION:
            return {
                "instanceid": parameters["instanceid"],
                "contenthash": hashlib.sha256(parameters["content"].encode("utf-8")).hexdigest(),
            }
        raise AssertionError(f"Unexpected function {function}")


class ManageMoodleBlocksTests(unittest.TestCase):
    def test_list_validates_hash_and_lists_every_block(self):
        blocks = [make_block(), make_block(18, "calendar_month")]
        client = FakeClient(blocks)
        result = manager.list_blocks(client)
        self.assertEqual(result, blocks)
        self.assertEqual(client.calls, [(manager.LIST_FUNCTION, {})])
        invalid = make_block(content="<p>Changed</p>")
        invalid["html"]["content"] = "<p>Different</p>"
        with self.assertRaisesRegex(manager.BlocksError, "hash does not match"):
            manager.validate_blocks([invalid])

    def test_dry_run_does_not_update_and_apply_uses_fresh_hash(self):
        current = make_block()
        client = FakeClient([current])
        content = "<p>Proposed</p>"
        with contextlib.redirect_stdout(io.StringIO()) as output:
            manager.update_block(client, 17, content, apply=False)
        self.assertIn("Dry run", output.getvalue())
        self.assertEqual([name for name, _ in client.calls], [manager.LIST_FUNCTION])

        client = FakeClient([current])
        manager.update_block(client, 17, content, apply=True)
        self.assertEqual(
            client.calls,
            [
                (manager.LIST_FUNCTION, {}),
                (
                    manager.UPDATE_FUNCTION,
                    {
                        "instanceid": 17,
                        "content": content,
                        "expectedhash": current["html"]["contenthash"],
                    },
                ),
            ],
        )

    def test_update_rejects_non_html_instance(self):
        client = FakeClient([make_block(17, "calendar_month")])
        with self.assertRaisesRegex(manager.BlocksError, "not an HTML block"):
            manager.update_block(client, 17, "<p>New</p>", apply=False)

    def test_list_json_includes_html_content(self):
        block = make_block()
        with contextlib.redirect_stdout(io.StringIO()) as output:
            manager.display_blocks([block], as_json=True)
        self.assertEqual(json.loads(output.getvalue()), [block])

    def test_rest_posts_token_and_keeps_it_out_of_url(self):
        client = manager.MoodleClient("https://example.test/moodle", "secret")
        client.opener.open = mock.Mock(return_value=io.BytesIO(b'{"instanceid":17}'))
        result = client.call(manager.UPDATE_FUNCTION, {"instanceid": 17, "content": "<p>A&B</p>"})
        request = client.opener.open.call_args.args[0]
        self.assertEqual(result, {"instanceid": 17})
        self.assertEqual(request.full_url, "https://example.test/moodle/webservice/rest/server.php")
        self.assertNotIn("secret", request.full_url)
        fields = parse_qs(request.data.decode("utf-8"))
        self.assertEqual(fields["wstoken"], ["secret"])
        self.assertEqual(fields["content"], ["<p>A&B</p>"])
        with self.assertRaises(manager.BlocksError):
            manager.MoodleClient("http://example.test", "secret")


if __name__ == "__main__":
    unittest.main()
