import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


LIST_FUNCTION = "local_bosonanceblocks_list_blocks"
UPDATE_FUNCTION = "local_bosonanceblocks_update_html"
HASH_PATTERN = re.compile(r"[0-9a-f]{64}\Z")


class BlocksError(Exception):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise HTTPError(req.full_url, code, "Redirect refused", headers, fp)


class MoodleClient:
    def __init__(self, base_url, token):
        try:
            parsed = urlsplit(base_url)
        except ValueError as exc:
            raise BlocksError("Invalid Moodle base URL") from exc
        if (
            parsed.scheme != "https"
            or not parsed.hostname
            or parsed.username is not None
            or parsed.password is not None
            or parsed.query
            or parsed.fragment
        ):
            raise BlocksError(
                "Moodle URL must be an HTTPS base URL without credentials, query or fragment"
            )
        if not token or not token.strip():
            raise BlocksError("Set the MOODLE_TOKEN environment variable")
        self.endpoint = base_url.rstrip("/") + "/webservice/rest/server.php"
        self.token = token
        self.opener = build_opener(NoRedirect())

    def call(self, function, parameters):
        fields = {
            "wstoken": self.token,
            "wsfunction": function,
            "moodlewsrestformat": "json",
            **parameters,
        }
        request = Request(
            self.endpoint,
            data=urlencode(fields).encode("utf-8"),
            headers={"Accept": "application/json"},
            method="POST",
        )
        try:
            with self.opener.open(request, timeout=30) as response:
                body = response.read()
        except HTTPError as exc:
            raise BlocksError(f"{function}: HTTP {exc.code}; redirects are not followed") from exc
        except (URLError, TimeoutError, OSError) as exc:
            raise BlocksError(f"{function}: network request failed") from exc
        try:
            result = json.loads(body)
        except (UnicodeError, json.JSONDecodeError) as exc:
            raise BlocksError(f"{function}: Moodle did not return valid JSON") from exc
        if isinstance(result, dict) and "exception" in result:
            code = str(result.get("errorcode", "unknown")).replace(self.token, "[redacted]")
            message = str(result.get("message", "Moodle API error")).replace(
                self.token, "[redacted]"
            )
            raise BlocksError(f"{function}: {code}: {message}")
        return result


def validate_blocks(result):
    if not isinstance(result, list):
        raise BlocksError("Moodle returned an unexpected block list")

    seen_ids = set()
    required = {
        "instanceid",
        "blockname",
        "contextid",
        "parentcontextid",
        "pagetypepattern",
        "subpagepattern",
        "showinsubcontexts",
        "defaultregion",
        "defaultweight",
    }
    for index, block in enumerate(result, 1):
        if not isinstance(block, dict) or not required.issubset(block):
            raise BlocksError(f"Block {index}: Moodle returned incomplete block metadata")
        instanceid = block["instanceid"]
        if type(instanceid) is not int or instanceid <= 0:
            raise BlocksError(f"Block {index}: invalid instance ID")
        if instanceid in seen_ids:
            raise BlocksError(f"Block {index}: duplicate instance ID {instanceid}")
        seen_ids.add(instanceid)
        if not isinstance(block["blockname"], str):
            raise BlocksError(f"Block {instanceid}: invalid block type")

        if "html" in block:
            html = block["html"]
            if (
                block["blockname"] != "html"
                or not isinstance(html, dict)
                or not isinstance(html.get("title"), str)
                or not isinstance(html.get("content"), str)
                or not isinstance(html.get("contenthash"), str)
                or not HASH_PATTERN.fullmatch(html["contenthash"])
            ):
                raise BlocksError(f"Block {instanceid}: invalid HTML block data")
            actual_hash = hashlib.sha256(html["content"].encode("utf-8")).hexdigest()
            if html["contenthash"] != actual_hash:
                raise BlocksError(f"Block {instanceid}: content hash does not match its content")
    return result


def list_blocks(client):
    return validate_blocks(client.call(LIST_FUNCTION, {}))


def find_html_block(blocks, instanceid):
    matches = [block for block in blocks if block["instanceid"] == instanceid]
    if not matches:
        raise BlocksError(f"HTML block instance {instanceid} was not found")
    block = matches[0]
    if block["blockname"] != "html" or "html" not in block:
        raise BlocksError(f"Block instance {instanceid} is not an HTML block")
    return block


def read_content(path):
    try:
        content = path.read_text(encoding="utf-8-sig")
    except (OSError, UnicodeError) as exc:
        raise BlocksError(f"Cannot read HTML file {path}: {exc}") from exc
    if not content.strip():
        raise BlocksError(f"HTML file is empty: {path}")
    return content


def display_blocks(blocks, as_json=False):
    if as_json:
        print(json.dumps(blocks, ensure_ascii=False, indent=2))
        return
    if not blocks:
        print("No blocks found.")
        return
    for block in blocks:
        fields = [
            f"instanceid={block['instanceid']}",
            f"blockname={json.dumps(block['blockname'], ensure_ascii=False)}",
            f"contextid={block['contextid']}",
            f"parentcontextid={block['parentcontextid']}",
            f"pagetypepattern={json.dumps(block['pagetypepattern'], ensure_ascii=False)}",
            f"subpagepattern={json.dumps(block['subpagepattern'], ensure_ascii=False)}",
            f"showinsubcontexts={block['showinsubcontexts']}",
            f"defaultregion={json.dumps(block['defaultregion'], ensure_ascii=False)}",
            f"defaultweight={block['defaultweight']}",
        ]
        if "html" in block:
            fields.append(f"title={json.dumps(block['html']['title'], ensure_ascii=False)}")
            fields.append(f"contenthash={block['html']['contenthash']}")
        print(" ".join(fields))


def update_block(client, instanceid, content, apply):
    block = find_html_block(list_blocks(client), instanceid)
    old_hash = block["html"]["contenthash"]
    new_hash = hashlib.sha256(content.encode("utf-8")).hexdigest()
    if not apply:
        print(
            f"Dry run: HTML block {instanceid} verified; no content changed. "
            f"Current SHA-256 {old_hash}; proposed SHA-256 {new_hash}."
        )
        return

    result = client.call(
        UPDATE_FUNCTION,
        {
            "instanceid": instanceid,
            "content": content,
            "expectedhash": old_hash,
        },
    )
    if (
        not isinstance(result, dict)
        or result.get("instanceid") != instanceid
        or result.get("contenthash") != new_hash
    ):
        raise BlocksError(f"Block {instanceid}: unexpected update response; verify Moodle")
    print(f"Updated HTML block {instanceid}; content SHA-256 {new_hash}.")


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="List Moodle blocks or update an HTML block using Bosonance block web services."
    )
    parser.add_argument("--url", default=os.environ.get("MOODLE_URL"), help="Moodle HTTPS base URL")
    commands = parser.add_subparsers(dest="command", required=True)

    list_parser = commands.add_parser("list", help="List all block instances")
    list_parser.add_argument(
        "--json", action="store_true", help="Print complete JSON, including HTML block content"
    )

    update_parser = commands.add_parser("update", help="Update the content of one HTML block")
    update_parser.add_argument("--id", type=int, required=True, help="HTML block instance ID")
    update_parser.add_argument("--file", type=Path, required=True, help="UTF-8 HTML file to upload")
    update_parser.add_argument("--apply", action="store_true", help="Actually update Moodle")
    update_parser.add_argument(
        "--backup-confirmed",
        action="store_true",
        help="Confirm an independent database and Moodle data backup exists",
    )
    args = parser.parse_args(argv)
    if not args.url:
        parser.error("provide --url or set MOODLE_URL")
    if args.command == "update":
        if args.id <= 0:
            parser.error("--id must be a positive integer")
        if args.apply and not args.backup_confirmed:
            parser.error("--apply requires --backup-confirmed")
        if args.backup_confirmed and not args.apply:
            parser.error("--backup-confirmed is only valid with --apply")

    client = MoodleClient(args.url, os.environ.get("MOODLE_TOKEN"))
    if args.command == "list":
        display_blocks(list_blocks(client), args.json)
    else:
        content = read_content(args.file)
        update_block(client, args.id, content, args.apply)


if __name__ == "__main__":
    try:
        main()
    except BlocksError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        sys.exit(1)
