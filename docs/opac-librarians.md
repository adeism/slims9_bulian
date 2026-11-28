# Public librarian directory (ADR-0051)

Read-only replacement for `lib/contents/librarian.inc.php`, using the existing
`user` table with no migration or production writes.

## Routes and visibility

`GET|HEAD /librarians` and `/index.php?p=librarian` show up to twenty profiles.
The selection mirrors PHP: only `user_type IN (1,2)` (Librarian and Senior
Librarian). Senior profiles come first; `user_id ASC` supplies a deterministic
tie-breaker, unlike PHP's unspecified order within a type. Types 0/3/unknown/NULL
are excluded **before** the SQL limit or memory cap. An administrator assigned
one of these public types is public too, exactly as with the legacy selection;
this is not an authorization role check. Review type assignments and public
email consent before routing live traffic.

Only `realname,email,user_image,user_type` are selected. Login names, password
hashes, 2FA material, reset tokens, IPs, group membership and login times never
enter the public profile model. No directory query parameter can select a user
or change the filter/limit. Unsupported or duplicate selectors return 400.
Non-GET/HEAD methods return 405 with `Allow: GET, HEAD` (never POST replay).

## Rendering and compatibility

Names and emails are escaped text, not raw HTML or mailto links. Role labels are
fixed English labels. Missing email displays `Not provided`. Portraits accept
only ASCII alphanumeric/underscore/hyphen stems and raster extensions
png/jpg/jpeg/gif/webp. Other values (SVG, URLs, traversal, percent escapes,
attributes or directories) fall back to `/images/persons/person.png`. A valid
but absent image still yields the static-file 404; no filesystem probing is done
per profile. The directory response has no-store, no-referrer, nosniff and a
restrictive CSP. Existing same-origin static serving is reused.

No serialized PHP `social_media` parsing, social links, translations, custom
role configuration, staff authentication or administrative profile editing is
ported. No `unserialize` or raw rich-HTML execution is introduced. This is
functional directory coverage, not full template/provider/PHP parity.

At most twenty profiles, 4096-byte names/emails and 1024-byte image fields are
accepted by the renderer; oversized backend results fail 413 without partial
profiles. DB/store failures are 503 with a static message, not an empty 200
or an error exposing connection details. A successful zero-row result is 200.

## Evidence

Six directory integration tests cover pre-limit filtering/order, missing data,
XSS payloads/private-field exclusion, portrait paths, route/method/selector
behavior and real TCP HEAD responses. Two renderer unit tests cover redacted
backend failure and fail-closed output bounds; one SQL projection unit test
checks fields/filter/order/limit. Four source-derived behavior-gate cases were
added, not recorded against a live PHP deployment.

Local offline locked tests passed with the temporarily restored Rust 1.92 nightly:

- Default: 82 library, one behavior gate, 6 content, 6 directory, 2 startup.
- member-crypt: 98 library, behavior, 6 content, 6 directory, 25 member, 2 startup.
- member-sqlite: 108 library, behavior, 6 content, 6 directory, 28 member, 2 startup.
- Eleven Python runner/evidence safety tests, fixture py_compile, diff check.
- Live memory HTTP gate: 31/31 applicable, one MySQL-only case skipped.

Disposable fixture adds public/private staff and a new ignored SQL integration
case in `content_mysql`, already selected by the guarded runner fresh-read stage.
It was compiled but **not executed on real MySQL** this increment. No new full
MySQL/PHP/proxy/parity/MSRV acceptance is claimed. CI remains an inactive template
because workflow permission is unavailable; production cutover blockers remain.
The shipped installer admin has NULL user_type, so the unmodified memory demo
correctly displays an empty directory rather than invented staff.
