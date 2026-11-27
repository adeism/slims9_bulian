"""Transport layer for the SLiMS harness.

Two backends speak the exact same envelope, so an agent does not care where
it runs:

* :class:`HttpBackend`   — talks to the CLI-Anything bridge plugin over HTTP
  (``index.php?p=api/cli-anything/v1/...``). Works from a laptop, a CI job or
  an agent sandbox; nothing needs to be installed on the SLiMS server.
* :class:`TarsiusBackend` — shells out to the bundled ``tarsius`` console
  commands. Needs PHP + filesystem access to the SLiMS root, which also makes
  it the right choice for scheduled jobs on the server itself.

Both return the envelope documented in the README::

    {"ok": true, "schema": "1.0", "command": "...", "data": {...}, "meta": {...}}

and raise :class:`BackendError` for ``{"ok": false, ...}`` payloads.
"""

from __future__ import annotations

import json
import shlex
import subprocess
from typing import Any, Dict, Iterable, Mapping, Optional, Tuple
from urllib import error as urlerror
from urllib import parse as urlparse
from urllib import request as urlrequest

DEFAULT_TIMEOUT = 60.0

#: command -> (method, path under /cli-anything/v1, params carried in the path)
HTTP_ROUTES: Dict[str, Tuple[str, str, Tuple[str, ...]]] = {
    "doctor": ("GET", "status", ()),
    "biblio.list": ("GET", "biblio", ()),
    "biblio.get": ("GET", "biblio/{id}", ("id",)),
    "item.get": ("GET", "item/{code}", ("code",)),
    "member.get": ("GET", "member/{id}", ("id",)),
    "loan.list": ("GET", "loan", ()),
    "report.summary": ("GET", "report/summary", ()),
    "loan.checkout": ("POST", "loan/checkout", ()),
    "loan.checkin": ("POST", "loan/checkin", ()),
    "loan.extend": ("POST", "loan/extend", ()),
}

#: command -> (console command, path params, flag map)
TARSIUS_COMMANDS: Dict[str, Tuple[str, Tuple[str, ...], Dict[str, str]]] = {
    "doctor": ("clianything:doctor", (), {}),
    "biblio.list": ("clianything:biblio:list", (), {"q": "--q", "limit": "--limit", "offset": "--offset"}),
    "biblio.get": ("clianything:biblio:get", ("id",), {}),
    "item.get": ("clianything:item:get", ("code",), {}),
    "member.get": ("clianything:member:get", ("id",), {"reveal": "--reveal"}),
    "loan.list": ("clianything:loan:list", (), {"status": "--status", "member": "--member", "limit": "--limit"}),
    "report.summary": ("clianything:report", (), {}),
    "loan.checkout": (
        "clianything:loan:checkout",
        (),
        {"member": "--member", "items": "--items", "item": "--item", "commit": "--commit"},
    ),
    "loan.checkin": (
        "clianything:loan:checkin",
        (),
        {"loanid": "--loanid", "itemcode": "--itemcode", "commit": "--commit"},
    ),
    "loan.extend": ("clianything:loan:extend", (), {"loanid": "--loanid", "commit": "--commit"}),
}

#: Params that are sent as `true` booleans and therefore rendered as bare flags.
BOOLEAN_PARAMS = {"commit", "reveal"}


class BackendError(RuntimeError):
    """A failed CLI-Anything call, carrying the machine readable envelope."""

    def __init__(self, payload: Mapping[str, Any], status: Optional[int] = None):
        self.payload = dict(payload)
        self.status = status
        error = self.payload.get("error") or {}
        self.code = str(error.get("code") or "BACKEND_ERROR")
        self.message = str(error.get("message") or "Unknown backend error")
        super().__init__(f"[{self.code}] {self.message}")

    def envelope(self) -> Dict[str, Any]:
        payload = dict(self.payload)
        payload.setdefault("ok", False)
        if self.status is not None:
            payload.setdefault("meta", {})
            if isinstance(payload["meta"], dict):
                payload["meta"].setdefault("http_status", self.status)
        return payload


class Backend:
    """Interface implemented by both transports."""

    name = "abstract"

    def call(self, command: str, params: Optional[Mapping[str, Any]] = None) -> Dict[str, Any]:
        raise NotImplementedError

    # -- helpers shared by both implementations -----------------------

    @staticmethod
    def _ensure_ok(payload: Any, status: Optional[int] = None) -> Dict[str, Any]:
        if not isinstance(payload, dict):
            raise BackendError(
                {"ok": False, "error": {"code": "BAD_RESPONSE", "message": "Response is not a JSON object"}},
                status,
            )
        if not payload.get("ok", False):
            raise BackendError(payload, status)
        return payload


class HttpBackend(Backend):
    """Talk to the CLI-Anything bridge plugin."""

    name = "http"

    def __init__(
        self,
        base_url: str,
        token: Optional[str] = None,
        timeout: float = DEFAULT_TIMEOUT,
    ):
        if not base_url:
            raise ValueError("base_url is required for the HTTP backend")
        self.base_url = base_url.rstrip("/")
        self.token = token or ""
        self.timeout = timeout

    def endpoint(self, path: str, query: Optional[Mapping[str, Any]] = None) -> str:
        url = f"{self.base_url}/index.php?p=api/cli-anything/v1/{path}"
        if query:
            clean = {k: v for k, v in query.items() if v not in (None, "", False)}
            if clean:
                url += "&" + urlparse.urlencode(clean)
        return url

    def call(self, command: str, params: Optional[Mapping[str, Any]] = None) -> Dict[str, Any]:
        if command not in HTTP_ROUTES:
            raise BackendError(
                {"ok": False, "error": {"code": "UNKNOWN_COMMAND", "message": f"Unknown command {command}"}}
            )

        params = dict(params or {})
        method, path, path_params = HTTP_ROUTES[command]

        for key in path_params:
            if params.get(key) in (None, ""):
                raise BackendError({"ok": False, "error": {"code": "INVALID_ARGUMENT", "message": f"Missing '{key}'"}})
            path = path.replace("{%s}" % key, urlparse.quote(str(params.pop(key)), safe=""))

        headers = {"Accept": "application/json", "User-Agent": "cli-anything-slims/1.0"}
        if self.token:
            headers["X-CLI-Anything-Token"] = self.token

        body: Optional[bytes] = None
        if method == "GET":
            url = self.endpoint(path, params)
        else:
            url = self.endpoint(path)
            body = json.dumps(params).encode("utf-8")
            headers["Content-Type"] = "application/json"

        request = urlrequest.Request(url, data=body, headers=headers, method=method)

        try:
            with urlrequest.urlopen(request, timeout=self.timeout) as response:
                raw = response.read().decode("utf-8", "replace")
                status = response.status
        except urlerror.HTTPError as error:
            raw = error.read().decode("utf-8", "replace")
            status = error.code
        except urlerror.URLError as error:
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "CONNECTION_FAILED",
                        "message": f"Cannot reach SLiMS at {self.base_url}: {error.reason}",
                    },
                }
            ) from error

        try:
            payload = json.loads(raw)
        except json.JSONDecodeError as error:
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "BAD_RESPONSE",
                        "message": f"SLiMS returned non-JSON output (HTTP {status}): {raw[:200]!r}",
                    },
                },
                status,
            ) from error

        return self._ensure_ok(payload, status)


class TarsiusBackend(Backend):
    """Shell out to the bundled ``tarsius`` console commands."""

    name = "tarsius"

    def __init__(
        self,
        slims_root: str,
        php_binary: str = "php",
        tarsius: Optional[str] = None,
        timeout: float = DEFAULT_TIMEOUT,
    ):
        if not slims_root:
            raise ValueError("slims_root is required for the tarsius backend")
        self.slims_root = slims_root.rstrip("/")
        self.php_binary = php_binary
        self.tarsius = tarsius or f"{self.slims_root}/tarsius"
        self.timeout = timeout

    def build_argv(self, command: str, params: Mapping[str, Any]) -> list:
        if command not in TARSIUS_COMMANDS:
            raise BackendError(
                {"ok": False, "error": {"code": "UNKNOWN_COMMAND", "message": f"Unknown command {command}"}}
            )

        console_command, path_params, option_map = TARSIUS_COMMANDS[command]
        params = dict(params or {})
        argv = [self.php_binary, self.tarsius, console_command]

        for key in path_params:
            if params.get(key) in (None, ""):
                raise BackendError({"ok": False, "error": {"code": "INVALID_ARGUMENT", "message": f"Missing '{key}'"}})
            argv.append(str(params.pop(key)))

        for key, option in option_map.items():
            if key not in params:
                continue
            value = params.pop(key)
            if key in BOOLEAN_PARAMS:
                if value:
                    argv.append(option)
                continue
            if value is None or value == "":
                continue
            argv.append(f"{option}={value}")

        argv.append("--json")

        return argv

    def call(self, command: str, params: Optional[Mapping[str, Any]] = None) -> Dict[str, Any]:
        argv = self.build_argv(command, params or {})

        try:
            completed = subprocess.run(
                argv,
                cwd=self.slims_root,
                capture_output=True,
                text=True,
                timeout=self.timeout,
                check=False,
            )
        except FileNotFoundError as error:
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "PHP_NOT_FOUND",
                        "message": f"Cannot execute {shlex.join(argv)}: {error}",
                    },
                }
            ) from error
        except subprocess.TimeoutExpired as error:
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "TIMEOUT",
                        "message": f"{shlex.join(argv)} timed out after {self.timeout}s",
                    },
                }
            ) from error

        payload = self._parse(completed.stdout)

        if payload is None:
            message = (completed.stderr or completed.stdout or "").strip()
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "BAD_RESPONSE",
                        "message": f"tarsius exited {completed.returncode} without a JSON envelope: {message[:400]}",
                    },
                }
            )

        return self._ensure_ok(payload, completed.returncode)

    @staticmethod
    def _parse(stdout: str) -> Optional[Dict[str, Any]]:
        stdout = (stdout or "").strip()
        if not stdout:
            return None

        try:
            return json.loads(stdout)
        except json.JSONDecodeError:
            pass

        # tarsius may prepend warnings/deprecations; take the last JSON object
        start = stdout.find("{")
        while start != -1:
            try:
                return json.loads(stdout[start:])
            except json.JSONDecodeError:
                start = stdout.find("{", start + 1)

        return None


def build_backend(
    backend: Optional[str] = None,
    base_url: Optional[str] = None,
    token: Optional[str] = None,
    slims_root: Optional[str] = None,
    tarsius: Optional[str] = None,
    php_binary: str = "php",
    timeout: float = DEFAULT_TIMEOUT,
) -> Backend:
    """Pick a transport from CLI flags/environment."""

    choice = (backend or "").strip().lower()
    if choice in ("", "auto"):
        if base_url:
            choice = "http"
        elif slims_root:
            choice = "tarsius"
        else:
            raise BackendError(
                {
                    "ok": False,
                    "error": {
                        "code": "NO_BACKEND",
                        "message": (
                            "No backend configured. Pass --base-url (HTTP bridge) or "
                            "--slims-root (local tarsius commands)."
                        ),
                    },
                }
            )

    if choice == "http":
        if not base_url:
            raise BackendError(
                {"ok": False, "error": {"code": "NO_BACKEND", "message": "--base-url is required for --backend http"}}
            )
        return HttpBackend(base_url, token=token, timeout=timeout)

    if choice == "tarsius":
        if not slims_root:
            raise BackendError(
                {
                    "ok": False,
                    "error": {"code": "NO_BACKEND", "message": "--slims-root is required for --backend tarsius"},
                }
            )
        return TarsiusBackend(slims_root, php_binary=php_binary, tarsius=tarsius, timeout=timeout)

    raise BackendError({"ok": False, "error": {"code": "UNKNOWN_BACKEND", "message": f"Unknown backend {backend!r}"}})


def csv_items(values: Iterable[str]) -> str:
    """Join repeated ``--item`` flags into the single ``items`` parameter."""

    return ",".join(str(value).strip() for value in values if str(value).strip())
