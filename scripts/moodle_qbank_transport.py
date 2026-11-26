"""Shared question-bank API client and contracts; no test or CLI execution."""

import ipaddress
import json
import uuid
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


PREFIX = "local_bosonanceqbank_"
STATUSES = ("ready", "draft", "hidden", "all")
SUMMARY_KEYS = {"questionid", "entryid", "categoryid", "version", "status", "name", "qtype", "timemodified"}
CONTENT_KEYS = {"questiontext", "questiontextformat", "generalfeedback", "generalfeedbackformat"}


class ApiError(Exception):
    def __init__(self, message, errorcode=None):
        super().__init__(message)
        self.errorcode = errorcode


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise HTTPError(req.full_url, code, "Redirect refused", headers, fp)


class MoodleClient:
    def __init__(self, base_url, token, timeout=60):
        try:
            parsed = urlsplit(base_url)
        except ValueError:
            raise ApiError("Invalid Moodle base URL") from None
        hostname = parsed.hostname.lower() if parsed.hostname else None
        loopback = hostname == "localhost"
        if hostname and not loopback:
            try:
                loopback = ipaddress.ip_address(hostname).is_loopback
            except ValueError:
                pass
        if (
            parsed.scheme not in ("https", "http") or not hostname
            or (parsed.scheme == "http" and not loopback) or parsed.username is not None
            or parsed.password is not None or parsed.query or parsed.fragment
        ):
            raise ApiError("Moodle URL must use HTTPS except on localhost/loopback, without credentials, query or fragment")
        if not token or not token.strip():
            raise ApiError("Set the MOODLE_TOKEN environment variable")
        self.base = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.opener = build_opener(NoRedirect())

    def _redact(self, value):
        return str(value).replace(self.token, "[redacted]")

    def _send(self, request, label):
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                body = response.read()
        except HTTPError as exc:
            raise ApiError(f"{label}: HTTP {exc.code}; redirects are not followed") from None
        except (URLError, TimeoutError, OSError):
            raise ApiError(f"{label}: network request failed") from None
        try:
            result = json.loads(body)
        except (UnicodeError, json.JSONDecodeError):
            raise ApiError(f"{label}: Moodle did not return valid JSON") from None
        if isinstance(result, dict) and ("exception" in result or "error" in result):
            code = self._redact(result.get("errorcode", "unknown"))
            message = self._redact(result.get("message") or result.get("error") or "Moodle API error")
            raise ApiError(f"{label}: {code}: {message}", code)
        return result

    def call(self, function, **parameters):
        fields = {"wstoken": self.token, "wsfunction": PREFIX + function, "moodlewsrestformat": "json"}
        for key, value in parameters.items():
            if isinstance(value, (list, tuple)):
                for index, item in enumerate(value):
                    fields[f"{key}[{index}]"] = item
            elif isinstance(value, bool):
                fields[key] = int(value)
            else:
                fields[key] = value
        request = Request(
            self.base + "/webservice/rest/server.php",
            data=urlencode(fields).encode("utf-8"),
            headers={"Accept": "application/json"},
            method="POST",
        )
        return self._send(request, function)

    def upload_draft(self, filename, content):
        boundary = "----qbanktest" + uuid.uuid4().hex
        parts = [
            f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
            for name, value in (("token", self.token), ("filearea", "draft"), ("itemid", "0"), ("filepath", "/"))
        ]
        parts.append(
            f'--{boundary}\r\nContent-Disposition: form-data; name="file_1"; filename="{filename}"\r\n'
            "Content-Type: text/xml\r\n\r\n".encode() + content + b"\r\n"
        )
        parts.append(f"--{boundary}--\r\n".encode())
        request = Request(
            self.base + "/webservice/upload.php",
            data=b"".join(parts),
            headers={"Accept": "application/json", "Content-Type": f"multipart/form-data; boundary={boundary}"},
            method="POST",
        )
        result = self._send(request, "upload")
        if not isinstance(result, list) or len(result) != 1 or not isinstance(result[0], dict):
            raise ApiError("upload: unexpected response")
        if result[0].get("error"):
            raise ApiError(f"upload: {self._redact(result[0].get('errortype', 'error'))}: {self._redact(result[0]['error'])}")
        itemid = result[0].get("itemid")
        if type(itemid) is not int or itemid <= 0 or result[0].get("filename") != filename:
            raise ApiError("upload: response missing draft item ID or filename")
        return itemid


def is_int(value, minimum=0):
    return type(value) is int and value >= minimum


def valid_import_tags(tags):
    if not isinstance(tags, list):
        return False
    ids, names = set(), set()
    for tag in tags:
        if (not isinstance(tag, dict) or not is_int(tag.get("id"))
                or not is_int(tag.get("collectionid"), 1)
                or not isinstance(tag.get("name"), str) or not tag["name"].strip()
                or type(tag.get("isstandard")) is not bool):
            return False
        action = tag.get("action", "reuse")
        if (action not in ("reuse", "create") or (tag["id"] == 0) != (action == "create")
                or (action == "create" and tag["isstandard"])):
            return False
        name = (tag["collectionid"], tag["name"].lower())
        if name in names or (tag["id"] and tag["id"] in ids):
            return False
        names.add(name)
        if tag["id"]:
            ids.add(tag["id"])
    return True
