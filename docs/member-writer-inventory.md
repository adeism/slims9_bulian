# Member writer inventory and coordination contract

Scope: repository-local entry points reviewed for the Rust member replacement.
This is a baseline, not proof that plugins, scheduled jobs, deployments or direct
SQL clients have no additional writers. No production approval is implied.

| Writer | Tables/effects | Selected ownership and remaining coordination |
| --- | --- | --- |
| `lib/contents/member.inc.php` | bookmarks, password, basket/reservations | Legacy member include denied by ownership gate; proxy must route new member traffic to Rust. |
| `lib/member_logon.inc.php`, `lib/Auth/Methods/Native.php` | password upgrade, last-login/session | Legacy member authentication denied; admin authentication retained. |
| `admin/modules/circulation/circulation_action.php`, `reserveItemID` | reservation insertion | Denied by member ownership gate; do not leave a second uncoordinated insertion path. |
| Same file, `reserveID` | reservation deletion | Retained admin operation; Rust must read current counts, not cached counts. Verify administrator authorization in a full deployment. |
| `admin/modules/circulation/circulation_base_lib.inc.php`, loan processing | loans/items and reservation fulfillment/deletion | Retained administration. Not an atomic operation with Rust's multi-statement flow; full PHP/MySQL concurrency validation remains required. |
| `admin/modules/membership/index.php` | password resets, status/type/expiry, member rename/delete | Retained administration. Rust rechecks credential version on protected requests and reservation writes. Rename/delete dependencies require explicit operational policy. |
| `admin/modules/membership/import.php` | member import including credentials | Retained administration; bulk jobs must be inventoried/drained during cutover. |
| `admin/modules/membership/member_type.php` | reservation enable/limit, shared member policies | Retained administration; reservation policy must be read under the mutation lock. |
| Catalog/item administration | visibility, eligible copies/statuses | Retained administration; do not assume policy parity with the experimental reservation algorithm. |
| Plugins, custom providers, CLI jobs, direct SQL | unknown | Deployment owner must enumerate and approve; repository gate cannot constrain arbitrary code. |

## Reservation critical section (test database only)

HTTP single-title and basket SQL reservations use `test_reserve_bound`, supplying
the session credential version. MySQL takes the reservation WRITE and related
member/type/item/catalog READ locks, reads the stored password version and
policy, and refuses stale credentials before inserting. This closes the window
between the HTTP credential refresh and acquisition of those database locks.
The unbound `test_reserve` helper remains for direct disposable policy tests;
HTTP handlers must not use it. Unsupported stores fail closed for the bound API.

A password reset after the reservation lock is released does not undo the
reservation. Session expiry is checked by the HTTP/session layer, not atomically
with MySQL insertion. A response lost after insertion remains ambiguous. PHP
administrative multi-statement checks/writes are not made atomic by Rust locks.

## Evidence and gaps

The opt-in destructive MySQL test simulates an admin password reset holding a
member WRITE lock, then a bound reservation with the stale session version;
the reservation is refused and no row is inserted. It also tests changed
reservation enable/limit policy and restored valid credentials. Existing cases
cover pending/expired members and concurrent Rust reservation limits.

These are SQL-level simulations on MySQL 5.7.29, not an executing PHP admin,
FPM/proxy, plugin or MariaDB integration. Production ownership remains blocked
on those tests plus the acceptance criteria in `member-cutover.md`.

## Bookmark critical section (ADR-0041)

Both HTTP bookmark mutations call `member_bookmark_write_bound`. MySQL checks
the session credential version under member READ plus bookmark WRITE locks;
additions also check catalog visibility under biblio READ. The mutation runs
once on that same connection. Stale binding is refused, additions to unavailable
titles return NotFound, and removal allows orphan cleanup. Fixture-only unbound
helpers must not be used by HTTP handlers. The memory backend holds its password
mutex through mutation. Neither approach makes session expiry atomic with MySQL,
and locks/network contention still require deployment/load review.

The destructive SQL integration includes add/remove after a simulated admin
reset, hidden-title rejection, repeat behavior, byte-exact owner checks and
PHP-compatible keys. It passed on MySQL 5.7.29 with SQLite sessions and also with
memory sessions plus NO_BACKSLASH_ESCAPES. This is not a full executing PHP test.
