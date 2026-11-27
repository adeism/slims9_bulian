"""`cli-anything-slims` — an agent-native CLI for SLiMS 9 Bulian.

Examples::

    cli-anything-slims --base-url https://slims.sekolah.id doctor
    cli-anything-slims --base-url ... biblio list --q "sejarah" --limit 5
    cli-anything-slims --slims-root /var/www/slims loan checkout \
        --member M0001 --items B00001,B00002            # dry run
    cli-anything-slims --slims-root /var/www/slims loan checkout \
        --member M0001 --items B00001,B00002 --commit   # gated write
"""

from __future__ import annotations

from typing import Any, Dict, Mapping, Optional

import click

from . import __version__
from .backend import (
    DEFAULT_TIMEOUT,
    Backend,
    BackendError,
    build_backend,
    csv_items,
)
from .output import dump_json, render

EXIT_ERROR = 1
EXIT_GATE = 3
GATE_CODES = {"WRITE_DISABLED", "BULK_DISABLED", "PII_REVEAL_DISABLED"}


class Context:
    """Holds transport settings and prints envelopes."""

    def __init__(
        self,
        as_json: bool = False,
        backend: Optional[str] = None,
        base_url: Optional[str] = None,
        token: Optional[str] = None,
        slims_root: Optional[str] = None,
        tarsius: Optional[str] = None,
        php_binary: str = "php",
        timeout: float = DEFAULT_TIMEOUT,
    ):
        self.as_json = as_json
        self.backend_name = backend
        self.base_url = base_url
        self.token = token
        self.slims_root = slims_root
        self.tarsius = tarsius
        self.php_binary = php_binary
        self.timeout = timeout
        self._backend: Optional[Backend] = None

    def exit(self, code: int = 0):
        """Stop the command with an exit code (mirrors click.Context.exit)."""

        raise SystemExit(code)

    def backend(self) -> Backend:
        if self._backend is None:
            self._backend = _build_backend(
                backend=self.backend_name,
                base_url=self.base_url,
                token=self.token,
                slims_root=self.slims_root,
                tarsius=self.tarsius,
                php_binary=self.php_binary,
                timeout=self.timeout,
            )
        return self._backend

    def run(self, command: str, params: Optional[Mapping[str, Any]] = None, as_json: Optional[bool] = None) -> int:
        use_json = self.as_json if as_json is None else as_json

        try:
            payload = self.backend().call(command, params or {})
        except BackendError as error:
            if use_json:
                dump_json(error.envelope())
            else:
                click.secho(f"✖ [{error.code}] {error.message}", fg="red", err=True)
            return EXIT_GATE if error.code in GATE_CODES else EXIT_ERROR

        if use_json:
            dump_json(payload)
        else:
            render(payload)

        return 0


def _build_backend(**kwargs: Any) -> Backend:
    """Indirection so tests can inject a fake transport."""

    return build_backend(**kwargs)


def json_option():
    return click.option(
        "--json",
        "as_json_local",
        is_flag=True,
        default=None,
        help="Print the JSON envelope (same shape as the HTTP bridge).",
    )


def common_options(func):
    func = json_option()(func)
    return func


@click.group()
@click.option("--json", "as_json", is_flag=True, help="Print JSON envelopes only.")
@click.option("--backend", type=click.Choice(["http", "tarsius"]), default=None, help="Force a transport.")
@click.option("--base-url", envvar="SLIMS_BASE_URL", default=None, help="SLiMS base URL (HTTP bridge).")
@click.option("--token", envvar="SLIMS_CLI_ANYTHING_TOKEN", default=None, help="Bridge token.")
@click.option("--slims-root", envvar="SLIMS_ROOT", default=None, help="SLiMS root (tarsius backend).")
@click.option("--tarsius", "tarsius", envvar="SLIMS_TARSIUS", default=None, help="Path to the tarsius script.")
@click.option("--php", "php_binary", envvar="SLIMS_PHP_BINARY", default="php", show_default=True)
@click.option("--timeout", type=float, default=DEFAULT_TIMEOUT, show_default=True)
@click.version_option(__version__, prog_name="cli-anything-slims")
@click.pass_context
def cli(ctx, as_json, backend, base_url, token, slims_root, tarsius, php_binary, timeout):
    """Agent-native CLI for SLiMS 9 Bulian (read-only by default)."""

    ctx.obj = Context(
        as_json=as_json,
        backend=backend,
        base_url=base_url,
        token=token,
        slims_root=slims_root,
        tarsius=tarsius,
        php_binary=php_binary,
        timeout=timeout,
    )


# ---------------------------------------------------------------------
# doctor
# ---------------------------------------------------------------------


@cli.command()
@common_options
@click.pass_obj
def doctor(ctx: Context, as_json_local):
    """Report gates, limits and SLiMS health."""

    ctx.exit(ctx.run("doctor", {}, as_json_local))


# ---------------------------------------------------------------------
# biblio
# ---------------------------------------------------------------------


@cli.group()
def biblio():
    """Bibliography records."""


@biblio.command("list")
@click.option("--q", default="", help="Search title, ISBN/ISSN, call number or author.")
@click.option("--limit", type=int, default=20, show_default=True)
@click.option("--offset", type=int, default=0, show_default=True)
@common_options
@click.pass_obj
def biblio_list(ctx: Context, q, limit, offset, as_json_local):
    """Search bibliography records."""

    ctx.exit(ctx.run("biblio.list", {"q": q, "limit": limit, "offset": offset}, as_json_local))


@biblio.command("get")
@click.argument("biblio_id")
@common_options
@click.pass_obj
def biblio_get(ctx: Context, biblio_id, as_json_local):
    """Show one bibliography with authors, subjects and items."""

    ctx.exit(ctx.run("biblio.get", {"id": biblio_id}, as_json_local))


# ---------------------------------------------------------------------
# item
# ---------------------------------------------------------------------


@cli.group()
def item():
    """Collection copies."""


@item.command("get")
@click.argument("item_code")
@common_options
@click.pass_obj
def item_get(ctx: Context, item_code, as_json_local):
    """Show one item by item code (barcode)."""

    ctx.exit(ctx.run("item.get", {"code": item_code}, as_json_local))


# ---------------------------------------------------------------------
# member
# ---------------------------------------------------------------------


@cli.group()
def member():
    """Patrons."""


@member.command("get")
@click.argument("member_id")
@click.option("--reveal", is_flag=True, help="Show unmasked PII (needs allow_pii_reveal).")
@common_options
@click.pass_obj
def member_get(ctx: Context, member_id, reveal, as_json_local):
    """Show one member with loan summary (PII masked by default)."""

    ctx.exit(ctx.run("member.get", {"id": member_id, "reveal": reveal}, as_json_local))


# ---------------------------------------------------------------------
# loan
# ---------------------------------------------------------------------


@cli.group()
def loan():
    """Circulation. Write commands are dry runs unless --commit is given."""


@loan.command("list")
@click.option("--status", type=click.Choice(["active", "overdue", "returned", "all"]), default="active")
@click.option("--member", default="", help="Filter by member id.")
@click.option("--limit", type=int, default=20, show_default=True)
@common_options
@click.pass_obj
def loan_list(ctx: Context, status, member, limit, as_json_local):
    """List loans by status."""

    ctx.exit(ctx.run("loan.list", {"status": status, "member": member, "limit": limit}, as_json_local))


@loan.command("checkout")
@click.option("--member", "member_id", required=True, help="Member id.")
@click.option("--item", "items", multiple=True, help="Item code (repeatable).")
@click.option("--items", "items_csv", default="", help="Comma separated item codes.")
@click.option("--commit", is_flag=True, help="Actually write the loans (gated).")
@common_options
@click.pass_obj
def loan_checkout(ctx: Context, member_id, items, items_csv, commit, as_json_local):
    """Check out one or more items (dry run by default)."""

    codes = list(items)
    if items_csv:
        codes.extend(items_csv.split(","))

    payload = csv_items(codes)

    if not payload:
        raise click.UsageError("Provide --item CODE (repeatable) or --items CODE1,CODE2")

    ctx.exit(
        ctx.run(
            "loan.checkout",
            {"member": member_id, "items": payload, "commit": commit},
            as_json_local,
        )
    )


@loan.command("checkin")
@click.option("--loan-id", "loan_id", default=None, help="Loan id.")
@click.option("--item-code", "item_code", default=None, help="Item code.")
@click.option("--commit", is_flag=True, help="Actually return the item (gated).")
@common_options
@click.pass_obj
def loan_checkin(ctx: Context, loan_id, item_code, commit, as_json_local):
    """Return an item by loan id or item code (dry run by default)."""

    if not loan_id and not item_code:
        raise click.UsageError("Provide --loan-id or --item-code")

    params: Dict[str, Any] = {"commit": commit}
    if loan_id:
        params["loanid"] = loan_id
    if item_code:
        params["itemcode"] = item_code

    ctx.exit(ctx.run("loan.checkin", params, as_json_local))


@loan.command("extend")
@click.option("--loan-id", "loan_id", required=True, help="Loan id.")
@click.option("--commit", is_flag=True, help="Actually renew the loan (gated).")
@common_options
@click.pass_obj
def loan_extend(ctx: Context, loan_id, commit, as_json_local):
    """Renew a loan (dry run by default)."""

    ctx.exit(ctx.run("loan.extend", {"loanid": loan_id, "commit": commit}, as_json_local))


# ---------------------------------------------------------------------
# report
# ---------------------------------------------------------------------


@cli.group()
def report():
    """Aggregated numbers."""


@report.command("summary")
@common_options
@click.pass_obj
def report_summary(ctx: Context, as_json_local):
    """Collection, membership, circulation and fines summary."""

    ctx.exit(ctx.run("report.summary", {}, as_json_local))


def main() -> None:
    cli(prog_name="cli-anything-slims")


if __name__ == "__main__":  # pragma: no cover
    main()
