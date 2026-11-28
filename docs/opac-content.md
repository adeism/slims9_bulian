# Public news and content port (ADR-0050)

This is a read-only feature replacement, not CMS/admin migration or PHP markup
parity. It uses the existing `content` table without schema changes or writes.

## Routes

- `GET|HEAD /news` and `/index.php?p=news`: ten published news per page,
  newest `last_update` first, `content_id` descending as a stable tie-breaker.
- `keywords` searches title/body as a literal substring, **not** PHP BOOLEAN
  FULLTEXT. `%`, `_`, quotes and backslashes are not operators. MySQL collation
  determines matching; memory uses lowercase Unicode matching, not full DB
  collation parity.
- `page=1..10001`: bounded offsets, eleven-row sentinel, previous/next navigation.
  There is no full-table total count or PHP-configurable page size.
- `GET|HEAD /pages/{path}` and `/index.php?p={path}`: a published content page.
  Existing named handlers (member, news, show_detail) take precedence. Unknown
  nonempty legacy `p` now returns content/not-found, rather than silently showing
  the catalog home/search. `/index.php?p=headerinfo` redirects home as in PHP;
  headerinfo remains a template block and is not exposed as a content page.
- News/content POSTs receive 405 and `Allow: GET, HEAD`; duplicate query names,
  invalid pages and oversized/control-character queries receive 400.

Paths are lowercase ASCII letters/digits/underscore/hyphen, at most 20 bytes,
starting with an alphanumeric character. All `admin*` and `headerinfo` paths are
excluded. Missing/draft pages return 404. MySQL enforces public scope before
pagination (including valid paths); it never caps then filters private rows.
The store defaults fail closed when unsupported. A DB failure is 503 with no
backend details, not a successful empty-news page.

## Stored formatting and bounds

Stored content is untrusted. Only exact attribute-free lowercase `p`, `br`,
`strong`, `em`, `b`, `i`, `ul`, `ol`, `li`, `h2`, `h3`, `blockquote` opening/closing
tags are emitted. All other tokens and text are escaped, including entities.
Links, images, attributes, script tags, comments, embeds and CKEditor extensions
are displayed as literal text, **not executed**. This is a restrictive formatting
renderer, not a general HTML sanitizer. No entity decode, stripslashes, plugins,
translations/language fallback, news scheduling, custom templates or CMS editing
is implemented. Titles/dates/queries/summaries are always escaped. News summaries
are at most 300 Unicode characters of the stored source (markup may be visible).

Content body limit is 256 KiB and title limit 4096 bytes; oversized selected rows
produce 413, never a partial page. All content responses have no-store, nosniff
and a restrictive CSP. Protocol result caps still apply before rendering; these
are not a whole-handler resource deadline or load-test result.

## Validation

Six in-process content tests cover public/draft/admin/null-draft filtering,
pagination/ties, literal searches, stored XSS payloads, escaped inputs, legacy
routes, 405/400/404/413, Unicode and actual TCP GET/HEAD response lengths. One
SQL-construction unit test checks scope-before-limit, hex literal inputs and
bounds. Four source-derived behavior-gate cases were added; these are not live
PHP differential recordings.

The fresh disposable fixture now includes thirteen news and newer private/draft/
invalid-path rows. `content_mysql` runs alongside `member_mysql` in the runner's
fresh-read stage before any destructive tests. These real-MySQL assertions were
compiled but **not executed** during this increment; no MySQL service is present.
Use a NEW EMPTY local fixture and the guarded suite described in
[member-validation.md](member-validation.md); never reuse a real SLiMS database.

Local validation with temporarily restored integrity-verified npm Rust tooling
(1.92 nightly, outside Git): default 79 library + behavior gate + 6 content + 2
startup tests; crypt 95 library + behavior + 6 content + 25 member + 2 startup;
SQLite 105 library + behavior + 6 content + 28 member + 2 startup passed. All three configurations include the size-limit regression. Eleven Python runner/evidence safety tests and py_compile
passed. CI template remains inactive; no production acceptance or MSRV proof.

Live memory HTTP behavior gate also passed 27/27 applicable cases (one MySQL-only
case skipped), including the four new content cases. This is not live PHP parity.
