#![cfg(feature = "member-crypt")]
use slims9::app::AppState;
use slims9::config::Config;
use slims9::http::{url_encode, Request, Response};

const HASH: &str = "$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO";
fn state() -> AppState {
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_member_hash = Some(HASH.into());
    AppState::new(config).unwrap()
}
fn header<'a>(res: &'a Response, name: &str) -> &'a str {
    &res.headers.iter().find(|(key, _)| key.eq_ignore_ascii_case(name)).expect(name).1
}
fn body(res: &Response) -> String { String::from_utf8(res.body.clone()).unwrap() }
fn csrf(res: &Response) -> String {
    let text = body(res);
    text.split("name=\"csrf_token\" value=\"").nth(1).unwrap().split('"').next().unwrap().into()
}
fn cookie(res: &Response) -> String { header(res, "Set-Cookie").split(';').next().unwrap().into() }
fn get(state: &AppState, path: &str, c: Option<&str>) -> Response {
    let mut req = Request::new("GET", path);
    if let Some(c) = c { req.headers.push(("Cookie".into(), c.into())); }
    state.handle(&req)
}
fn post(state: &AppState, c: &str, payload: &str) -> Response {
    let mut req = Request::new("POST", "/index.php?p=member");
    req.headers.push(("Cookie".into(), c.into()));
    req.headers.push(("Content-Type".into(), "application/x-www-form-urlencoded".into()));
    req.body = payload.as_bytes().into();
    state.handle(&req)
}
fn login_payload(token: &str, id: &str, password: &str) -> String {
    format!("action=login&csrf_token={}&memberID={}&memberPassWord={}", token, url_encode(id), url_encode(password))
}

#[test]
fn login_history_logout_and_session_rotation() {
    let state = state();
    let page = get(&state, "/member", None);
    assert_eq!(page.status, 200);
    assert!(header(&page, "Set-Cookie").contains("HttpOnly; SameSite=Lax"));
    assert!(header(&page, "Set-Cookie").contains("Secure"));
    assert!(header(&page, "Cache-Control").contains("no-store"));
    let anon_cookie = cookie(&page);
    let anon_token = csrf(&page);
    assert_eq!(post(&state, &anon_cookie, &login_payload("bad", "M001", "siti rahma")).status, 403);
    let wrong = post(&state, &anon_cookie, &login_payload(&anon_token, "M001", "bad"));
    assert!(body(&wrong).contains("incorrect"));
    let ok = post(&state, &anon_cookie, &login_payload(&anon_token, "M001", "siti rahma"));
    assert_eq!(ok.status, 302);
    let auth_cookie = cookie(&ok);
    assert_ne!(anon_cookie, auth_cookie);
    assert_eq!(post(&state, &anon_cookie, &login_payload(&anon_token, "M001", "siti rahma")).status, 403);
    let current = get(&state, "/member", Some(&auth_cookie));
    assert!(body(&current).contains("Hi, Siti Rahma"));
    assert!(body(&current).contains("My Current Loan"));
    let profile = get(&state, "/member?sec=my_account", Some(&auth_cookie));
    assert!(body(&profile).contains("Member Detail"));
    assert!(body(&profile).contains("siti.rahma@example.org"));
    assert!(!body(&profile).contains("budi.santoso@example.org"));
    assert!(header(&profile, "Cache-Control").contains("no-store"));
    let anonymous_profile = get(&state, "/member?sec=my_account", None);
    assert!(!body(&anonymous_profile).contains("siti.rahma@example.org"));
    let history = get(&state, "/index.php?p=member&sec=loan_history", Some(&auth_cookie));
    assert!(body(&history).contains("My Loan History"));
    assert!(!body(&history).contains(HASH));
    assert_eq!(get(&state, "/member?logout=1", Some(&auth_cookie)).status, 405);
    assert_eq!(post(&state, &auth_cookie, "action=logout&csrf_token=wrong").status, 403);
    let logout = post(&state, &auth_cookie, &format!("action=logout&csrf_token={}", csrf(&current)));
    assert_eq!(logout.status, 302);
    assert!(header(&logout, "Set-Cookie").contains("Max-Age=0"));
    assert!(body(&get(&state, "/member", Some(&auth_cookie))).contains("Library Member Login"));
}

#[test]
fn no_default_demo_password_and_malformed_requests_fail_closed() {
    let mut config = Config::default();
    config.member_enabled = true;
    let state = AppState::new(config).unwrap();
    let page = get(&state, "/member", None);
    let c = cookie(&page);
    let token = csrf(&page);
    let wrong = post(&state, &c, &login_payload(&token, "M001", "siti rahma"));
    assert!(body(&wrong).contains("incorrect"));
    assert_eq!(post(&state, &c, &format!("action=logout&csrf_token={}&csrf_token={}", token, token)).status, 403);
    let duplicate = format!("{}; {}", c, c);
    assert_eq!(post(&state, &duplicate, &format!("action=logout&csrf_token={}", token)).status, 403);
    let mut req = Request::new("POST", "/member");
    req.headers.push(("Cookie".into(), c));
    req.headers.push(("Content-Type".into(), "text/plain".into()));
    req.body = format!("action=logout&csrf_token={}", token).into_bytes();
    assert_eq!(state.handle(&req).status, 400);
}

#[test]
fn login_throttle_persists_across_sessions() {
    let state = state();
    for _ in 0..8 {
        let page = get(&state, "/member", None);
        assert_eq!(post(&state, &cookie(&page), &login_payload(&csrf(&page), "M001", "wrong")).status, 200);
    }
    let page = get(&state, "/member", None);
    let blocked = post(&state, &cookie(&page), &login_payload(&csrf(&page), "m001 ", "wrong"));
    assert_eq!(blocked.status, 429);
    assert_eq!(header(&blocked, "Retry-After"), "600");
    assert!(String::from_utf8(blocked.serialize(false)).unwrap().starts_with("HTTP/1.1 429 Too Many Requests"));
}

#[test]
fn demo_reservation_requires_auth_csrf_and_never_fakes_mysql_writes() {
    use slims9::config::{DbConfig, MySqlUrl};
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_reservations = true;
    config.demo_member_hash = Some(HASH.into());
    let state = AppState::new(config.clone()).unwrap();
    let page = get(&state, "/member", None);
    let anon = cookie(&page);
    let token = csrf(&page);
    assert_eq!(post(&state, &anon, &format!("action=reserve&csrf_token={}&biblio_id=2", token)).status, 403);
    let login = post(&state, &anon, &login_payload(&token, "M001", "siti rahma"));
    let c = cookie(&login);
    let page = get(&state, "/member?sec=reservations", Some(&c));
    assert!(body(&page).contains("does not reserve a real library item"));
    let token = csrf(&page);
    assert_eq!(post(&state, &c, "action=reserve&csrf_token=wrong&biblio_id=2").status, 403);
    assert_eq!(post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=2", token)).status, 302);
    let page = get(&state, "/member?sec=reservations", Some(&c));
    assert!(body(&page).contains("Linux In a Nutshell"));
    let duplicate = post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=2", token));
    assert!(body(&duplicate).contains("already reserved"));
    assert_eq!(post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=4", token)).status, 302);
    let over = post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=6", token));
    assert!(body(&over).contains("Reserve limit reached"));
    let logout = post(&state, &c, &format!("action=logout&csrf_token={}", token));
    assert_eq!(logout.status, 302);
    assert_eq!(post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=6", token)).status, 403);
    config.db = DbConfig::MySql(MySqlUrl::parse("mysql://slims:secret@127.0.0.1:3307/senayan").unwrap());
    assert!(AppState::new(config).is_err());
    let mut invalid_db = Config::default();
    invalid_db.member_enabled = true;
    invalid_db.db_configuration_error = Some("invalid URL".into());
    assert!(AppState::new(invalid_db).is_err());
    let mut off = Config::default();
    off.demo_reservations = true;
    assert!(AppState::new(off).is_err());
}

#[cfg(feature = "member-sqlite")]
#[test]
fn persistent_sessions_survive_state_restart_and_share_logout() {
    use std::os::unix::fs::PermissionsExt;
    use std::time::{SystemTime, UNIX_EPOCH};
    let unique = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos();
    let dir = std::env::temp_dir().join(format!("slims-flow-{}-{}", std::process::id(), unique));
    std::fs::create_dir(&dir).unwrap();
    std::fs::set_permissions(&dir, std::fs::Permissions::from_mode(0o700)).unwrap();
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_member_hash = Some(HASH.into());
    config.member_session_db = Some(dir.join("sessions.sqlite"));
    let first = AppState::new(config.clone()).unwrap();
    let anonymous = get(&first, "/member", None);
    let login = post(&first, &cookie(&anonymous), &login_payload(&csrf(&anonymous), "M001", "siti rahma"));
    assert_eq!(login.status, 302);
    let c = cookie(&login);
    drop(first);
    let second = AppState::new(config.clone()).unwrap();
    let page = get(&second, "/member", Some(&c));
    assert!(body(&page).contains("Hi, Siti Rahma"));
    let third = AppState::new(config).unwrap();
    assert!(body(&get(&third, "/member", Some(&c))).contains("Hi, Siti Rahma"));
    let logout = post(&third, &c, &format!("action=logout&csrf_token={}", csrf(&page)));
    assert_eq!(logout.status, 302);
    assert!(body(&get(&second, "/member", Some(&c))).contains("Library Member Login"));
    drop(second);
    drop(third);
    std::fs::remove_dir_all(dir).unwrap();
}

#[cfg(feature = "member-sqlite")]
#[test]
fn session_store_failure_returns_503_not_a_new_session() {
    use std::os::unix::fs::PermissionsExt;
    use std::time::{SystemTime, UNIX_EPOCH};
    let unique = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos();
    let dir = std::env::temp_dir().join(format!("slims-failure-{}-{}", std::process::id(), unique));
    std::fs::create_dir(&dir).unwrap();
    std::fs::set_permissions(&dir, std::fs::Permissions::from_mode(0o700)).unwrap();
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_session_db = Some(dir.join("sessions.sqlite"));
    let state = AppState::new(config).unwrap();
    let page = get(&state, "/member", None);
    let c = cookie(&page);
    std::fs::remove_file(dir.join("sessions.sqlite")).unwrap();
    let response = get(&state, "/member", Some(&c));
    assert_eq!(response.status, 503);
    assert!(!response.headers.iter().any(|(k, _)| k == "Set-Cookie"));
    drop(state);
    std::fs::remove_dir_all(dir).unwrap();
}

#[cfg(not(feature = "member-sqlite"))]
#[test]
fn persistent_session_configuration_requires_feature() {
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_session_db = Some("/tmp/sessions.sqlite".into());
    assert!(AppState::new(config).is_err());
}

#[test]
fn session_is_revoked_after_credential_change_or_removal() {
    use slims9::store::memory::MemoryStore;
    use slims9::store::Store;
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_reservations = true;
    let mut state = AppState::new(config.clone()).unwrap();
    let mut store = MemoryStore::load(&config.root, true);
    store.enable_demo_reservations();
    store.set_demo_member_password("M001", HASH);
    state.store = Box::new(store.clone());

    let first = get(&state, "/member", None);
    let login = post(&state, &cookie(&first), &login_payload(&csrf(&first), "M001", "siti rahma"));
    assert_eq!(login.status, 302);
    let c = cookie(&login);
    let page = get(&state, "/member?sec=reservations", Some(&c));
    let token = csrf(&page);
    assert!(body(&page).contains("Siti Rahma"));
    store.set_demo_member_password("M001", "$2b$04$kfEuXqkxREGoRdFNFMfJFO7ARi6uiBzBLQJ7DMGAWdAPhdoPq9V2.");
    let denied = post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=2", token));
    assert_eq!(denied.status, 302);
    assert!(header(&denied, "Set-Cookie").contains("Max-Age=0"));
    assert_eq!(post(&state, &c, &format!("action=reserve&csrf_token={}&biblio_id=2", token)).status, 403);
    assert!(store.demo_reservations("M001").unwrap().is_empty());

    let first = get(&state, "/member", None);
    let login = post(&state, &cookie(&first), &login_payload(&csrf(&first), "M001", "secret"));
    assert_eq!(login.status, 302);
    let c = cookie(&login);
    store.set_demo_member_password("M001", ""); // simulates a deleted credential
    let denied = get(&state, "/member", Some(&c));
    assert_eq!(denied.status, 302);
    assert!(header(&denied, "Set-Cookie").contains("Max-Age=0"));
    assert!(body(&get(&state, "/member", Some(&c))).contains("Library Member Login"));
}

#[test]
fn credential_lookup_failure_fails_closed_without_destroying_session() {
    use slims9::config::MySqlUrl;
    use slims9::store::memory::MemoryStore;
    use slims9::store::mysql::MySqlStore;
    let mut config = Config::default();
    config.member_enabled = true;
    let mut state = AppState::new(config.clone()).unwrap();
    let memory = MemoryStore::load(&config.root, true);
    memory.set_demo_member_password("M001", HASH);
    state.store = Box::new(memory.clone());
    let page = get(&state, "/member", None);
    let login = post(&state, &cookie(&page), &login_payload(&csrf(&page), "M001", "siti rahma"));
    let c = cookie(&login);
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    drop(listener);
    let url = MySqlUrl::parse(&format!("mysql://x:y@127.0.0.1:{port}/test")).unwrap();
    state.store = Box::new(MySqlStore::new(url));
    let response = get(&state, "/member", Some(&c));
    assert_eq!(response.status, 503);
    assert!(!response.headers.iter().any(|(name, _)| name == "Set-Cookie"));
    state.store = Box::new(memory);
    assert!(body(&get(&state, "/member", Some(&c))).contains("Hi, Siti Rahma"));
}

#[cfg(feature = "member-sqlite")]
#[test]
fn changed_password_revokes_shared_sqlite_session_in_other_worker() {
    use slims9::store::memory::MemoryStore;
    use std::os::unix::fs::PermissionsExt;
    use std::time::{SystemTime, UNIX_EPOCH};
    let unique = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos();
    let dir = std::env::temp_dir().join(format!("slims-revoke-{}-{}", std::process::id(), unique));
    std::fs::create_dir(&dir).unwrap();
    std::fs::set_permissions(&dir, std::fs::Permissions::from_mode(0o700)).unwrap();
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_session_db = Some(dir.join("sessions.sqlite"));
    let memory = MemoryStore::load(&config.root, true);
    memory.set_demo_member_password("M001", HASH);
    let mut first = AppState::new(config.clone()).unwrap();
    let mut second = AppState::new(config).unwrap();
    first.store = Box::new(memory.clone());
    second.store = Box::new(memory.clone());
    let page = get(&first, "/member", None);
    let login = post(&first, &cookie(&page), &login_payload(&csrf(&page), "M001", "siti rahma"));
    let c = cookie(&login);
    assert!(body(&get(&second, "/member", Some(&c))).contains("Hi, Siti Rahma"));
    memory.set_demo_member_password("M001", "$2b$04$kfEuXqkxREGoRdFNFMfJFO7ARi6uiBzBLQJ7DMGAWdAPhdoPq9V2.");
    assert_eq!(get(&second, "/member", Some(&c)).status, 302);
    assert!(body(&get(&first, "/member", Some(&c))).contains("Library Member Login"));
    drop(first);
    drop(second);
    std::fs::remove_dir_all(dir).unwrap();
}
