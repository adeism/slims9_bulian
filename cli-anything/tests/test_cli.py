"""Behaviour of the Click front-end: envelopes, gates, exit codes."""

from __future__ import annotations

import json

from cli_anything.slims import cli as cli_module
from cli_anything.slims.backend import BackendError


def test_doctor_json_envelope(runner, fake):
    result = runner.invoke(cli_module.cli, ["--json", "doctor"])

    assert result.exit_code == 0, result.output
    payload = json.loads(result.output)
    assert payload["ok"] is True
    assert payload["command"] == "doctor"


def test_json_flag_accepted_after_subcommand(runner, fake):
    result = runner.invoke(cli_module.cli, ["biblio", "list", "--q", "sejarah", "--json"])

    assert result.exit_code == 0, result.output
    assert fake.last == (
        "biblio.list",
        {"q": "sejarah", "limit": 20, "offset": 0, "sort": "relevance"},
    )
    assert json.loads(result.output)["ok"] is True


def test_biblio_sort_flag_is_forwarded(runner, fake):
    result = runner.invoke(cli_module.cli, ["biblio", "list", "--q", "sejarah", "--sort", "popular"])

    assert result.exit_code == 0, result.output
    command, params = fake.last
    assert command == "biblio.list"
    assert params["sort"] == "popular"


def test_biblio_sort_rejects_unknown_mode(runner, fake):
    result = runner.invoke(cli_module.cli, ["biblio", "list", "--sort", "cheapest"])

    assert result.exit_code == 2
    assert "cheapest" in result.output


def test_loan_checkout_is_dry_run_by_default(runner, fake):
    result = runner.invoke(cli_module.cli, ["loan", "checkout", "--member", "M0001", "--items", "B1, B2"])

    assert result.exit_code == 0, result.output
    command, params = fake.last
    assert command == "loan.checkout"
    assert params["member"] == "M0001"
    assert params["items"] == "B1,B2"
    assert params["commit"] is False


def test_loan_checkout_commit_flag_is_forwarded(runner, fake):
    result = runner.invoke(
        cli_module.cli,
        ["loan", "checkout", "--member", "M0001", "--item", "B1", "--item", "B2", "--commit"],
    )

    assert result.exit_code == 0, result.output
    _, params = fake.last
    assert params["items"] == "B1,B2"
    assert params["commit"] is True


def test_checkout_requires_items(runner, fake):
    result = runner.invoke(cli_module.cli, ["loan", "checkout", "--member", "M0001"])

    assert result.exit_code == 2
    assert "Provide --item" in result.output


def test_write_disabled_exits_with_gate_code(runner, fake):
    fake.errors["loan.checkout"] = BackendError(
        {
            "ok": False,
            "error": {
                "code": "WRITE_DISABLED",
                "message": "Write operations are disabled (loan.checkout).",
            },
        }
    )

    result = runner.invoke(
        cli_module.cli,
        ["--json", "loan", "checkout", "--member", "M0001", "--item", "B1", "--commit"],
    )

    assert result.exit_code == 3
    payload = json.loads(result.output)
    assert payload["ok"] is False
    assert payload["error"]["code"] == "WRITE_DISABLED"


def test_human_output_renders_table(runner, fake):
    result = runner.invoke(cli_module.cli, ["biblio", "list"])

    assert result.exit_code == 0, result.output
    assert "Metodologi Penelitian" in result.output
    assert "BIBLIO ID" in result.output


def test_dry_run_banner(runner, fake):
    fake.responses["loan.checkout"] = {
        "ok": True,
        "schema": "1.0",
        "command": "loan.checkout",
        "data": {
            "dry_run": True,
            "committed": False,
            "items": [{"item_code": "B1", "status": "ready", "due_date": "2026-12-11"}],
        },
        "meta": {},
    }

    result = runner.invoke(cli_module.cli, ["loan", "checkout", "--member", "M1", "--item", "B1"])

    assert result.exit_code == 0, result.output
    assert "DRY RUN" in result.output
    assert "2026-12-11" in result.output


def test_member_pii_masked_by_default(runner, fake):
    runner.invoke(cli_module.cli, ["member", "get", "M0001"])

    _, params = fake.last
    assert params["reveal"] is False


def test_missing_backend_reports_actionable_error(runner, monkeypatch):
    def _explode(**kwargs):
        raise BackendError(
            {
                "ok": False,
                "error": {
                    "code": "NO_BACKEND",
                    "message": "No backend configured. Pass --base-url or --slims-root.",
                },
            }
        )

    monkeypatch.setattr(cli_module, "_build_backend", _explode)

    result = runner.invoke(cli_module.cli, ["--json", "doctor"])

    assert result.exit_code == 1
    assert json.loads(result.output)["error"]["code"] == "NO_BACKEND"
