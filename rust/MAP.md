# Port map

Every area of the original, the Rust that replaces it, and its state. Update
this file before starting a unit; it is the handoff between sessions.

States: **ported** · **partial** · **planned** · **kept in PHP** (until cutover).

| Original | Rust | State | Notes |
| --- | --- | --- | --- |
| `index.php` | `src/app.rs`, `src/opac.rs` | ported | request lifecycle, legacy query dispatch |
| `lib/Opac.php` | `src/opac.rs` | partial | path handling and pages; plugin hooks not ported |
| `lib/content.inc.php` | `src/content.rs`, Store public content reads | partial | published news/content reads, restricted formatting; no language/plugin/CMS parity |
| `lib/biblio_list.inc.php` | `store::search`, `store::mysql.rs` | ported | LIKE instead of FULLTEXT (ADR-0005) |
| `lib/biblio_list_model.inc.php` | `src/models.rs::SearchQuery` | ported | field list and pagination |
| `lib/contents/show_detail.inc.php` | `src/opac.rs::detail` | ported | metadata, copies, subjects; comments not ported |
| `lib/contents/member.inc.php` | `src/member.rs`, `src/member_limit.rs` | partial | experimental member flow; session basket, paginated loans/bookmarks, test-only writes and reservation prototype; not production-ready |
| `lib/contents/download_member_loan_report.inc.php` | `src/member_report.rs`, `Store::member_loan_report` | partial | authenticated combined printable HTML + CSV; 1000-loan/size ceiling; new UI, not byte parity |
| `lib/contents/download_current_loan.inc.php`, `download_loan_history.inc.php` | `src/member_report.rs`, scoped Store read | partial | authenticated GET/HEAD HTML attachments + CSV; scope-before-limit; not PHP markup parity |
| `lib/contents/librarian.inc.php` | `src/librarians.rs`, Store public librarian projection | partial | public types1/2, capped read-only directory; no social-media/i18n/admin editing parity |
| `lib/contents/login.inc.php` | — | planned | librarian login not ported |
| `lib/contents/news.inc.php`, `headerinfo.inc.php` | `src/content.rs` | partial | news GET/HEAD + legacy alias; headerinfo redirects home, block not ported |
| `template/default/**` | `src/templates.rs`, `static/opac.css` | ported (new UI) | ADR-0007 |
| `api/v1/routes.php` | `src/api.rs::handle` | ported | 15 routes |
| `api/v1/controllers/HomeController.php` | `src/api.rs::home` | ported | exact message |
| `api/v1/controllers/BiblioController.php` | `src/api.rs` | ported | popular/latest/gmd/coll_type/total |
| `api/v1/controllers/SubjectController.php` | `src/api.rs` | ported | popular/latest |
| `api/v1/controllers/MemberController.php` | `src/api.rs` | ported | top members |
| `api/v1/controllers/ItemController.php` | `src/api.rs` | ported | totals |
| `api/v1/controllers/LoanController.php` | `src/api.rs` | ported | summary/getdate/summary-date |
| `api/v1/helpers/Cache.php` | `src/api.rs::Cache` | ported | 18,000 s TTL, in memory |
| `api/v1/helpers/Image.php` | `src/api.rs::image_path` | ported | double `urlencode` preserved |
| `lib/DB.php`, `lib/Connection.php` | `src/store/mysql.rs` | ported | wire protocol, pooled connection |
| `lib/Json.php` | `src/json.rs` | ported | PHP-compatible escaping |
| `lib/Captcha/**` | — | kept in PHP | Phase 2 (member/admin forms) |
| `lib/Mail/**`, `lib/PHPMailer/**` | — | kept in PHP | Phase 2 |
| `lib/Session/**` | `src/member.rs`, `src/session_sqlite.rs` | partial | Rust-only member sessions, optional local SQLite; not compatible with PHP sessions |
| `lib/Auth/**` | `src/crypto.rs`, `src/member.rs` | partial | bcrypt login and test-only MD5 upgrade; no LDAP, captcha or admin authentication |
| `lib/Plugins.php`, `plugins/**` | — | kept in PHP | plugin hooks are PHP-only by design |
| `lib/SearchEngine/**` | `store::mysql.rs` (SQL) | partial | Elasticsearch/Sphinx/MongoDB not ported |
| `lib/Polyglot/**`, `lib/lang/**` | — | planned | i18n/gettext |
| `lib/Cli/**` | `src/main.rs` | partial | only `serve` |
| `lib/Migration/**`, `upgrade/**` | — | kept in PHP | installer/upgrader stays PHP |
| `install/senayan.sql`, `install/sampledata.sql` | `src/store/seed.rs` | ported (read-only) | demo seed |
| `admin/modules/bibliography/**` | — | planned | Phase 4 cataloging |
| `admin/modules/circulation/**` | — | planned | Phase 3 |
| `admin/modules/membership/**` | — | planned | Phase 2 |
| `admin/modules/reporting/**` | — | planned | Phase 5 |
| `admin/modules/stock_take/**` | — | planned | Phase 5 |
| `admin/modules/system/**` | — | planned | Phase 5 |
| `oai.php`, `oai2.php`, `oai2.xsl` | — | planned | Phase 5 OAI-PMH |
| `m/index.php` (mobile) | — | kept in PHP | responsive template covers it |
| `lib/minigalnano/createthumb.php` | `src/app.rs::thumbnail` | partial | serves original, no resize (ADR-0007) |
| `lib/VideoStream.php`, `fbinary.php` | — | kept in PHP | Phase 5 file streaming |
| `lib/Csv/**`, `lib/Parcel/**` | — | kept in PHP | import/export, Phase 4 |
| `lib/Debug/**`, `lib/Log/**` | — | kept in PHP | logging, Phase 5 |
| `lib/Currency.php`, `lib/Number.php` | — | planned | Phase 3 fines |
| `lib/AltoRouter.php` | `src/app.rs` | ported | simple router |
| `lib/Sanitizer.php` | `src/http.rs`, `src/templates.rs` | partial | escaping at output; no global input filter |

## What a "ported" line means

The Rust code implements the indicated route or data path and has a behavior
gate case for its main behavior where applicable. The gate fixtures are derived
from source, not yet recorded against a live PHP instance; they do not establish
full equivalence. "Partial" means the main
path works and a documented feature is missing. "Planned" means no Rust code
yet. "Kept in PHP" means the area is out of scope for the cutover or is
inherently PHP (plugins).
