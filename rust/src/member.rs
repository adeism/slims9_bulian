//! Experimental, opt-in member login and loan views. Not production-ready.
//! No writes to SLiMS occur. Sessions may use the experimental local SQLite store.
use std::collections::HashMap;
use std::io::Read;
use std::sync::Mutex;
use std::time::{SystemTime, UNIX_EPOCH};
use std::path::Path;

use crate::app::AppState;
use crate::crypto::{member_password, sha256};
use crate::http::{parse_query, Request, Response};
use crate::models::{DemoReserveResult, MemberProfile};
use crate::templates::{escape, layout};

const COOKIE: &str = "slims_rust_member";
pub(crate) const LIFETIME_SECONDS: u64 = 1800;
pub(crate) const MAX_SESSIONS: usize = 10_000;
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
        let session = Session { csrf: random_token()?, member, expires: now.saturating_add(LIFETIME_SECONDS) };
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
        map.insert(id.clone(), Session { csrf: new_csrf, member: Some(member), expires: now.saturating_add(LIFETIME_SECONDS) });
        Ok(Some(id))
    }
    /// Serialize the in-memory demo mutation against logout. Never perform a
    /// network/database operation in this closure.
    fn with_authenticated<T>(&self, id: &str, csrf: &str, f: impl FnOnce(&str) -> T) -> std::io::Result<Option<T>> {
        #[cfg(feature = "member-sqlite")]
        if let Some(disk) = &self.disk { return disk.with_authenticated(id, csrf, f); }
        let now = now_seconds()?;
        let map = self.lock();
        let Some(session) = map.get(id) else { return Ok(None) };
        if session.expires <= now || !constant_eq(&session.csrf, csrf) { return Ok(None); }
        let Some(member) = session.member.as_ref() else { return Ok(None) };
        Ok(Some(f(&member.id)))
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
    let header = request.header("cookie")?;
    let mut matches = header.split(';').filter_map(|part| part.trim().split_once('='))
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
        .with_header("Pragma", "no-cache")
        .with_header("Content-Security-Policy", "default-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'")
}
fn unavailable() -> Response { private(Response::text(503, "Member service unavailable")) }
fn forbidden() -> Response { private(Response::text(403, "Invalid or expired session or CSRF token")) }
fn form_value<'a>(form: &'a [(String, String)], key: &str) -> Option<&'a str> {
    let mut values = form.iter().filter(|(name, _)| name == key).map(|(_, value)| value.as_str());
    let value = values.next()?;
    if values.next().is_some() { None } else { Some(value) }
}

pub fn handle(request: &Request, state: &AppState) -> Response {
    if request.method == "GET" || request.method == "HEAD" {
        // State-changing GET logout is intentionally not supported.
        if request.query("logout").is_some() { return private(Response::text(405, "Use POST to sign out")); }
        let current = match cookie(request) {
            Some(id) => match state.member_sessions.read(id) { Ok(session) => session, Err(_) => return unavailable() },
            None => None,
        };
        if let Some(mut session) = current {
            match refresh_member(&mut session, state) {
                Ok(true) => return render(state, &session, request.query_or("sec", "current_loan"), None),
                Ok(false) => return revoke_response(cookie(request).unwrap(), &session.csrf, state),
                Err(_) => return unavailable(),
            }
        }
        let (id, session) = match state.member_sessions.issue(None) { Ok(pair) => pair, Err(_) => return unavailable() };
        return render(state, &session, "current_loan", None).with_header("Set-Cookie", &set_cookie(&id, state));
    }
    if request.method != "POST" { return private(Response::text(405, "Method not allowed")); }
    let content_type = request.header("content-type").unwrap_or("").split(';').next().unwrap_or("").trim();
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
            })),
        };
    }
    if action != "login" || session.member.is_some() { return private(Response::text(400, "Invalid form")); }
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
    let verified = member_password::verify(password, hash);
    if !verified || credential.is_none() {
        return render(state, &session, "current_loan", Some("Member ID or password is incorrect."));
    }
    let credential = credential.unwrap();
    let version = credential_version(&credential.password_hash);
    match state.member_sessions.rotate(id, csrf, SessionMember { id: credential.id, name: credential.name, credential_version: version }) {
        Ok(Some(new_id)) => private(Response::redirect("/member")).with_header("Set-Cookie", &set_cookie(&new_id, state)),
        Ok(None) => forbidden(),
        Err(_) => unavailable(),
    }
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
        ("Register Date", profile.register_date.as_deref()), ("Expiry Date", profile.expiry_date.as_deref()),
        ("Institution", profile.institution.as_deref()),
    ];
    let mut output = format!("<h2>Member Detail</h2>{}<table class=\"copies\"><tbody>", notices);
    for (label, value) in rows {
        output.push_str(&format!("<tr><th scope=\"row\">{}</th><td>{}</td></tr>", label, escape(value.unwrap_or("—"))));
    }
    output.push_str("</tbody></table><p>Changing your password is not available yet.</p>");
    output
}

fn render(state: &AppState, session: &Session, section: &str, error: Option<&str>) -> Response {
    let token = escape(&session.csrf);
    let content = if let Some(member) = &session.member {
        let member_id = &member.id;
        let name = &member.name;
        let reserve_nav = if state.config.demo_reservations { " · <a href=\"/member?sec=reservations\">Demo reservations</a>" } else { "" };
        let page = if section == "reservations" && state.config.demo_reservations && state.config.is_demo() {
            let reservations = match state.store.demo_reservations(member_id) { Ok(rows) => rows, Err(_) => return unavailable() };
            let mut rows = String::new();
            for row in reservations {
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>",
                    row.biblio_id, escape(&row.title), escape(&row.item_code), escape(&row.reserve_date)));
            }
            format!("<h2>Demo reservations</h2><p>Demo only: reservations are temporary and disappear on restart. This does not reserve a real library item.</p><p>{}</p><table class=\"copies\"><thead><tr><th>Title ID</th><th>Title</th><th>Item code</th><th>Date (UTC)</th></tr></thead><tbody>{}</tbody></table><form method=\"post\" action=\"/member\"><label class=\"field\">Catalog title ID <input name=\"biblio_id\" type=\"number\" min=\"1\" required></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"reserve\"><button type=\"submit\">Reserve in demo</button></form>",
                escape(error.unwrap_or("Find the title ID in its catalog URL, for example /biblio/8.")), rows, token)
        } else if section == "my_account" {
            let profile = match state.store.member_profile(member_id) {
                Ok(Some(profile)) if profile.id == *member_id => profile,
                _ => return unavailable(),
            };
            render_profile(&profile)
        } else if section == "current_loan" || section == "loan_history" {
            let returned = section == "loan_history";
            let loans = match state.store.member_loans(member_id, returned) { Ok(loans) => loans, Err(_) => return unavailable() };
            let mut rows = String::new();
            for loan in loans {
                let last_date = if returned { loan.return_date } else { loan.due_date };
                rows.push_str(&format!("<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>",
                    escape(&loan.item_code), escape(loan.title.as_deref().unwrap_or("—")),
                    escape(loan.loan_date.as_deref().unwrap_or("—")), escape(last_date.as_deref().unwrap_or("—"))));
            }
            format!("<h2>{}</h2><table class=\"copies\"><thead><tr><th>Item Code</th><th>Title</th><th>Loan Date</th><th>{}</th></tr></thead><tbody>{}</tbody></table>",
                if returned { "My Loan History" } else { "My Current Loan" }, if returned { "Return Date" } else { "Due Date" }, rows)
        } else {
            return private(Response::text(404, "Member section not implemented"));
        };
        format!("<section class=\"section\"><h1>Hi, {}</h1><p><a href=\"/member\">Current Loan</a> · <a href=\"/member?sec=loan_history\">Loan History</a> · <a href=\"/member?sec=my_account\">My Account</a>{}</p>{}<form method=\"post\" action=\"/member\"><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"logout\"><button type=\"submit\">Sign out</button></form><p>Account changes are not available yet.</p></section>",
            escape(name), reserve_nav, page, token)
    } else {
        format!("<section class=\"section\"><h1>Library Member Login</h1><p>{}</p><form method=\"post\" action=\"/member\"><label class=\"field\">Member ID <input name=\"memberID\" required autocomplete=\"username\"></label><label class=\"field\">Password <input name=\"memberPassWord\" type=\"password\" required autocomplete=\"current-password\"></label><input type=\"hidden\" name=\"csrf_token\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"login\"><button type=\"submit\">Login</button></form></section>",
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
    fn cookie_validation_and_token_comparison() {
        assert!(!constant_eq("", "x"));
        assert!(!constant_eq("abc", "abd"));
        assert!(constant_eq("abc", "abc"));
        let mut request = Request::new("GET", "/member");
        request.headers.push(("Cookie".into(), format!("{}={}", COOKIE, "a".repeat(64))));
        assert!(cookie(&request).is_some());
        request.headers[0].1.push_str(&format!("; {}={}", COOKIE, "b".repeat(64)));
        assert!(cookie(&request).is_none());
    }
}
