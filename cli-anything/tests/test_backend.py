"""Transport tests: HTTP bridge client and tarsius argv/JSON handling."""

from __future__ import annotations

import json
from urllib.parse import parse_qs, urlparse

import pytest

from cli_anything.slims.backend import (
    BackendError,
    HttpBackend,
    TarsiusBackend,
    build_backend,
)


def test_http_get_builds_bridge_url_and_token_header(stub_server):
    stub_server.enqueue(200, {"ok": True, "schema": "1.0", "command": "biblio.list", "data": {"items": []}})
    backend = HttpBackend(stub_server.url, token="s3cr3t")

    payload = backend.call("biblio.list", {"q": "sejarah", "limit": 5, "offset": 0})

    assert payload["ok"] is True
    request = stub_server.requests[-1]
    assert request["method"] == "GET"

    parsed = urlparse(request["path"])
    query = parse_qs(parsed.query)
    assert query["p"] == ["api/cli-anything/v1/biblio"]
    assert query["q"] == ["sejarah"]
    assert query["limit"] == ["5"]

    assert request["headers"]["x-cli-anything-token"] == "s3cr3t"


def test_http_path_parameter_is_quoted(stub_server):
    stub_server.enqueue(200, {"ok": True, "data": {"item": {"item_code": "B 1/2"}}})
    backend = HttpBackend(stub_server.url, token="t")

    backend.call("item.get", {"code": "B 1/2"})

    path = stub_server.requests[-1]["path"]
    assert "item/B%201%2F2" in path


def test_http_post_sends_json_body(stub_server):
    stub_server.enqueue(200, {"ok": True, "data": {"dry_run": False, "committed": True}})
    backend = HttpBackend(stub_server.url, token="t")

    backend.call("loan.checkout", {"member": "M1", "items": "B1,B2", "commit": True})

    request = stub_server.requests[-1]
    assert request["method"] == "POST"
    assert "loan/checkout" in request["path"]
    assert json.loads(request["body"]) == {"member": "M1", "items": "B1,B2", "commit": True}


def test_http_error_envelope_becomes_backend_error(stub_server):
    stub_server.enqueue(
        403,
        {"ok": False, "error": {"code": "WRITE_DISABLED", "message": "Write operations are disabled."}},
    )
    backend = HttpBackend(stub_server.url, token="t")

    with pytest.raises(BackendError) as caught:
        backend.call("loan.checkout", {"member": "M1", "items": "B1", "commit": True})

    assert caught.value.code == "WRITE_DISABLED"
    assert caught.value.status == 403
    assert caught.value.envelope()["meta"]["http_status"] == 403


def test_http_non_json_response_is_reported(stub_server):
    stub_server.enqueue(200, {"ok": True})
    # simulate an HTML error page
    stub_server.httpd.responses[-1] = (200, "<html>login</html>")
    backend = HttpBackend(stub_server.url, token="t")

    with pytest.raises(BackendError) as caught:
        backend.call("doctor", {})

    assert caught.value.code == "BAD_RESPONSE"


def test_http_connection_failure_is_reported():
    backend = HttpBackend("http://127.0.0.1:1", token="t", timeout=1.0)

    with pytest.raises(BackendError) as caught:
        backend.call("doctor", {})

    assert caught.value.code == "CONNECTION_FAILED"


def test_tarsius_argv_checkout_with_commit():
    backend = TarsiusBackend("/var/www/slims", php_binary="php")

    argv = backend.build_argv("loan.checkout", {"member": "M1", "items": "B1,B2", "commit": True})

    assert argv[0] == "php"
    assert argv[1].endswith("/tarsius")
    assert argv[2] == "clianything:loan:checkout"
    assert "--member=M1" in argv
    assert "--items=B1,B2" in argv
    assert "--commit" in argv
    assert argv[-1] == "--json"


def test_tarsius_argv_omits_false_flags():
    backend = TarsiusBackend("/var/www/slims")

    argv = backend.build_argv("loan.checkin", {"loanid": 12, "commit": False})

    assert "--loanid=12" in argv
    assert "--commit" not in argv


def test_tarsius_argv_positional_arguments():
    backend = TarsiusBackend("/var/www/slims")

    argv = backend.build_argv("biblio.get", {"id": 42})

    assert argv[2] == "clianything:biblio:get"
    assert argv[3] == "42"


def test_tarsius_requires_path_parameter():
    backend = TarsiusBackend("/var/www/slims")

    with pytest.raises(BackendError) as caught:
        backend.build_argv("biblio.get", {})

    assert caught.value.code == "INVALID_ARGUMENT"


def test_tarsius_parses_envelope_after_php_noise():
    payload = TarsiusBackend._parse(
        'PHP Deprecated: something\n{"ok":true,"schema":"1.0","command":"doctor","data":{"gates":{}},"meta":{}}\n'
    )

    assert payload is not None
    assert payload["command"] == "doctor"


def test_tarsius_returns_none_for_garbage():
    assert TarsiusBackend._parse("Segmentation fault\n") is None


def test_build_backend_prefers_base_url():
    backend = build_backend(base_url="https://slims.example.org", token="t")

    assert isinstance(backend, HttpBackend)
    assert backend.base_url == "https://slims.example.org"


def test_build_backend_prefers_slims_root():
    backend = build_backend(slims_root="/var/www/slims")

    assert isinstance(backend, TarsiusBackend)


def test_build_backend_without_configuration_fails():
    with pytest.raises(BackendError) as caught:
        build_backend()

    assert caught.value.code == "NO_BACKEND"
