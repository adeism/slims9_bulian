"""Shared fixtures for the CLI-Anything SLiMS harness tests."""

from __future__ import annotations

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List, Optional

import pytest
from click.testing import CliRunner

from cli_anything.slims import cli as cli_module
from cli_anything.slims.backend import BackendError


class FakeBackend:
    """Transport double that records calls."""

    name = "fake"

    def __init__(self, responses: Optional[Dict[str, Any]] = None, errors: Optional[Dict[str, BackendError]] = None):
        self.responses = responses or {}
        self.errors = errors or {}
        self.calls: List[tuple] = []

    def call(self, command: str, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        self.calls.append((command, dict(params or {})))
        if command in self.errors:
            raise self.errors[command]
        if command in self.responses:
            return self.responses[command]
        return {
            "ok": True,
            "schema": "1.0",
            "command": command,
            "data": {"items": [{"biblio_id": 1, "title": "Metodologi Penelitian"}]},
            "meta": {"transport": "fake"},
        }

    # convenience
    @property
    def last(self) -> tuple:
        return self.calls[-1]


@pytest.fixture
def fake(monkeypatch):
    backend = FakeBackend()

    def _factory(**kwargs):
        return backend

    monkeypatch.setattr(cli_module, "_build_backend", _factory)

    return backend


@pytest.fixture
def runner():
    return CliRunner()


class _RecordingHandler(BaseHTTPRequestHandler):
    server_version = "SlimsTest/1.0"

    def _handle(self, method: str):
        server = self.server
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8") if length else ""

        server.requests.append(  # type: ignore[attr-defined]
            {
                "method": method,
                "path": self.path,
                "headers": {key.lower(): value for key, value in self.headers.items()},
                "body": body,
            }
        )

        status, payload = server.responses.pop(0) if server.responses else (200, {"ok": True, "data": {}})  # type: ignore[attr-defined]

        raw = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        self._handle("GET")

    def do_POST(self):
        self._handle("POST")

    def log_message(self, *args):  # silence test output
        return


class StubServer:
    def __init__(self):
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), _RecordingHandler)
        self.httpd.requests = []  # type: ignore[attr-defined]
        self.httpd.responses = []  # type: ignore[attr-defined]
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    @property
    def url(self) -> str:
        host, port = self.httpd.server_address[:2]
        return f"http://{host}:{port}"

    @property
    def requests(self):
        return self.httpd.requests  # type: ignore[attr-defined]

    def enqueue(self, status: int, payload: Dict[str, Any]):
        self.httpd.responses.append((status, payload))  # type: ignore[attr-defined]

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *exc_info):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.thread.join(timeout=5)


@pytest.fixture
def stub_server():
    with StubServer() as server:
        yield server
