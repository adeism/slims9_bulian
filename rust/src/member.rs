//! Experimental, opt-in member login and loan views. Not production-ready.
//! Database writes are explicitly opt-in and restricted to a local disposable
//! test database. Sessions may use the experimental local SQLite store.
use std::collections::HashMap;
use std::io::Read;
use std::sync::Mutex;
use std::time::{SystemTime, UNIX_EPOCH};
use std::path::Path;

use crate::app::AppState;
use crate::crypto::{legacy_md5, member_password, sha256};
use crate::http::{parse_query, Request, Response};
use crate::models::{DemoReserveResult, MemberProfile};
use crate::templates::{escape, layout};

const COOKIE: &str = "slims_rust_member";
pub(crate) const LIFETIME_SECONDS: u64 = 1800;
pub(crate) const MAX_SESSIONS: usize = 10_000;
pub(crate) const MAX_BASKET: usize = 20;
// Dummy verification keeps nonexistent users from skipping the bcrypt step.
const DUMMY_HASH: &str = "$2b$10$IyxvT/xs1wt2fvMl6h1JZuQuvN4xAlA7no9h31tp2OAfiIvI.ERDu";

#[derive(Clone)]
pub(crate) struct SessionMember {
    pub(crate) id: String,
    pub(crate) name: String,
    pub(crate) credential_version: String,
}

#[derive(Clone)]
pub(crate) struct Session {
    pub(crate) csrf: String,
    pub(crate) member: Option<SessionMember>,
    pub(crate) expires: u64,
    pub(crate) basket: Vec<i64>,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum BasketAction { Add(i64), Remove(i64), Clear }
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum BasketResult { Changed, Unchanged, Full }

pub(crate) fn apply_basket(basket: &mut Vec<i64>, action: BasketAction) -> BasketResult {
    match action {
        BasketAction::Add(id) if id <= 0 => BasketResult::Unchanged,
        BasketAction::Add(id) if basket.contains(&id) => BasketResult::Unchanged,
        BasketAction::Add(_) if basket.len() >= MAX_BASKET => BasketResult::Full,
        BasketAction::Add(id) => { basket.push(id); BasketResult::Changed }
        BasketAction::Remove(id) => {
            let before = basket.len(); basket.retain(|entry| *entry != id);
            if before == basket.len() { BasketResult::Unchanged } else { BasketResult::Changed }
        }
        BasketAction::Clear => { if basket.is_empty() { BasketResult::Unchanged } else { basket.clear(); BasketResult::Changed } }
    }
}

pub struct Sessions {
    memory: Mutex<HashMap<String, Session>>,
    #[cfg(feature = "member-sqlite")]
    disk: Option<crate::session_sqlite::SqliteSessions>,
}
impl Sessions {
    pub fn new(path: Option<&Path>) -> std::io::Result<Self> {
        #[cfg(not(feature = "member-sqlite"))]
        if path.is_some() { return Err(std::io::Error::new(std::io::ErrorKind::Other, "SLIMS_SESSION_DB requires the member-sqlite feature")); }
        Ok(Self {
            memory: Mutex::new(HashMap::new()),
            #[cfg(feature = "member-sqlite")]
            disk: path.map(|p| crate::session_sqlite::SqliteSessions::new(p.to_path_buf())).transpose()?,
        })
    }
    fn lock(&self) -> std::sync::MutexGuard<'_, HashMap<String, Session>> {
        self.memory.lock().unwrap_or_else(|e| e.into_inner())
    }
    fn read(&self, id: &str) -> std::io::Result<Option<Session>> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.read(id); }
        let now = now_seconds()?;
        let mut map = self.lock();
        if map.get(id).map(|s| s.expires <= now).unwrap_or(false) { map.remove(id); }
        Ok(map.get(id).cloned())
    }
    fn issue(&self, member: Option<SessionMember>) -> std::io::Result<(String, Session)> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.issue(member); }
        let id = random_token()?;
        let now = now_seconds()?;
        let session = Session { csrf: random_token()?, member, expires: now.saturating_add(LIFETIME_SECONDS), basket: Vec::new() };
        let mut map = self.lock();
        map.retain(|_, value| value.expires > now);
        if map.len() >= MAX_SESSIONS { return Err(std::io::Error::new(std::io::ErrorKind::Other, "session capacity")); }
        if map.contains_key(&id) { return Err(std::io::Error::new(std::io::ErrorKind::Other, "session collision")); }
        map.insert(id.clone(), session.clone());
        Ok((id, session))
    }
    fn rotate(&self, old: &str, csrf: &str, member: SessionMember) -> std::io::Result<Option<String>> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.rotate(old, csrf, member); }
        let id = random_token()?;
        let new_csrf = random_token()?;
        let now = now_seconds()?;
        let mut map = self.lock();
        let valid = map.get(old).map(|s| s.expires > now && s.member.is_none() && constant_eq(&s.csrf, csrf)).unwrap_or(false);
        if !valid { return Ok(None); }
        if map.contains_key(&id) { return Err(std::io::Error::new(std::io::ErrorKind::Other, "session collision")); }
        map.remove(old);
        map.insert(id.clone(), Session { csrf: new_csrf, member: Some(member), expires: now.saturating_add(LIFETIME_SECONDS), basket: Vec::new() });
        Ok(Some(id))
    }
    /// Serialize a mutation against logout on this session store. Test-only
    /// database writes hold this lock across network I/O; not production-ready.
    fn with_authenticated<T>(&self, id: &str, csrf: &str, f: impl FnOnce(&str) -> T) -> std::io::Result<Option<T>> {
        self.with_basket_authenticated(id, csrf, |member, _| f(member))
    }
    fn with_basket_authenticated<T>(&self, id: &str, csrf: &str, f: impl FnOnce(&str, &[i64]) -> T) -> std::io::Result<Option<T>> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.with_basket_authenticated(id, csrf, f); }
        let now = now_seconds()?;
        let map = self.lock();
        let Some(session) = map.get(id) else { return Ok(None) };
        if session.expires <= now || !constant_eq(&session.csrf, csrf) { return Ok(None); }
        let Some(member) = session.member.as_ref() else { return Ok(None) };
        Ok(Some(f(&member.id, &session.basket)))
    }
    /// Session-scoped basket updates are serialized with logout/expiry.
    fn modify_basket(&self, id: &str, csrf: &str, action: BasketAction) -> std::io::Result<Option<BasketResult>> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.modify_basket(id, csrf, action); }
        let now = now_seconds()?;
        let mut map = self.lock();
        let Some(session) = map.get_mut(id) else { return Ok(None) };
        if session.expires <= now || session.member.is_none() || !constant_eq(&session.csrf, csrf) { return Ok(None); }
        Ok(Some(apply_basket(&mut session.basket, action)))
    }
    /// Revoke this member's sessions in this store, authorizing and deleting
    /// under the same lock so an expired/logged-out caller cannot revoke others.
    fn invalidate_all(&self, id: &str, csrf: &str) -> std::io::Result<bool> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.invalidate_all(id, csrf); }
        let now = now_seconds()?;
        let mut map = self.lock();
        let Some(session) = map.get(id) else { return Ok(false); };
        if session.expires <= now || !constant_eq(&session.csrf, csrf) { return Ok(false); }
        let Some(member) = &session.member else { return Ok(false); };
        let member_id = member.id.clone();
        map.retain(|_, session| session.member.as_ref().map_or(true, |member| member.id != member_id));
        Ok(true)
    }
    fn invalidate(&self, id: &str, csrf: &str) -> std::io::Result<bool> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.invalidate(id, csrf); }
        let now = now_seconds()?;
        let mut map = self.lock();
        let valid = map.get(id).map(|s| s.expires > now && constant_eq(&s.csrf, csrf)).unwrap_or(false);
        if valid { map.remove(id); }
        Ok(valid)
    }
}

pub(crate) fn now_seconds() -> std::io::Result<u64> {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|time| time.as_secs())
        .map_err(|_| std::io::Error::new(std::io::ErrorKind::Other, "system clock before epoch"))
}

pub(crate) fn random_token() -> std::io::Result<String> {
    let mut bytes = [0u8; 32];
    std::fs::File::open("/dev/urandom")?.read_exact(&mut bytes)?;
    Ok(bytes.iter().map(|byte| format!("{:02x}", byte)).collect())
}
pub(crate) fn constant_eq(a: &str, b: &str) -> bool {
    if a.len() != b.len() { return false; }
    a.bytes().zip(b.bytes()).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}
/// Fingerprint the stored password hash, not the supplied password. The raw
/// hash is never placed in the session. This is a version marker, not a MAC.
pub(crate) fn credential_version(hash: &str) -> String {
    sha256(hash.as_bytes()).iter().map(|b| format!("{b:02x}")).collect()
}

/// Check the current credential each time a protected page/action is accessed.
/// A backend failure is not treated as a logout or an anonymous session.
fn refresh_member(session: &mut Session, state: &AppState) -> crate::store::StoreResult<bool> {
    let Some(member) = &mut session.member else { return Ok(true) };
    let Some(current) = state.store.member_credential(&member.id)? else { return Ok(false) };
    if current.id != member.id || !constant_eq(&member.credential_version, &credential_version(&current.password_hash)) {
        return Ok(false);
    }
    member.name = current.name;
    Ok(true)
}

fn revoke_response(id: &str, csrf: &str, state: &AppState) -> Response {
    match state.member_sessions.invalidate(id, csrf) {
        Ok(_) => private(Response::redirect("/member")).with_header("Set-Cookie", &clear_cookie(state)),
        Err(_) => unavailable(),
    }
}

fn cookie(request: &Request) -> Option<&str> {
    // HTTP/2 proxies can split Cookie into multiple fields. Inspect all fields
    // and reject duplicate session names even if the values are identical.
    let mut matches = request.headers.iter().filter(|(name, _)| name.eq_ignore_ascii_case("cookie"))
        .flat_map(|(_, value)| value.split(';')).filter_map(|part| part.trim().split_once('='))
        .filter(|(name, _)| *name == COOKIE).map(|(_, value)| value);
    let value = matches.next()?;
    if matches.next().is_some() || value.len() != 64 || !value.bytes().all(|b| b.is_ascii_hexdigit()) { return None; }
    Some(value)
}
fn set_cookie(id: &str, state: &AppState) -> String {
    format!("{}={}; Path=/; HttpOnly; SameSite=Lax; Max-Age=1800{}", COOKIE, id,
        if state.config.member_insecure_cookie { "" } else { "; Secure" })
}
fn clear_cookie(state: &AppState) -> String {
    format!("{}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0{}", COOKIE,
        if state.config.member_insecure_cookie { "" } else { "; Secure" })
}
fn private(response: Response) -> Response {
    response.with_header("Cache-Control", "no-store, private")
        .with_header("Referrer-Policy", "no-referrer")
        .with_header("X-Content-Type-Options", "nosniff")
        .with_header("Pragma", "no-cache")
        .with_header("Content-Security-Policy", "default-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'")
}
fn unavailable() -> Response { private(Response::text(503, "Member service unavailable")) }
fn forbidden() -> Response { private(Response::text(403, "Invalid or expired session or CSRF token")) }
fn write_unknown() -> Response { private(Response::text(503, "The change may or may not have been applied. Check the account before retrying.")) }
fn valid_new_password(password: &str) -> bool {
    let bytes = password.as_bytes();
    (8..=72).contains(&bytes.len()) && !bytes.contains(&0) &&
        bytes.iter().any(u8::is_ascii_uppercase) && bytes.iter().any(u8::is_ascii_digit) &&
        bytes.iter().any(|b| !b.is_ascii_alphanumeric())
}
/// CSV quotes alone do not prevent spreadsheet formula evaluation. Prefix
/// dangerous/leading-control values and escape quotes in every cell. The
/// apostrophe is intentional data in the export, not a lossless backup format.
pub(crate) fn csv_cell(value: &str) -> String {
    let trimmed = value.trim_start_matches(|c: char| c.is_whitespace() || c.is_control() || c == '\u{feff}');
    let dangerous = value.chars().next().map_or(false, |c| c.is_control()) ||
        trimmed.starts_with(['=', '+', '-', '@']);
    format!("\"{}{}\"", if dangerous { "'" } else { "" }, value.replace('"', "\"\""))
}

fn export_loan_page(state: &AppState, session: &Session, returned: bool, page: usize) -> Response {
    let Some(member) = &session.member else { return forbidden(); };
    let rows = match state.store.member_loans_page(&member.id, returned, (page - 1) * 20) {
        Ok(rows) => rows, Err(_) => return unavailable(),
    };
    let mut csv = String::from("\u{feff}"); // UTF-8 hint for spreadsheet readers
    let mut append = |values: [&str; 4]| {
        csv.push_str(&values.map(csv_cell).join(","));
        csv.push_str("\r\n");
    };
    append(["Item Code", "Title", "Loan Date", if returned { "Return Date" } else { "Due Date" }]);
    for loan in rows.iter().take(20) {
        append([&loan.item_code, loan.title.as_deref().unwrap_or(""), loan.loan_date.as_deref().unwrap_or(""),
            if returned { loan.return_date.as_deref().unwrap_or("") } else { loan.due_date.as_deref().unwrap_or("") }]);
    }
    let kind = if returned { "history" } else { "current" };
    private(Response::new(200)
        .with_header("Content-Type", "text/csv; charset=utf-8")
        .with_header("Content-Disposition", &format!("attachment; filename=\"member-loans-{kind}-page-{page}.csv\""))
        .with_body(csv.into_bytes()))
}

/// Display-only UTC calendar lateness, not a fine or holiday calculation.
fn render_loan_date(raw: Option<&str>, returned: bool, today: crate::date::Date) -> String {
    let displayed = escape(raw.unwrap_or("—"));
    if returned { return displayed; }
    let Some(raw) = raw else { return displayed; };
    let Some(due) = crate::date::Date::parse(raw) else { return displayed; };
    // Do not flag malformed/zero/impossible legacy dates as overdue.
    if raw != due.iso() || crate::date::Date::from_days(due.to_days()) != due || due.year < 1 {
        return displayed;
    }
    let days = today.to_days() - due.to_days();
    if days > 0 {
        format!("<strong>{displayed} — Overdue: {days} calendar day{}</strong>", if days == 1 { "" } else { "s" })
    } else { displayed }
}

fn form_value<'a>(form: &'a [(String, String)], key: &str) -> Option<&'a str> {
    let mut values = form.iter().filter(|(name, _)| name == key).map(|(_, value)| value.as_str());
    let value = values.next()?;
    if values.next().is_some() { None } else { Some(value) }
}

pub fn handle(request: &Request, state: &AppState) -> Response {
    if request.path.trim_end_matches('/') == "/index.php" && matches!(request.query("p"), Some("download_member_loan_report" | "download_current_loan" | "download_loan_history")) {
        if request.query.iter().filter(|(key, _)| key == "p").count() != 1 || request.query.iter().any(|(key, _)| matches!(key.as_str(), "sec" | "page" | "scope")) {
            return private(Response::text(400, "Invalid legacy report selector"));
        }
        if !matches!(request.method.as_str(), "GET" | "HEAD") { return private(Response::text(405, "Loan report requires GET or HEAD")); }
        let scope = match request.query("p") { Some("download_current_loan") => "current", Some("download_loan_history") => "history", _ => "all" };
        let mut canonical = request.clone();
        canonical.query.retain(|(key, _)| key != "p");
        canonical.query.push(("sec".into(), "loan_report".into()));
        canonical.query.push(("scope".into(), scope.into()));
        if scope != "all" && request.query("format").is_none() { canonical.query.push(("format".into(), "html".into())); }
        return handle(&canonical, state);
    }
    // Reject ambiguous selectors rather than relying on first-value semantics
    // that can differ from a proxy or PHP's last-value handling.
    for key in ["sec", "page", "format", "biblio_id", "scope"] {
        if request.query.iter().filter(|(name, _)| name == key).count() > 1 {
            return private(Response::text(400, "Duplicate member parameter"));
        }
    }
    if request.method == "GET" || request.method == "HEAD" {
        // State-changing GET logout is intentionally not supported.
        if request.query("logout").is_some() { return private(Response::text(405, "Use POST to sign out")); }
        let return_title = if request.query("sec") == Some("title_actions") {
            match request.query("biblio_id").and_then(positive_title_id) {
                Some(id) => Some(id),
                None => return private(Response::text(400, "Invalid title ID")),
            }
        } else { None };
        let current = match cookie(request) {
            Some(id) => match state.member_sessions.read(id) { Ok(session) => session, Err(_) => return unavailable() },
            None => None,
        };
        if let Some(mut session) = current {
            match refresh_member(&mut session, state) {
                Ok(true) => {
                    let section = request.query_or("sec", "current_loan");
                    let page = if matches!(section, "bookmark" | "current_loan" | "loan_history") && session.member.is_some() {
                        let values: Vec<_> = request.query.iter().filter(|(key, _)| key == "page").collect();
                        if values.len() > 1 { return private(Response::text(400, "Invalid page")); }
                        match values.first() {
                            None => 1,
                            Some((_, raw)) => match raw.parse::<usize>() {
                                Ok(n) if (1..=5001).contains(&n) => n,
                                _ => return private(Response::text(400, "Invalid page")),
                            },
                        }
                    } else { 1 };
                    if section == "loan_report" && session.member.is_some() {
                        if request.query.iter().any(|(key, _)| matches!(key.as_str(), "page" | "biblio_id" | "memberID" | "member_id")) ||
                            request.query("format").map_or(false, |format| !matches!(format, "csv" | "html")) {
                            return private(Response::text(400, "Invalid report selector"));
                        }
                        let scope = match request.query_or("scope", "all") {
                            "all" => crate::models::MemberLoanReportScope::All,
                            "current" => crate::models::MemberLoanReportScope::Current,
                            "history" => crate::models::MemberLoanReportScope::History,
                            _ => return private(Response::text(400, "Invalid report scope")),
                        };
                        let member = session.member.as_ref().expect("authenticated member");
                        let rows = match state.store.member_loan_report_scoped(&member.id, scope) { Ok(rows) => rows, Err(_) => return unavailable() };
                        let profile = match state.store.member_profile(&member.id) {
                            Ok(Some(profile)) if profile.id == member.id => profile,
                            Ok(_) => return revoke_response(cookie(request).unwrap(), &session.csrf, state),
                            Err(_) => return unavailable(),
                        };
                        let response = crate::member_report::render_scoped(&rows, &profile, &state.config.library_name, request.query("format") == Some("csv"), scope, request.query("format") == Some("html"));
                        // Discard a report if a password reset/deletion became
                        // visible while its data was being read.
                        return match refresh_member(&mut session, state) {
                            Ok(true) => private(response),
                            Ok(false) => revoke_response(cookie(request).unwrap(), &session.csrf, state),
                            Err(_) => unavailable(),
                        };
                    }
                    if session.member.is_some() {
                        let formats: Vec<_> = request.query.iter().filter(|(key, _)| key == "format").collect();
                        if !formats.is_empty() {
                            if formats.len() != 1 || formats[0].1 != "csv" || !matches!(section, "current_loan" | "loan_history") {
                                return private(Response::text(400, "Invalid export format or section"));
                            }
                            return export_loan_page(state, &session, section == "loan_history", page);
                        }
                    }
                    if section == "title_actions" && session.member.is_some() {
                        let Some(title_id) = request.query("biblio_id").and_then(|raw| raw.parse::<i64>().ok()).filter(|id| *id > 0) else {
                            return private(Response::text(400, "Invalid title ID"));
                        };
                        return render_title_actions(state, &session, title_id);
                    }
                    return render_with_target(state, &session, section, None, page, return_title);
                },
                Ok(false) => return revoke_response(cookie(request).unwrap(), &session.csrf, state),
                Err(_) => return unavailable(),
            }
        }
        let (id, session) = match state.member_sessions.issue(None) { Ok(pair) => pair, Err(_) => return unavailable() };
        return render_with_target(state, &session, "current_loan", None, 1, return_title).with_header("Set-Cookie", &set_cookie(&id, state));
    }
    if request.method != "POST" { return private(Response::text(405, "Method not allowed")); }
    let mut content_types = request.headers.iter().filter(|(name, _)| name.eq_ignore_ascii_case("content-type"));
    let content_type = content_types.next().map(|(_, value)| value.as_str()).unwrap_or("");
    if content_types.next().is_some() { return private(Response::text(400, "Ambiguous content type")); }
    let content_type = content_type.split(';').next().unwrap_or("").trim();
    if !content_type.eq_ignore_ascii_case("application/x-www-form-urlencoded") || request.body.len() > 4096 {
        return private(Response::text(400, "Invalid form"));
    }
    let Ok(raw) = std::str::from_utf8(&request.body) else { return private(Response::text(400, "Invalid form")); };
    let form = parse_query(raw);
    let Some(id) = cookie(request) else { return forbidden() };
    let mut session = match state.member_sessions.read(id) { Ok(Some(session)) => session, Ok(None) => return forbidden(), Err(_) => return unavailable() };
    let Some(csrf) = form_value(&form, "csrf_token") else { return forbidden() };
    if !constant_eq(&session.csrf, csrf) { return forbidden(); }
    let Some(action) = form_value(&form, "action") else { return private(Response::text(400, "Invalid form")) };
    if action == "logout" {
        match state.member_sessions.invalidate(id, csrf) {
            Ok(true) => (), Ok(false) => return forbidden(), Err(_) => return unavailable(),
        }
        return private(Response::redirect("/member")).with_header("Set-Cookie", &clear_cookie(state));
    }
    match refresh_member(&mut session, state) {
        Ok(true) => (),
        Ok(false) => return revoke_response(id, &session.csrf, state),
        Err(_) => return unavailable(),
    }
    if action == "logout_all" {
        return match state.member_sessions.invalidate_all(id, csrf) {
            Ok(true) => private(Response::redirect("/member")).with_header("Set-Cookie", &clear_cookie(state)),
            Ok(false) => forbidden(),
            Err(_) => unavailable(),
        };
    }
    if matches!(action, "bookmark_add" | "bookmark_remove") {
        if !state.config.member_writes_enabled { return private(Response::text(404, "Bookmark writes unavailable")); }
        if session.member.is_none() { return forbidden(); }
        let Some(title_id) = form_value(&form, "biblio_id").and_then(|s| s.parse::<i64>().ok()).filter(|id| *id > 0) else {
            return private(Response::text(400, "Invalid title ID"));
        };
        if action == "bookmark_add" {
            match state.store.detail(title_id) {
                Ok(Some(detail)) if detail.biblio.opac_hide < 1 => (),
                Ok(_) => return private(Response::text(404, "Title not available")),
                Err(_) => return unavailable(),
            }
        }
        let version = &session.member.as_ref().expect("authenticated member").credential_version;
        let result = state.member_sessions.with_authenticated(id, csrf, |member_id| {
            state.store.member_bookmark_write_bound(member_id, title_id, version, action == "bookmark_add")
        });
        return match result {
            Ok(Some(Ok(false))) => revoke_response(id, &session.csrf, state),
            Ok(Some(Err(crate::store::StoreError::NotFound))) => private(Response::text(404, "Title not available")),
            Ok(Some(Ok(true))) => private(Response::redirect("/member?sec=bookmark")),
            Ok(None) => forbidden(),
            Ok(Some(Err(crate::store::StoreError::WriteUnknown))) => write_unknown(),
            _ => unavailable(),
        };
    }
    if action == "change_password" {
        if !state.config.member_writes_enabled { return private(Response::text(404, "Password changes unavailable")); }
        let Some(member) = &session.member else { return forbidden() };
        let (Some(current), Some(new), Some(confirm)) = (form_value(&form, "current_password"), form_value(&form, "new_password"), form_value(&form, "confirm_password")) else {
            return private(Response::text(400, "Invalid form"));
        };
        if current.is_empty() || current.len() > 1024 || current.as_bytes().contains(&0) || new != confirm || !valid_new_password(new) {
            return render(state, &session, "my_account", Some("Passwords must match and contain 8–72 bytes, an uppercase letter, a number and a non-alphanumeric character."), 1);
        }
        let Some(_permit) = state.login_limiter.acquire(&member.id) else {
            return private(Response::text(429, "Too many attempts. Please try again later.")).with_header("Retry-After", "600");
        };
        let credential = match state.store.member_credential(&member.id) {
            Ok(Some(c)) if c.id == member.id && constant_eq(&credential_version(&c.password_hash), &member.credential_version) => c,
            Ok(_) => return revoke_response(id, &session.csrf, state),
            Err(_) => return unavailable(),
        };
        if !member_password::verify(current, &credential.password_hash) {
            return render(state, &session, "my_account", Some("Current password is incorrect."), 1);
        }
        let new_hash = match member_password::hash(new) { Ok(hash) => hash, Err(_) => return unavailable() };
        let result = state.member_sessions.with_authenticated(id, csrf, |member_id| {
            state.store.member_change_password(member_id, &credential.password_hash, &new_hash)
        });
        return match result {
            Ok(None) => forbidden(),
            Ok(Some(Ok(false))) => revoke_response(id, &session.csrf, state),
            Ok(Some(Ok(true))) => {
                // The hash change itself revokes other sessions on their next request.
                // Clear this cookie even if session deletion fails.
                let _ = state.member_sessions.invalidate(id, csrf);
                private(Response::html(200, "<!doctype html><html><body><p>Password changed. Sign in again.</p><a href=\"/member\">Sign in</a></body></html>"))
                    .with_header("Set-Cookie", &clear_cookie(state))
            },
            Ok(Some(Err(crate::store::StoreError::WriteUnknown))) => write_unknown(),
            _ => unavailable(),
        };
    }
    if matches!(action, "basket_add" | "basket_remove" | "basket_clear") {
        if session.member.is_none() { return forbidden(); }
        let change = if action == "basket_clear" { BasketAction::Clear } else {
            let Some(title_id) = form_value(&form, "biblio_id").and_then(|s| s.parse::<i64>().ok()).filter(|id| *id > 0) else {
                return private(Response::text(400, "Invalid title ID"));
            };
            if action == "basket_add" {
                match state.store.detail(title_id) {
                    Ok(Some(detail)) if detail.biblio.opac_hide < 1 => (),
                    Ok(_) => return private(Response::text(404, "Title not available")),
                    Err(_) => return unavailable(),
                }
                BasketAction::Add(title_id)
            } else { BasketAction::Remove(title_id) }
        };
        return match state.member_sessions.modify_basket(id, csrf, change) {
            Ok(None) => forbidden(),
            Err(_) => unavailable(),
            Ok(Some(BasketResult::Full)) => private(Response::text(400, "Basket limit reached (20 titles).")),
            Ok(Some(_)) => private(Response::redirect("/member?sec=title_basket")),
        };
    }
    if action == "basket_reserve" {
        let test = state.config.member_test_reservations;
        if !test && !(state.config.demo_reservations && state.config.is_demo()) {
            return private(Response::text(404, "Basket reservations unavailable"));
        }
        // Only the live session basket is authoritative, never submitted IDs.
        if form.iter().any(|(name, _)| matches!(name.as_str(), "biblio_id" | "basket" | "basket[]" | "memberID")) {
            return private(Response::text(400, "Submit the session basket, not title or member IDs"));
        }
        let result = state.member_sessions.with_basket_authenticated(id, csrf, |member_id, basket| {
            let deadline = std::time::Instant::now() + std::time::Duration::from_secs(15);
            reserve_basket(basket, |title| {
                if std::time::Instant::now() >= deadline || now_seconds().map_or(true, |now| now >= session.expires) {
                    return Err(crate::store::StoreError::Db("batch budget expired".into()));
                }
                let current = state.store.member_credential(member_id)?;
                let valid = current.as_ref().zip(session.member.as_ref()).map_or(false, |(current, bound)|
                    current.id == bound.id && constant_eq(&credential_version(&current.password_hash), &bound.credential_version));
                if !valid { return Err(crate::store::StoreError::Db("credential changed during batch".into())); }
                if test { state.store.test_reserve_bound(member_id, title, &session.member.as_ref().expect("validated member").credential_version) }
                else { state.store.demo_reserve(member_id, title) }
            })
        });
        return match result {
            Ok(Some((rows, uncertain))) => {
                let mut table = String::new();
                for (title, result) in rows {
                    table.push_str(&format!("<tr><td>{title}</td><td>{result}</td></tr>"));
                }
                if table.is_empty() { table.push_str("<tr><td colspan=\"2\">Basket is empty; no titles attempted.</td></tr>"); }
                let content = format!("<section class=\"section\"><h1>Basket reservation results (demo/test only)</h1><p>Processed individually, not one transaction. Some titles may succeed while others fail. The basket is unchanged. If any result is unconfirmed, check stored reservations before retrying; remaining titles were not attempted.</p><table class=\"copies\"><thead><tr><th>Title ID</th><th>Result</th></tr></thead><tbody>{table}</tbody></table><p><a href=\"/member?sec=title_basket\">Back to basket</a> · <a href=\"/member?sec={}\">Check reservations</a></p></section>", if test { "reservation_list" } else { "reservations" });
                private(Response::html(if uncertain { 503 } else { 200 }, layout(&state.config, "Basket results", "member", &content)))
            },
            Ok(None) => forbidden(),
            Err(_) => unavailable(),
        };
    }
    if action == "test_reserve" {
        if !state.config.member_test_reservations { return private(Response::text(404, "Test reservations unavailable")); }
        if session.member.is_none() { return forbidden(); }
        let Some(title_id) = form_value(&form, "biblio_id").and_then(|id| id.parse::<i64>().ok()).filter(|id| *id > 0) else {
            return private(Response::text(400, "Invalid title ID"));
        };
        let expected_version = &session.member.as_ref().expect("authenticated member").credential_version;
        let outcome = state.member_sessions.with_authenticated(id, csrf, |member_id| state.store.test_reserve_bound(member_id, title_id, expected_version));
        return match outcome {
            Ok(None) => forbidden(),
            Err(_) => unavailable(),
            Ok(Some(Err(crate::store::StoreError::WriteUnknown))) => write_unknown(),
            Ok(Some(Err(_))) => unavailable(),
            Ok(Some(Ok(DemoReserveResult::Created))) => private(Response::redirect("/member?sec=test_reservations")),
            Ok(Some(Ok(result))) => render(state, &session, "test_reservations", Some(match result {
                DemoReserveResult::AlreadyReserved => "This title is already reserved.",
                DemoReserveResult::LimitReached => "Reserve limit reached.",
                DemoReserveResult::NotAllowed => "Reservation not allowed.",
                DemoReserveResult::Unavailable => "No eligible item is available to reserve.",
                DemoReserveResult::Created => unreachable!(),
            }), 1),
        };
    }
    if action == "reserve" {
        if !state.config.demo_reservations || !state.config.is_demo() {
            return private(Response::text(404, "Reservations are unavailable"));
        }
        let Some(biblio_id) = form_value(&form, "biblio_id").and_then(|id| id.parse::<i64>().ok()).filter(|id| *id > 0) else {
            return private(Response::text(400, "Invalid title ID"));
        };
        let outcome = state.member_sessions.with_authenticated(id, csrf, |member_id| state.store.demo_reserve(member_id, biblio_id));
        return match outcome {
            Ok(None) => forbidden(),
            Err(_) | Ok(Some(Err(_))) => unavailable(),
            Ok(Some(Ok(DemoReserveResult::Created))) => private(Response::redirect("/member?sec=reservations")),
            Ok(Some(Ok(result))) => render(state, &session, "reservations", Some(match result {
                DemoReserveResult::AlreadyReserved => "This title is already reserved.",
                DemoReserveResult::LimitReached => "Reserve limit reached.",
                DemoReserveResult::NotAllowed => "Reservation not allowed.",
                DemoReserveResult::Unavailable => "No eligible item is available to reserve.",
                DemoReserveResult::Created => unreachable!(),
            }), 1),
        };
    }
    if action != "login" || session.member.is_some() { return private(Response::text(400, "Invalid form")); }
    let targets: Vec<_> = form.iter().filter(|(name, _)| name == "return_title").collect();
    let return_title = match targets.as_slice() {
        [] => None,
        [(_, raw)] => match positive_title_id(raw) {
            Some(id) => Some(id),
            None => return private(Response::text(400, "Invalid return title")),
        },
        _ => return private(Response::text(400, "Duplicate return title")),
    };
    let (Some(member_id), Some(password)) = (form_value(&form, "memberID"), form_value(&form, "memberPassWord")) else {
        return private(Response::text(400, "Invalid form"));
    };
    if member_id.is_empty() || member_id.len() > 80 || password.is_empty() || password.len() > 1024 {
        return private(Response::text(400, "Invalid form"));
    }
    let Some(_permit) = state.login_limiter.acquire(member_id) else {
        return private(Response::text(429, "Too many login attempts. Please try again later."))
            .with_header("Retry-After", "600");
    };
    let credential = match state.store.member_credential(member_id) { Ok(c) => c, Err(_) => return unavailable() };
    let hash = credential.as_ref().map(|c| c.password_hash.as_str()).unwrap_or(DUMMY_HASH);
    // Still perform bcrypt work for unknown users and legacy hashes.
    let legacy = hash.len() == 32 && hash.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b));
    let verified = member_password::verify(password, if legacy { DUMMY_HASH } else { hash });
    let mut credential = match credential { Some(c) => c, None => {
        return render_with_target(state, &session, "current_loan", Some("Member ID or password is incorrect."), 1, return_title);
    }};
    if legacy {
        let md5 = legacy_md5(password.as_bytes()).iter().map(|b| format!("{b:02x}")).collect::<String>();
        // Refuse an upgrade that would silently truncate the old password.
        if !state.config.member_writes_enabled || password.len() > 72 || password.as_bytes().contains(&0) || !constant_eq(&md5, &credential.password_hash) {
            return render_with_target(state, &session, "current_loan", Some("Member ID or password is incorrect."), 1, return_title);
        }
        let new_hash = match member_password::hash(password) { Ok(hash) => hash, Err(_) => return unavailable() };
        match state.store.member_upgrade_password(&credential.id, &credential.password_hash, &new_hash) {
            Ok(true) => credential.password_hash = new_hash,
            Ok(false) => return render_with_target(state, &session, "current_loan", Some("Member ID or password is incorrect."), 1, return_title),
            Err(crate::store::StoreError::WriteUnknown) => return write_unknown(),
            Err(_) => return unavailable(),
        }
    } else if !verified {
        return render_with_target(state, &session, "current_loan", Some("Member ID or password is incorrect."), 1, return_title);
    }
    if state.config.member_writes_enabled {
        match state.store.member_record_login(&credential.id, &credential.password_hash) {
            Ok(true) => (),
            Ok(false) => return render_with_target(state, &session, "current_loan", Some("Member ID or password is incorrect."), 1, return_title),
            Err(crate::store::StoreError::WriteUnknown) => return write_unknown(),
            Err(_) => return unavailable(),
        }
    }
    let version = credential_version(&credential.password_hash);
    match state.member_sessions.rotate(id, csrf, SessionMember { id: credential.id, name: credential.name, credential_version: version }) {
        Ok(Some(new_id)) => {
            let destination = return_title.map(|id| format!("/member?sec=title_actions&biblio_id={id}")).unwrap_or_else(|| "/member".into());
            private(Response::redirect(&destination)).with_header("Set-Cookie", &set_cookie(&new_id, state))
        },
        Ok(None) => forbidden(),
        Err(_) => unavailable(),
    }
}

fn reserve_basket(basket: &[i64], mut reserve: impl FnMut(i64) -> crate::store::StoreResult<DemoReserveResult>) -> (Vec<(i64, &'static str)>, bool) {
    let mut stopped = false;
    let mut rows = Vec::new();
    // The session store enforces the same bound; keep a second defensive cap.
    for &title in basket.iter().take(MAX_BASKET) {
        let result = if stopped { "Not attempted" } else {
            match reserve(title) {
                Ok(DemoReserveResult::Created) => "Reserved",
                Ok(DemoReserveResult::AlreadyReserved) => "Already reserved",
                Ok(DemoReserveResult::LimitReached) => "Reserve limit reached",
                Ok(DemoReserveResult::NotAllowed) => "Reservation not allowed",
                Ok(DemoReserveResult::Unavailable) => "No eligible item available",
                Err(_) => { stopped = true; "Result unconfirmed; check reservations before retrying" },
            }
        };
        rows.push((title, result));
    }
    (rows, stopped)
}

fn render_title_actions(state: &AppState, session: &Session, title_id: i64) -> Response {
    if session.member.is_none() { return forbidden(); }
    let detail = match state.store.detail(title_id) {
        Ok(Some(detail)) if detail.biblio.opac_hide < 1 => detail,
        Ok(_) => return private(Response::text(404, "Title not available")),
        Err(_) => return unavailable(),
    };
    let mut forms = String::new();
    let mut add_form = |action: &str, label: &str| {
        forms.push_str(&format!("<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"{}\"><input type=\"hidden\" name=\"biblio_id\" value=\"{}\"><button type=\"submit\">{}</button></form>", escape(&session.csrf), action, title_id, label));
    };
    add_form("basket_add", "Add to title basket");
    if state.config.member_writes_enabled { add_form("bookmark_add", "Add bookmark (test only)"); }
    if state.config.member_test_reservations {
        add_form("test_reserve", "Reserve in test database");
    } else if state.config.demo_reservations && state.config.is_demo() {
        add_form("reserve", "Reserve in demo");
    }
    let content = format!("<section class=\"section\"><h1>Member title actions</h1><h2>{}</h2><p>Review this title before submitting. Opening this page does not change your basket, bookmarks or reservations. Reservations and account writes, when enabled, are for demo/test data only.</p>{}<p><a href=\"/biblio/{}\">Back to catalog title</a> · <a href=\"/member\">Member area</a></p></section>", escape(&detail.biblio.title), forms, title_id);
    private(Response::html(200, layout(&state.config, "Member title actions", "member", &content)))
}

fn render_profile(profile: &MemberProfile) -> String {
    let mut notices = String::new();
    if profile.pending { notices.push_str("<p>Membership currently in pending state, no loan transaction can be made yet.</p>"); }
    if profile.expiry_date.as_deref().and_then(crate::date::Date::parse).map_or(false, |expiry| expiry < crate::date::Date::today()) {
        notices.push_str("<p>Your membership has expired. Please extend your membership.</p>");
    }
    let rows = [
        ("Member Name", Some(profile.name.as_str())), ("Member ID", Some(profile.id.as_str())),
        ("Member Email", profile.email.as_deref()), ("Member Type", profile.member_type.as_deref()),
        ("Last Login (stored timestamp)", profile.last_login.as_deref()),
        ("Register Date", profile.register_date.as_deref()), ("Expiry Date", profile.expiry_date.as_deref()),
        ("Institution", profile.institution.as_deref()),
    ];
    let mut output = format!("<h2>Member Detail</h2>{}<table class=\"copies\"><tbody>", notices);
    for (label, value) in rows {
        output.push_str(&format!("<tr><th scope=\"row\">{}</th><td>{}</td></tr>", label, escape(value.unwrap_or("—"))));
    }
    output.push_str("</tbody></table>");
    output
}

fn positive_title_id(raw: &str) -> Option<i64> {
    if raw.is_empty() || !raw.bytes().all(|b| b.is_ascii_digit()) { return None; }
    raw.parse::<i64>().ok().filter(|id| *id > 0)
}

fn render(state: &AppState, session: &Session, section: &str, error: Option<&str>, page_number: usize) -> Response {
    render_with_target(state, session, section, error, page_number, None)
}

fn render_with_target(state: &AppState, session: &Session, section: &str, error: Option<&str>, page_number: usize, return_title: Option<i64>) -> Response {
    let token = escape(&session.csrf);
    let content = if let Some(member) = &session.member {
        let member_id = &member.id;
        let name = &member.name;
        let reserve_nav = if state.config.demo_reservations { " · <a href=\"/member?sec=reservations\">Demo reservations</a>" } else { "" };
        let test_reserve_nav = if state.config.member_test_reservations { " · <a href=\"/member?sec=test_reservations\">Test reservations</a>" } else { "" };
        let page = if section == "reservation_list" {
            let reservations = match state.store.member_reservations(member_id) {
                Ok(rows) => rows, Err(_) => return unavailable(),
            };
            let mut rows = String::new();
            for row in &reservations {
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>",
                    row.biblio_id, escape(&row.title), escape(&row.item_code), escape(&row.reserve_date)));
            }
            let empty = if reservations.is_empty() { "<p>No stored reservations found.</p>" } else { "" };
            format!("<h2>My Stored Reservations</h2><p>Read-only. Latest 100 matching catalog records, newest reservation ID first. Missing catalog references are omitted. Demo reservations are listed separately. No cancellation or fulfillment status is available here.</p>{empty}<table class=\"copies\"><thead><tr><th>Title ID</th><th>Title</th><th>Item</th><th>Reserved At</th></tr></thead><tbody>{rows}</tbody></table>")
        } else if section == "test_reservations" && state.config.member_test_reservations {
            let reservations = match state.store.test_reservations(member_id) { Ok(rows) => rows, Err(_) => return unavailable() };
            let mut rows = String::new();
            for row in reservations {
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>", row.biblio_id, escape(&row.title), escape(&row.item_code), escape(&row.reserve_date)));
            }
            format!("<h2>Test reservations</h2><p>Disposable database only. Requires exclusive reservation writers; this is not PHP parity or production-ready. Shows the latest 100.</p><p>{}</p><table class=\"copies\"><thead><tr><th>Title ID</th><th>Title</th><th>Item</th><th>Date</th></tr></thead><tbody>{}</tbody></table><form method=\"post\" action=\"/member\"><label class=\"field\">Catalog title ID <input name=\"biblio_id\" type=\"number\" min=\"1\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"test_reserve\"><button type=\"submit\">Reserve in test database</button></form>", escape(error.unwrap_or("Use only test data.")), rows, token)
        } else if section == "reservations" && state.config.demo_reservations && state.config.is_demo() {
            let reservations = match state.store.demo_reservations(member_id) { Ok(rows) => rows, Err(_) => return unavailable() };
            let mut rows = String::new();
            for row in reservations {
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>",
                    row.biblio_id, escape(&row.title), escape(&row.item_code), escape(&row.reserve_date)));
            }
            format!("<h2>Demo reservations</h2><p>Demo only: reservations are temporary and disappear on restart. This does not reserve a real library item.</p><p>{}</p><table class=\"copies\"><thead><tr><th>Title ID</th><th>Title</th><th>Item code</th><th>Date (UTC)</th></tr></thead><tbody>{}</tbody></table><form method=\"post\" action=\"/member\"><label class=\"field\">Catalog title ID <input name=\"biblio_id\" type=\"number\" min=\"1\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"reserve\"><button type=\"submit\">Reserve in demo</button></form>",
                escape(error.unwrap_or("Find the title ID in its catalog URL, for example /biblio/8.")), rows, token)
        } else if section == "bookmark" {
            let offset = (page_number - 1) * 20; // page is range-checked by handle
            let bookmarks = match state.store.member_bookmarks(member_id, offset) { Ok(rows) => rows, Err(_) => return unavailable() };
            let mut rows = String::new();
            for bookmark in bookmarks.iter().take(20) {
                let title = if bookmark.biblio_id > 0 {
                    format!("<a href=\"/biblio/{}\">{}</a>", bookmark.biblio_id, escape(&bookmark.title))
                } else { escape(&bookmark.title) };
                let remove = if state.config.member_writes_enabled && bookmark.biblio_id > 0 {
                    format!("<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"bookmark_remove\"><input type=\"hidden\" name=\"biblio_id\" value=\"{}\"><button type=\"submit\">Remove</button></form>", token, bookmark.biblio_id)
                } else { String::new() };
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td></tr>", title, escape(&bookmark.created_at), remove));
            }
            let previous = if page_number > 1 { format!("<a href=\"/member?sec=bookmark&amp;page={}\">Previous</a> ", page_number - 1) } else { String::new() };
            let next = if bookmarks.len() > 20 && page_number < 5001 { format!("<a href=\"/member?sec=bookmark&amp;page={}\">Next</a>", page_number + 1) } else { String::new() };
            let limit_notice = if bookmarks.len() > 20 && page_number == 5001 { "<p>Navigation is limited to the first 100,020 bookmarks.</p>" } else { "" };
            let add = if state.config.member_writes_enabled {
                format!("<p>Test writes only: use a disposable database.</p><form method=\"post\" action=\"/member\"><label class=\"field\">Catalog title ID <input name=\"biblio_id\" type=\"number\" min=\"1\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"bookmark_add\"><button type=\"submit\">Add bookmark</button></form>", token)
            } else { "<p>Read-only: adding or deleting bookmarks is disabled.</p>".into() };
            format!("<h2>My Title Bookmark</h2>{}<table class=\"copies\"><thead><tr><th>Title</th><th>Marked At</th><th>Action</th></tr></thead><tbody>{}</tbody></table><p>Page {} &nbsp; {}{}</p>{}", add, rows, page_number, previous, next, limit_notice)
        } else if section == "title_basket" {
            let mut rows = String::new();
            for title_id in &session.basket {
                let title = match state.store.detail(*title_id) {
                    Ok(Some(detail)) if detail.biblio.opac_hide < 1 => format!("<a href=\"/biblio/{}\">{}</a>", title_id, escape(&detail.biblio.title)),
                    Ok(_) => "Title no longer available".to_string(),
                    Err(_) => return unavailable(),
                };
                let reservation_action = if state.config.member_test_reservations {
                    Some(("test_reserve", "Reserve in test database"))
                } else if state.config.demo_reservations && state.config.is_demo() {
                    Some(("reserve", "Reserve in demo"))
                } else { None };
                let reserve = reservation_action.map(|(action, label)| format!(
                    "<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"{}\"><input type=\"hidden\" name=\"biblio_id\" value=\"{}\"><button type=\"submit\">{}</button></form>",
                    token, action, title_id, label)).unwrap_or_default();
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td><form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"basket_remove\"><input type=\"hidden\" name=\"biblio_id\" value=\"{}\"><button type=\"submit\">Remove</button></form>{}</td></tr>", title_id, title, token, title_id, reserve));
            }
            let reserve_all = if !session.basket.is_empty() && (state.config.member_test_reservations ||
                (state.config.demo_reservations && state.config.is_demo())) {
                format!("<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{token}\"><input type=\"hidden\" name=\"action\" value=\"basket_reserve\"><button type=\"submit\">Reserve basket (demo/test only)</button></form><p>Individual results; not atomic. Basket is kept unchanged.</p>")
            } else { String::new() };
            format!("<h2>Title Basket</h2>{reserve_all}<p>{} of {} titles. This basket belongs to this login session and is cleared on sign out or expiry. Adding to the basket does not reserve titles. Reservation buttons, when enabled, submit one title at a time and keep the basket unchanged; inspect the reservation result before retrying.</p><table class=\"copies\"><thead><tr><th>ID</th><th>Title</th><th>Action</th></tr></thead><tbody>{}</tbody></table><form method=\"post\" action=\"/member\"><label class=\"field\">Catalog title ID <input name=\"biblio_id\" type=\"number\" min=\"1\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"basket_add\"><button type=\"submit\">Add title</button></form><form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"basket_clear\"><button type=\"submit\">Clear basket</button></form>", session.basket.len(), MAX_BASKET, rows, token, token)
        } else if section == "my_account" {
            let profile = match state.store.member_profile(member_id) {
                Ok(Some(profile)) if profile.id == *member_id => profile,
                _ => return unavailable(),
            };
            let mut page = render_profile(&profile);
            page.push_str(&format!("<h2>Session security</h2><p>Sign out of all Rust member sessions in this session store, including this one. PHP sessions and other session stores are not affected. A sign-in completing after this action can create a new session.</p><form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"logout_all\"><button type=\"submit\">Sign out of all Rust sessions</button></form>", token));
            if state.config.member_writes_enabled {
                page.push_str(&format!("<h2>Change password (test only)</h2><p>{}</p><form method=\"post\" action=\"/member\"><label class=\"field\">Current password <input name=\"current_password\" type=\"password\" autocomplete=\"current-password\" required></label><label class=\"field\">New password <input name=\"new_password\" type=\"password\" autocomplete=\"new-password\" required></label><label class=\"field\">Confirm new password <input name=\"confirm_password\" type=\"password\" autocomplete=\"new-password\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"change_password\"><button type=\"submit\">Change test password</button></form>", escape(error.unwrap_or("Use a disposable test account only.")), token));
            }
            page
        } else if section == "current_loan" || section == "loan_history" {
            let returned = section == "loan_history";
            let loans = match state.store.member_loans_page(member_id, returned, (page_number - 1) * 20) { Ok(loans) => loans, Err(_) => return unavailable() };
            let mut rows = String::new();
            let today = crate::date::Date::today();
            for loan in loans.iter().take(20) {
                let last_date = if returned { &loan.return_date } else { &loan.due_date };
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>",
                    escape(&loan.item_code), escape(loan.title.as_deref().unwrap_or("—")),
                    escape(loan.loan_date.as_deref().unwrap_or("—")), render_loan_date(last_date.as_deref(), returned, today)));
            }
            let previous = if page_number > 1 { format!("<a href=\"/member?sec={}&amp;page={}\">Previous</a> ", section, page_number - 1) } else { String::new() };
            let next = if loans.len() > 20 && page_number < 5001 { format!("<a href=\"/member?sec={}&amp;page={}\">Next</a>", section, page_number + 1) } else { String::new() };
            let report_scope = if returned { "history" } else { "current" };
            let download = format!("<p><a href=\"/member?sec={}&amp;page={}&amp;format=csv\">Download this page (CSV)</a> — up to 20 loans. <a href=\"/member?sec=loan_report&amp;scope={report_scope}&amp;format=html\">Download complete section (HTML, subject to report limits)</a> · <a href=\"/member?sec=loan_report\">Combined printable report / CSV</a>.</p>", section, page_number);
            let limit = if loans.len() > 20 && page_number == 5001 { "<p>Navigation is limited to the first 100,020 loans.</p>" } else { "" };
            format!("<h2>{}</h2><table class=\"copies\"><thead><tr><th>Item Code</th><th>Title</th><th>Loan Date</th><th>{}</th></tr></thead><tbody>{}</tbody></table><p>Page {} &nbsp; {}{}</p>{download}{}",
                if returned { "My Loan History" } else { "My Current Loan (dates and lateness in UTC; not a fine calculation)" }, if returned { "Return Date" } else { "Due Date" }, rows, page_number, previous, next, limit)
        } else {
            return private(Response::text(404, "Member section not implemented"));
        };
        format!("<section class=\"section\"><h1>Hi, {}</h1><p><a href=\"/member\">Current Loan</a> · <a href=\"/member?sec=loan_history\">Loan History</a> · <a href=\"/member?sec=my_account\">My Account</a> · <a href=\"/member?sec=title_basket\">Title Basket</a> · <a href=\"/member?sec=bookmark\">Title Bookmark</a> · <a href=\"/member?sec=reservation_list\">Stored Reservations</a> · <a href=\"/member?sec=loan_report\">Loan Report</a>{}{}</p>{}<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"logout\"><button type=\"submit\">Sign out</button></form>{}</section>",
            escape(name), reserve_nav, test_reserve_nav, page, token,
            if state.config.member_writes_enabled { "<p>Account writes are enabled for test data only.</p>" } else { "<p>Account changes are not available yet.</p>" })
    } else {
        let return_field = return_title.map(|id| format!("<input type=\"hidden\" name=\"return_title\" value=\"{id}\"><p>After sign-in you will return to title {id} for confirmation. No title action is submitted automatically.</p>")).unwrap_or_default();
        format!("<section class=\"section\"><h1>Library Member Login</h1><p>{}</p><form method=\"post\" action=\"/member\"><label class=\"field\">Member ID <input name=\"memberID\" required autocomplete=\"username\"></label><label class=\"field\">Password <input name=\"memberPassWord\" type=\"password\" required autocomplete=\"current-password\"></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"login\">{return_field}<button type=\"submit\">Login</button></form></section>",
            escape(error.unwrap_or("Enter the member ID and password supplied by your library.")), token)
    };
    private(Response::html(200, layout(&state.config, "Member area", "member", &content)))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;

    #[test]
    fn sessions_expire_and_only_one_concurrent_rotation_wins() {
        let sessions = Arc::new(Sessions::new(None).unwrap());
        let (id, session) = sessions.issue(None).unwrap();
        let threads: Vec<_> = (0..8).map(|_| {
            let sessions = sessions.clone();
            let id = id.clone();
            let csrf = session.csrf.clone();
            std::thread::spawn(move || sessions.rotate(&id, &csrf, SessionMember { id: "M001".into(), name: "Name".into(), credential_version: credential_version("example") }).unwrap())
        }).collect();
        let ids: Vec<_> = threads.into_iter().filter_map(|t| t.join().unwrap()).collect();
        assert_eq!(ids.len(), 1);
        assert!(sessions.read(&id).unwrap().is_none());
        sessions.lock().get_mut(&ids[0]).unwrap().expires = now_seconds().unwrap();
        assert!(sessions.read(&ids[0]).unwrap().is_none());
    }

    #[test]
    fn basket_batch_stops_on_unknown_without_retry_or_processing_remaining_titles() {
        let mut attempted = Vec::new();
        let (rows, uncertain) = reserve_basket(&[2, 4, 6, 8], |id| {
            attempted.push(id);
            if id == 4 { Err(crate::store::StoreError::WriteUnknown) }
            else { Ok(DemoReserveResult::Created) }
        });
        assert!(uncertain);
        assert_eq!(attempted, vec![2, 4]);
        assert_eq!(rows[0].1, "Reserved");
        assert!(rows[1].1.contains("unconfirmed"));
        assert_eq!(rows[2].1, "Not attempted");
        assert_eq!(rows[3].1, "Not attempted");
        let (empty, uncertain) = reserve_basket(&[], |_| panic!("empty basket"));
        assert!(empty.is_empty() && !uncertain);
        let (rows, uncertain) = reserve_basket(&(1..=25).collect::<Vec<_>>(), |_| Ok(DemoReserveResult::LimitReached));
        assert_eq!(rows.len(), MAX_BASKET);
        assert!(!uncertain);
    }

    #[test]
    fn logout_all_requires_live_authenticated_session() {
        let sessions = Sessions::new(None).unwrap();
        let (anon, s) = sessions.issue(None).unwrap();
        assert!(!sessions.invalidate_all(&anon, &s.csrf).unwrap());
        let member = || Some(SessionMember { id: "M001".into(), name: "Name".into(), credential_version: credential_version("hash") });
        let (a, sa) = sessions.issue(member()).unwrap();
        let (b, sb) = sessions.issue(member()).unwrap();
        sessions.lock().get_mut(&a).unwrap().expires = 0;
        assert!(!sessions.invalidate_all(&a, &sa.csrf).unwrap());
        assert!(sessions.read(&b).unwrap().is_some());
        assert!(!sessions.invalidate_all(&b, "bad").unwrap());
        assert!(sessions.invalidate_all(&b, &sb.csrf).unwrap());
        assert!(sessions.read(&b).unwrap().is_none());
        assert!(sessions.read(&anon).unwrap().is_some());
    }

    #[test]
    fn basket_bound_duplicate_and_removal() {
        let mut basket = Vec::new();
        assert_eq!(apply_basket(&mut basket, BasketAction::Add(0)), BasketResult::Unchanged);
        for id in 1..=MAX_BASKET as i64 { assert_eq!(apply_basket(&mut basket, BasketAction::Add(id)), BasketResult::Changed); }
        assert_eq!(apply_basket(&mut basket, BasketAction::Add(1)), BasketResult::Unchanged);
        assert_eq!(apply_basket(&mut basket, BasketAction::Add(21)), BasketResult::Full);
        assert_eq!(apply_basket(&mut basket, BasketAction::Remove(1)), BasketResult::Changed);
        assert_eq!(apply_basket(&mut basket, BasketAction::Add(21)), BasketResult::Changed);
        assert_eq!(apply_basket(&mut basket, BasketAction::Clear), BasketResult::Changed);
        assert_eq!(apply_basket(&mut basket, BasketAction::Clear), BasketResult::Unchanged);
    }

    #[test]
    fn profile_escapes_member_data_and_shows_status() {
        let profile = MemberProfile {
            id: "M<x>".into(), name: "<script>alert(1)</script>".into(),
            email: Some("x&y@example.org".into()), pending: true,
            expiry_date: Some("2000-01-01".into()), ..MemberProfile::default()
        };
        let html = render_profile(&profile);
        assert!(html.contains("&lt;script&gt;"));
        assert!(html.contains("x&amp;y@example.org"));
        assert!(!html.contains("<script>"));
        assert!(html.contains("pending state"));
        assert!(html.contains("membership has expired"));
    }

    #[test]
    fn csv_cells_quote_delimiters_and_neutralize_formula_prefixes() {
        assert_eq!(csv_cell("Title, \"quoted\"\r\nnext"), "\"Title, \"\"quoted\"\"\r\nnext\"");
        assert_eq!(csv_cell(""), "\"\"");
        assert_eq!(csv_cell("Judul 日本語"), "\"Judul 日本語\"");
        for value in ["=1+1", "+SUM(A1)", "-2+1", "@cmd", "  =1", "\ttext", "\r=1", "\n=1", "\u{feff}=1"] {
            assert!(csv_cell(value).starts_with("\"'"), "{value:?}");
        }
    }

    #[test]
    fn overdue_display_uses_calendar_days_and_escapes_untrusted_dates() {
        use crate::date::Date;
        let today = Date::new(2026, 3, 1);
        assert!(render_loan_date(Some("2026-02-28"), false, today).contains("Overdue: 1 calendar day</strong>"));
        assert!(render_loan_date(Some("2026-02-27"), false, today).contains("Overdue: 2 calendar days"));
        assert!(render_loan_date(Some("2024-02-29"), false, Date::new(2024, 3, 1)).contains("Overdue: 1"));
        for raw in [None, Some("2026-03-01"), Some("2026-03-02"), Some("2025-02-30"),
            Some("0000-00-00"), Some("2026-02-28<script>"), Some("2026-02-28 00:00:00")] {
            let html = render_loan_date(raw, false, today);
            assert!(!html.contains("Overdue"), "{raw:?}");
            assert!(!html.contains("<script>"));
        }
        assert_eq!(render_loan_date(Some("2020-01-01"), true, today), "2020-01-01");
        assert_eq!(render_loan_date(None, false, today), "—");
    }

    #[test]
    fn cookie_validation_and_token_comparison() {
        assert!(!constant_eq("", "x"));
        assert!(!constant_eq("abc", "abd"));
        assert!(constant_eq("abc", "abc"));
        let mut request = Request::new("GET", "/member");
        request.headers.push(("Cookie".into(), format!("{}={}", COOKIE, "a".repeat(64))));
        assert!(cookie(&request).is_some());
        request.headers[0].1.push_str(&format!("; {}={}", COOKIE, "b".repeat(64)));
        assert!(cookie(&request).is_none());
        request.headers[0].1 = "theme=dark".into();
        request.headers.push(("cOoKiE".into(), format!("{}={}", COOKIE, "a".repeat(64))));
        assert!(cookie(&request).is_some());
        request.headers.push(("Cookie".into(), format!("{}={}", COOKIE, "a".repeat(64))));
        assert!(cookie(&request).is_none());
    }
}
