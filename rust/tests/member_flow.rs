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
fn bookmarks_are_member_scoped_escaped_and_paginated() {
    use slims9::store::memory::MemoryStore;
    use slims9::store::seed::{self, SqlValue, Table};
    let mut config = Config::default();
    config.member_enabled = true;
    let mut data = seed::load(&config.root);
    let biblio = data.tables.get_mut("biblio").unwrap();
    let id_column = biblio.columns.iter().position(|c| c == "biblio_id").unwrap();
    let title_column = biblio.columns.iter().position(|c| c == "title").unwrap();
    let row = biblio.rows.iter_mut().find(|row| row[id_column].as_i64() == Some(2)).unwrap();
    row[title_column] = SqlValue::Text("<script>alert(1)</script>".into());
    let mut table = Table { columns: vec!["id".into(), "member_id".into(), "biblio_id".into(), "created_at".into()], rows: Vec::new() };
    for n in 0..22 {
        table.rows.push(vec![SqlValue::Text(format!("m-{n:02}")), SqlValue::Text("M001".into()), SqlValue::Int(2), SqlValue::Text("2024-01-01 <img src=x>".into())]);
    }
    table.rows.push(vec![SqlValue::Text("other".into()), SqlValue::Text("M002".into()), SqlValue::Int(4), SqlValue::Text("2024-02-01 00:00:00".into())]);
    table.rows.push(vec![SqlValue::Text("orphan".into()), SqlValue::Text("M001".into()), SqlValue::Int(999999), SqlValue::Text("2024-03-01 00:00:00".into())]);
    data.tables.insert("biblio_mark".into(), table);
    let store = MemoryStore::from_seed(&data, true);
    store.set_demo_member_password("M001", HASH);
    store.set_demo_member_password("M002", HASH);
    let mut state = AppState::new(config).unwrap();
    state.store = Box::new(store);
    let login = |id: &str| {
        let page = get(&state, "/member", None);
        cookie(&post(&state, &cookie(&page), &login_payload(&csrf(&page), id, "siti rahma")))
    };
    let first = login("M001");
    let second = login("M002");
    let anonymous = get(&state, "/member?sec=bookmark", None);
    assert!(!body(&anonymous).contains("&lt;script&gt;"));
    let page = get(&state, "/member?sec=bookmark", Some(&first));
    let text = body(&page);
    assert_eq!(text.matches("&lt;script&gt;alert(1)&lt;/script&gt;").count(), 20);
    assert!(!text.contains("<script>alert(1)</script>"));
    assert!(text.contains("2024-01-01 &lt;img src=x&gt;"));
    assert!(!text.contains("<img src=x>"));
    assert!(text.contains("page=2\">Next"));
    assert!(header(&page, "Cache-Control").contains("no-store"));
    let page = get(&state, "/member?sec=bookmark&page=2", Some(&first));
    assert_eq!(body(&page).matches("&lt;script&gt;alert(1)&lt;/script&gt;").count(), 2);
    assert!(body(&page).contains("Previous"));
    let page = get(&state, "/member?sec=bookmark", Some(&second));
    assert!(!body(&page).contains("&lt;script&gt;"));
    assert!(body(&page).contains("/biblio/4"));
    for query in ["page=0", "page=-1", "page=5002", "page=1&page=2", "page=abc", "page=184467440737095516160"] {
        assert_eq!(get(&state, &format!("/member?sec=bookmark&{query}"), Some(&first)).status, 400);
    }
    let token = csrf(&get(&state, "/member?sec=bookmark", Some(&first)));
    assert_eq!(post(&state, &first, &format!("action=logout&csrf_token={}", token)).status, 302);
    assert!(!body(&get(&state, "/member?sec=bookmark", Some(&first))).contains("&lt;script&gt;"));
}

#[test]
fn basket_is_session_isolated_csrf_protected_and_cleared_at_logout() {
    let state = state();
    let login = |state: &AppState| {
        let anon = get(state, "/member", None);
        let response = post(state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
        cookie(&response)
    };
    let first = login(&state);
    let second = login(&state);
    let page = get(&state, "/member?sec=title_basket", Some(&first));
    assert!(body(&page).contains("does not reserve titles"));
    let token = csrf(&page);
    assert_eq!(post(&state, &first, "action=basket_add&csrf_token=wrong&biblio_id=2").status, 403);
    assert_eq!(post(&state, &first, &format!("action=basket_add&csrf_token={}&biblio_id=999999", token)).status, 404);
    assert_eq!(post(&state, &first, &format!("action=basket_add&csrf_token={}&biblio_id=2&biblio_id=4", token)).status, 400);
    let add = format!("action=basket_add&csrf_token={}&biblio_id=2", token);
    assert_eq!(post(&state, &first, &add).status, 302);
    assert_eq!(post(&state, &first, &add).status, 302);
    let page = get(&state, "/member?sec=title_basket", Some(&first));
    assert!(body(&page).contains("1 of 20 titles"));
    assert!(body(&page).contains("Linux In a Nutshell"));
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&second))).contains("0 of 20 titles"));
    assert_eq!(post(&state, &first, &format!("action=basket_remove&csrf_token={}&biblio_id=2", token)).status, 302);
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&first))).contains("0 of 20 titles"));
    assert_eq!(post(&state, &first, &add).status, 302);
    assert_eq!(post(&state, &first, &format!("action=basket_clear&csrf_token={}", token)).status, 302);
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&first))).contains("0 of 20 titles"));
    assert_eq!(post(&state, &first, &add).status, 302);
    assert_eq!(post(&state, &first, &format!("action=logout&csrf_token={}", token)).status, 302);
    assert_eq!(post(&state, &first, &add).status, 403);
    let next = login(&state);
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&next))).contains("0 of 20 titles"));
}

#[test]
fn experimental_account_writes_are_opt_in_and_revoke_old_sessions() {
    use slims9::config::{DbConfig, MySqlUrl};
    let default_state = state();
    let page = get(&default_state, "/member", None);
    let login = post(&default_state, &cookie(&page), &login_payload(&csrf(&page), "M001", "siti rahma"));
    let c = cookie(&login);
    let t = csrf(&get(&default_state, "/member", Some(&c)));
    assert_eq!(post(&default_state, &c, &format!("action=bookmark_add&csrf_token={}&biblio_id=2", t)).status, 404);
    assert_eq!(post(&default_state, &c, &format!("action=change_password&csrf_token={}&current_password=x&new_password=Testpass1!&confirm_password=Testpass1!", t)).status, 404);
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_writes_enabled = true;
    config.demo_member_hash = Some(HASH.into());
    let state = AppState::new(config.clone()).unwrap();
    let login = |password: &str| {
        let page = get(&state, "/member", None);
        post(&state, &cookie(&page), &login_payload(&csrf(&page), "M001", password))
    };
    let first = cookie(&login("siti rahma"));
    let second = cookie(&login("siti rahma"));
    let t = csrf(&get(&state, "/member?sec=bookmark", Some(&first)));
    assert_eq!(post(&state, &first, &format!("action=bookmark_add&csrf_token={}&biblio_id=2", t)).status, 302);
    assert_eq!(post(&state, &first, &format!("action=bookmark_add&csrf_token={}&biblio_id=2", t)).status, 302);
    assert_eq!(body(&get(&state, "/member?sec=bookmark", Some(&second))).matches("Linux In a Nutshell").count(), 1);
    assert_eq!(post(&state, &first, &format!("action=bookmark_remove&csrf_token={}&biblio_id=2", t)).status, 302);
    assert!(!body(&get(&state, "/member?sec=bookmark", Some(&second))).contains("Linux In a Nutshell"));
    let change = |old: &str, new: &str, confirmation: &str| format!("action=change_password&csrf_token={}&current_password={}&new_password={}&confirm_password={}", t, url_encode(old), url_encode(new), url_encode(confirmation));
    assert!(body(&post(&state, &first, &change("siti rahma", "Weak", "Weak"))).contains("Passwords must match"));
    assert!(body(&post(&state, &first, &change("siti rahma", "Password1!", "Otherpass1!"))).contains("Passwords must match"));
    assert!(body(&post(&state, &first, &change("wrong", "Password1!", "Password1!"))).contains("Current password is incorrect"));
    let response = post(&state, &first, &change("siti rahma", "Password1!", "Password1!"));
    assert_eq!(response.status, 200);
    assert!(body(&response).contains("Password changed"));
    assert!(!body(&response).contains("Password1!"));
    assert_eq!(get(&state, "/member", Some(&second)).status, 302);
    assert_eq!(post(&state, &first, &change("siti rahma", "Password1!", "Password1!")).status, 403);
    assert!(body(&login("siti rahma")).contains("incorrect"));
    assert_eq!(login("Password1!").status, 302);
    config.db = DbConfig::MySql(MySqlUrl::parse("mysql://test:test@db.example/slims_test").unwrap());
    assert!(AppState::new(config.clone()).is_err());
    config.db = DbConfig::MySql(MySqlUrl::parse("mysql://test:test@127.0.0.1/production").unwrap());
    assert!(AppState::new(config).is_err());
    let mut disabled = Config::default();
    disabled.member_writes_enabled = true;
    assert!(AppState::new(disabled).is_err());
}

#[test]
fn legacy_password_upgrade_is_explicit_and_never_truncates() {
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_member_hash = Some("b6d4f14cc8fd48b20e3f23f5f81d9a61".into());
    let readonly = AppState::new(config.clone()).unwrap();
    let page = get(&readonly, "/member", None);
    assert!(body(&post(&readonly, &cookie(&page), &login_payload(&csrf(&page), "M001", "legacy password"))).contains("incorrect"));
    config.member_writes_enabled = true;
    let state = AppState::new(config).unwrap();
    let page = get(&state, "/member", None);
    let login = post(&state, &cookie(&page), &login_payload(&csrf(&page), "M001", "legacy password"));
    assert_eq!(login.status, 302);
    let credential = state.store.member_credential("M001").unwrap().unwrap();
    assert!(credential.password_hash.starts_with("$2b$10$"));
    let next = get(&state, "/member", None);
    assert_eq!(post(&state, &cookie(&next), &login_payload(&csrf(&next), "M001", "legacy password")).status, 302);
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
    let page = get(&first, "/member?sec=title_basket", Some(&c));
    let token = csrf(&page);
    assert_eq!(post(&first, &c, &format!("action=basket_add&csrf_token={}&biblio_id=2", token)).status, 302);
    drop(first);
    let second = AppState::new(config.clone()).unwrap();
    let page = get(&second, "/member", Some(&c));
    assert!(body(&page).contains("Hi, Siti Rahma"));
    assert!(body(&get(&second, "/member?sec=title_basket", Some(&c))).contains("Linux In a Nutshell"));
    let third = AppState::new(config).unwrap();
    assert_eq!(post(&third, &c, &format!("action=basket_clear&csrf_token={}", token)).status, 302);
    assert!(body(&get(&second, "/member?sec=title_basket", Some(&c))).contains("0 of 20 titles"));
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

#[test]
fn basket_reservation_buttons_are_opt_in_and_keep_basket_on_success_and_failure() {
    for enabled in [false, true] {
        let mut config = Config::default();
        config.member_enabled = true;
        config.demo_member_hash = Some(HASH.into());
        config.demo_reservations = enabled;
        let state = AppState::new(config).unwrap();
        let anon = get(&state, "/member", None);
        let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
        let c = cookie(&auth);
        let token = csrf(&get(&state, "/member", Some(&c)));
        for title in [1, 2] {
            assert_eq!(post(&state, &c, &format!("action=basket_add&csrf_token={token}&biblio_id={title}")).status, 302);
        }
        let basket = get(&state, "/member?sec=title_basket", Some(&c));
        assert_eq!(body(&basket).contains("Reserve in demo"), enabled);
        assert!(!body(&basket).contains("Reserve in test database"));
        let reserve = format!("action=reserve&csrf_token={token}&biblio_id=2");
        assert_eq!(post(&state, &c, "action=reserve&csrf_token=bad&biblio_id=2").status, 403);
        if enabled {
            assert_eq!(post(&state, &c, &reserve).status, 302);
            assert!(body(&post(&state, &c, &reserve)).contains("already reserved"));
            assert!(body(&post(&state, &c, &format!("action=reserve&csrf_token={token}&biblio_id=1"))).contains("No eligible item"));
            assert_eq!(state.store.demo_reservations("M001").unwrap().len(), 1);
        } else {
            assert_eq!(post(&state, &c, &reserve).status, 404);
        }
        assert!(body(&get(&state, "/member?sec=title_basket", Some(&c))).contains("2 of 20 titles"));
    }
}

#[test]
fn current_loans_show_utc_lateness_but_history_does_not() {
    let state = state();
    let anon = get(&state, "/member", None);
    let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
    let c = cookie(&auth);
    let current = get(&state, "/member?sec=current_loan", Some(&c));
    assert_eq!(current.status, 200);
    let html = body(&current);
    assert!(html.contains("lateness in UTC; not a fine calculation"));
    let today = slims9::date::Date::today();
    for loan in state.store.member_loans("M001", false).unwrap() {
        if let Some(due) = loan.due_date.as_deref().and_then(slims9::date::Date::parse) {
            let days = today.to_days() - due.to_days();
            if days > 0 { assert!(html.contains(&format!("Overdue: {days} calendar day"))); }
        }
    }
    assert!(!body(&get(&state, "/member?sec=loan_history", Some(&c))).contains("Overdue:"));
    assert!(!body(&get(&state, "/member", None)).contains("Overdue:"));
    assert!(header(&current, "Cache-Control").contains("no-store"));
}

#[test]
fn csv_exports_require_current_auth_and_validate_page_and_format() {
    let state = state();
    let export = "/member?sec=current_loan&format=csv";
    let anonymous = get(&state, export, None);
    assert!(header(&anonymous, "Content-Type").contains("text/html"));
    assert!(!anonymous.headers.iter().any(|(k, _)| k == "Content-Disposition"));
    let auth = post(&state, &cookie(&anonymous), &login_payload(&csrf(&anonymous), "M001", "siti rahma"));
    let c = cookie(&auth);
    for section in ["current_loan", "loan_history"] {
        let csv = get(&state, &format!("/member?sec={section}&format=csv"), Some(&c));
        assert_eq!(csv.status, 200);
        assert_eq!(header(&csv, "Content-Type"), "text/csv; charset=utf-8");
        assert!(header(&csv, "Content-Disposition").contains("page-1.csv"));
        assert_eq!(header(&csv, "X-Content-Type-Options"), "nosniff");
        assert!(header(&csv, "Cache-Control").contains("no-store"));
        let text = body(&csv);
        assert!(text.starts_with("\u{feff}\"Item Code\",\"Title\",\"Loan Date\","));
        assert!(text.contains(if section == "loan_history" { "Return Date" } else { "Due Date" }));
        assert!(!text.contains(HASH));
        let rows = state.store.member_loans("M001", section == "loan_history").unwrap();
        assert_eq!(text.matches("\r\n").count(), rows.len() + 1);
        let empty = get(&state, &format!("/member?sec={section}&format=csv&page=5001"), Some(&c));
        assert_eq!(empty.status, 200);
        assert_eq!(body(&empty).matches("\r\n").count(), 1);
    }
    for query in ["format=csv&page=0", "format=csv&page=5002", "format=csv&page=1&page=2",
        "format=csv&format=csv", "format=pdf", "format=", "sec=bookmark&format=csv"] {
        assert_eq!(get(&state, &format!("/member?{query}"), Some(&c)).status, 400, "{query}");
    }
    let page = get(&state, "/member", Some(&c));
    assert!(body(&page).contains("Download this page (CSV)"));
    assert_eq!(post(&state, &c, &format!("action=logout&csrf_token={}", csrf(&page))).status, 302);
    assert!(header(&get(&state, export, Some(&c)), "Content-Type").contains("text/html"));
}

#[test]
fn member_requests_reject_ambiguous_selectors_headers_and_revoked_csv() {
    let state = state();
    let anon = get(&state, "/member", None);
    let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
    let c = cookie(&auth);
    let page = get(&state, "/member", Some(&c));
    let token = csrf(&page);
    for query in ["sec=current_loan&sec=loan_history", "page=1&page=1", "format=csv&format=csv"] {
        assert_eq!(get(&state, &format!("/member?{query}"), Some(&c)).status, 400);
    }
    let mut split = Request::new("GET", "/member?format=csv");
    split.headers.push(("Cookie".into(), "theme=dark".into()));
    split.headers.push(("cOoKiE".into(), c.clone()));
    let csv = state.handle(&split);
    assert!(header(&csv, "Content-Type").contains("text/csv"));
    assert_eq!(header(&csv, "Referrer-Policy"), "no-referrer");
    assert_eq!(header(&csv, "X-Content-Type-Options"), "nosniff");
    split.headers.push(("Cookie".into(), c.clone()));
    let denied = state.handle(&split);
    assert!(header(&denied, "Content-Type").contains("text/html"));
    assert!(!denied.headers.iter().any(|(name, _)| name == "Content-Disposition"));

    let mut req = Request::new("POST", "/member");
    req.headers = vec![("Cookie".into(), c.clone()),
        ("Content-Type".into(), "application/x-www-form-urlencoded".into()),
        ("content-type".into(), "application/x-www-form-urlencoded".into())];
    req.body = format!("action=basket_add&csrf_token={token}&biblio_id=2").into_bytes();
    assert_eq!(state.handle(&req).status, 400);
    req.headers.pop();
    req.headers.push(("Cookie".into(), c.clone()));
    assert_eq!(state.handle(&req).status, 403);
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&c))).contains("0 of 20 titles"));
    // Fail-closed ambiguous requests must not invalidate the valid session.
    assert!(body(&get(&state, "/member", Some(&c))).contains("Hi, Siti Rahma"));

    assert!(state.store.member_change_password("M001", HASH, "removed").unwrap());
    let denied = get(&state, "/member?format=csv", Some(&c));
    assert_eq!(denied.status, 302);
    assert!(header(&denied, "Set-Cookie").contains("Max-Age=0"));
    assert!(!denied.headers.iter().any(|(name, _)| name == "Content-Disposition"));
}

#[test]
fn logout_all_revokes_only_authenticated_members_sessions() {
    logout_all_flow(None);
}

#[cfg(feature = "member-sqlite")]
#[test]
fn logout_all_revokes_shared_sqlite_sessions_across_workers() {
    use std::os::unix::fs::PermissionsExt;
    let dir = std::env::temp_dir().join(format!("slims-logout-all-{}-{}", std::process::id(),
        std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()));
    std::fs::create_dir(&dir).unwrap();
    std::fs::set_permissions(&dir, std::fs::Permissions::from_mode(0o700)).unwrap();
    logout_all_flow(Some(dir.join("sessions.sqlite")));
    std::fs::remove_dir_all(dir).unwrap();
}

fn logout_all_flow(path: Option<std::path::PathBuf>) {
    use slims9::store::memory::MemoryStore;
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_session_db = path.clone();
    let build = || {
        let mut state = AppState::new(config.clone()).unwrap();
        let store = MemoryStore::load(&config.root, true);
        for id in ["M001", "M002"] { store.set_demo_member_password(id, HASH); }
        state.store = Box::new(store);
        state
    };
    let state = build();
    let login = |state: &AppState, member: &str| {
        let anon = get(state, "/member", None);
        let res = post(state, &cookie(&anon), &login_payload(&csrf(&anon), member, "siti rahma"));
        assert_eq!(res.status, 302);
        cookie(&res)
    };
    let a = login(&state, "M001");
    // A second worker shares SQLite but not the in-memory session map.
    let worker = path.as_ref().map(|_| build());
    let second = worker.as_ref().unwrap_or(&state);
    let b = login(second, "M001");
    let other = login(second, "M002");
    let anon = get(&state, "/member", None);
    assert_eq!(post(&state, &cookie(&anon), &format!("action=logout_all&csrf_token={}", csrf(&anon))).status, 403);
    let account = get(&state, "/member?sec=my_account", Some(&a));
    assert!(body(&account).contains("Sign out of all Rust sessions"));
    let token = csrf(&account);
    assert_eq!(post(&state, &a, "action=logout_all&csrf_token=bad").status, 403);
    assert!(body(&get(second, "/member", Some(&b))).contains("Hi, Siti Rahma"));
    assert_eq!(post(&state, &a, &format!("action=basket_add&csrf_token={token}&biblio_id=2")).status, 302);
    let result = post(&state, &a, &format!("action=logout_all&csrf_token={token}&memberID=M002"));
    assert_eq!(result.status, 302);
    assert!(header(&result, "Set-Cookie").contains("Max-Age=0"));
    assert!(header(&result, "Cache-Control").contains("no-store"));
    for (worker, c) in [(&state, &a), (second, &b)] {
        assert!(body(&get(worker, "/member", Some(c))).contains("Library Member Login"));
        assert!(!get(worker, "/member?format=csv", Some(c)).headers.iter().any(|(name, _)| name == "Content-Disposition"));
    }
    assert_eq!(post(&state, &a, &format!("action=logout_all&csrf_token={token}")).status, 403);
    assert!(body(&get(second, "/member", Some(&other))).contains("Hi, Budi Santoso"));
    let fresh = login(&state, "M001");
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&fresh))).contains("0 of 20 titles"));
}

#[test]
fn catalog_member_actions_are_authenticated_read_only_confirmations() {
    use slims9::store::memory::MemoryStore;
    use slims9::store::seed::{self, SqlValue};
    for enabled in [false, true] {
        let mut config = Config::default();
        config.member_enabled = true;
        config.member_writes_enabled = enabled;
        config.demo_reservations = enabled;
        let mut data = seed::load(&config.root);
        let table = data.tables.get_mut("biblio").unwrap();
        let id_col = table.columns.iter().position(|c| c == "biblio_id").unwrap();
        let title_col = table.columns.iter().position(|c| c == "title").unwrap();
        let hidden_col = table.columns.iter().position(|c| c == "opac_hide").unwrap();
        for row in &mut table.rows {
            if row[id_col].as_i64() == Some(2) { row[title_col] = SqlValue::Text("<img src=x onerror=alert(1)> & Title".into()); }
            if row[id_col].as_i64() == Some(4) { row[hidden_col] = SqlValue::Int(1); }
        }
        let mut store = MemoryStore::from_seed(&data, true);
        store.set_demo_member_password("M001", HASH);
        if enabled { store.enable_demo_reservations(); }
        let mut state = AppState::new(config).unwrap();
        state.store = Box::new(store);
        let path = "/member?sec=title_actions&biblio_id=2";
        let catalog = get(&state, "/biblio/2", None);
        assert!(body(&catalog).contains("/member?sec=title_actions&amp;biblio_id=2"));
        assert!(!catalog.headers.iter().any(|(name, _)| name == "Set-Cookie"));
        let anon = get(&state, path, None);
        assert!(body(&anon).contains("Library Member Login"));
        assert!(!body(&anon).contains("Add to title basket"));
        let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
        let c = cookie(&auth);
        let confirm = get(&state, path, Some(&c));
        assert_eq!(confirm.status, 200);
        assert!(header(&confirm, "Cache-Control").contains("no-store"));
        assert!(body(&confirm).contains("&lt;img src=x onerror=alert(1)&gt; &amp; Title"));
        assert!(!body(&confirm).contains("<img src=x"));
        assert!(body(&confirm).contains("Add to title basket"));
        assert_eq!(body(&confirm).contains("Add bookmark (test only)"), enabled);
        assert_eq!(body(&confirm).contains("Reserve in demo"), enabled);
        // Merely viewing (including HEAD) never performs any mutation.
        let mut head = Request::new("HEAD", path);
        head.headers.push(("Cookie".into(), c.clone()));
        assert_eq!(state.handle(&head).status, 200);
        assert!(body(&get(&state, "/member?sec=title_basket", Some(&c))).contains("0 of 20 titles"));
        assert!(state.store.member_bookmarks("M001", 0).unwrap().is_empty());
        for id in ["", "-1", "oops", "2&biblio_id=4"] {
            assert_eq!(get(&state, &format!("/member?sec=title_actions&biblio_id={id}"), Some(&c)).status, 400);
        }
        for id in [4, 999999] {
            assert_eq!(get(&state, &format!("/member?sec=title_actions&biblio_id={id}"), Some(&c)).status, 404);
        }
        let payload = format!("action=basket_add&biblio_id=2&csrf_token={}", csrf(&confirm));
        assert_eq!(post(&state, &c, &payload).status, 302);
        assert!(body(&get(&state, "/member?sec=title_basket", Some(&c))).contains("1 of 20 titles"));
    }
    assert!(!body(&get(&AppState::new(Config::default()).unwrap(), "/biblio/2", None)).contains("Member actions:"));
}

#[test]
fn login_returns_to_validated_title_confirmation_without_performing_action() {
    let state = state();
    let target = "/member?sec=title_actions&biblio_id=0002";
    let anon = get(&state, target, None);
    let c = cookie(&anon);
    assert!(body(&anon).contains("name=\"return_title\" value=\"2\""));
    let again = get(&state, target, Some(&c));
    assert!(body(&again).contains("name=\"return_title\" value=\"2\""));
    let payload = |pass: &str| format!("{}&return_title=2", login_payload(&csrf(&anon), "M001", pass));
    let wrong = post(&state, &c, &payload("incorrect"));
    assert_eq!(wrong.status, 200);
    assert!(body(&wrong).contains("name=\"return_title\" value=\"2\""));
    let ok = post(&state, &c, &payload("siti rahma"));
    assert_eq!(ok.status, 302);
    assert_eq!(header(&ok, "Location"), "/member?sec=title_actions&biblio_id=2");
    let auth = cookie(&ok);
    assert_ne!(auth, c);
    assert!(body(&get(&state, header(&ok, "Location"), Some(&auth))).contains("Member title actions"));
    assert!(body(&get(&state, "/member?sec=title_basket", Some(&auth))).contains("0 of 20 titles"));
    assert!(state.store.member_bookmarks("M001", 0).unwrap().is_empty());
    assert_eq!(post(&state, &c, &payload("siti rahma")).status, 403);

    let anon = get(&state, "/member", None);
    let c = cookie(&anon);
    let login = login_payload(&csrf(&anon), "M001", "siti rahma");
    for raw in ["", "0", "-1", "+2", " 2", "https://evil.invalid", "//evil.invalid", "2\r\nLocation:evil", "9223372036854775808"] {
        assert_eq!(post(&state, &c, &format!("{login}&return_title={}", url_encode(raw))).status, 400, "{raw:?}");
    }
    assert_eq!(post(&state, &c, &format!("{login}&return_title=2&return_title=2")).status, 400);
    // An arbitrary return URL is not a supported target.
    let ok = post(&state, &c, &format!("{login}&return_url=https%3A%2F%2Fevil.invalid"));
    assert_eq!(header(&ok, "Location"), "/member");

    let anon = get(&state, "/member?sec=title_actions&biblio_id=999999", None);
    let ok = post(&state, &cookie(&anon), &format!("{}&return_title=999999", login_payload(&csrf(&anon), "M001", "siti rahma")));
    assert_eq!(get(&state, header(&ok, "Location"), Some(&cookie(&ok))).status, 404);
}

#[test]
fn stored_reservations_are_read_only_scoped_bounded_and_separate_from_demo() {
    use slims9::store::memory::MemoryStore;
    use slims9::store::seed::{self, SqlValue, Table};
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_reservations = true;
    let mut data = seed::load(&config.root);
    let mut table = Table {
        columns: ["reserve_id", "member_id", "biblio_id", "item_code", "reserve_date"].map(str::to_string).to_vec(),
        rows: Vec::new(),
    };
    for n in 1..=103 {
        table.rows.push(vec![SqlValue::Int(n), SqlValue::Text("M001".into()), SqlValue::Int(2),
            SqlValue::Text(format!("R{n:03}<script>")), SqlValue::Text("2026-01-01 <img>".into())]);
    }
    table.rows.push(vec![SqlValue::Int(104), SqlValue::Text("M002".into()), SqlValue::Int(2), SqlValue::Text("OTHER-OWNER".into()), SqlValue::Null]);
    table.rows.push(vec![SqlValue::Int(105), SqlValue::Text("M001".into()), SqlValue::Int(999999), SqlValue::Text("ORPHAN".into()), SqlValue::Null]);
    data.tables.insert("reserve".into(), table);
    let mut store = MemoryStore::from_seed(&data, true);
    store.enable_demo_reservations();
    store.set_demo_member_password("M001", HASH);
    let mut state = AppState::new(config).unwrap();
    state.store = Box::new(store);
    let path = "/member?sec=reservation_list";
    let anon = get(&state, path, None);
    assert!(!body(&anon).contains("R103"));
    let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
    let c = cookie(&auth);
    let page = get(&state, path, Some(&c));
    assert_eq!(page.status, 200);
    assert!(header(&page, "Cache-Control").contains("no-store"));
    let html = body(&page);
    assert!(html.contains("My Stored Reservations"));
    assert!(html.contains("R103&lt;script&gt;"));
    assert!(html.contains("R004&lt;script&gt;"));
    assert!(!html.contains("R003"));
    assert!(!html.contains("OTHER-OWNER"));
    assert!(!html.contains("ORPHAN"));
    assert!(!html.contains("<script>"));
    assert!(html.contains("2026-01-01 &lt;img&gt;"));
    assert!(html.find("R103").unwrap() < html.find("R004").unwrap());
    assert_eq!(state.store.member_reservations("M001").unwrap().len(), 100);
    assert!(state.store.member_reservations("m001").unwrap().is_empty());
    assert!(state.store.member_reservations("missing").unwrap().is_empty());
    assert_eq!(state.store.member_reservations("M002").unwrap().len(), 1);
    assert!(state.store.demo_reservations("M001").unwrap().is_empty());
    let reserve = format!("action=reserve&biblio_id=2&csrf_token={}", csrf(&page));
    assert_eq!(post(&state, &c, &reserve).status, 302);
    assert_eq!(state.store.member_reservations("M001").unwrap().len(), 100);
    assert_eq!(state.store.demo_reservations("M001").unwrap().len(), 1);
}

#[test]
fn login_timestamp_is_opt_in_credential_bound_and_not_written_on_bad_password() {
    for enabled in [false, true] {
        let mut config = Config::default();
        config.member_enabled = true;
        config.demo_member_hash = Some(HASH.into());
        config.member_writes_enabled = enabled;
        let state = AppState::new(config).unwrap();
        assert!(state.store.member_profile("M001").unwrap().unwrap().last_login.is_none());
        let anon = get(&state, "/member", None);
        let c = cookie(&anon);
        let failed = post(&state, &c, &login_payload(&csrf(&anon), "M001", "wrong"));
        assert!(body(&failed).contains("incorrect"));
        assert!(state.store.member_profile("M001").unwrap().unwrap().last_login.is_none());
        let success = post(&state, &c, &login_payload(&csrf(&anon), "M001", "siti rahma"));
        assert_eq!(success.status, 302);
        let profile = state.store.member_profile("M001").unwrap().unwrap();
        assert_eq!(profile.last_login.is_some(), enabled);
        if let Some(stamp) = &profile.last_login {
            assert_eq!(stamp.len(), 19);
            let page = get(&state, "/member?sec=my_account", Some(&cookie(&success)));
            assert!(body(&page).contains(stamp));
            assert!(!state.store.member_record_login("M001", "stale hash").unwrap());
            assert!(!state.store.member_record_login("m001", HASH).unwrap());
            assert_eq!(state.store.member_profile("M001").unwrap().unwrap().last_login.as_ref(), Some(stamp));
        }
        assert!(state.store.member_profile("M002").unwrap().unwrap().last_login.is_none());
    }
}

#[test]
fn reserve_session_basket_reports_partial_results_and_preserves_basket() {
    for enabled in [false, true] {
        let mut config = Config::default();
        config.member_enabled = true;
        config.demo_member_hash = Some(HASH.into());
        config.demo_reservations = enabled;
        let state = AppState::new(config).unwrap();
        let anon = get(&state, "/member", None);
        let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
        let c = cookie(&auth);
        let t = csrf(&get(&state, "/member", Some(&c)));
        let payload = format!("action=basket_reserve&csrf_token={t}");
        if !enabled {
            assert_eq!(post(&state, &c, &payload).status, 404);
            continue;
        }
        assert!(body(&post(&state, &c, &payload)).contains("Basket is empty"));
        for id in [2, 1, 4, 6] {
            assert_eq!(post(&state, &c, &format!("action=basket_add&csrf_token={t}&biblio_id={id}")).status, 302);
        }
        assert_eq!(post(&state, &c, "action=basket_reserve&csrf_token=bad").status, 403);
        for forged in ["biblio_id=8", "memberID=M002", "basket%5B%5D=8"] {
            assert_eq!(post(&state, &c, &format!("{payload}&{forged}")).status, 400);
        }
        assert!(state.store.demo_reservations("M001").unwrap().is_empty());
        let result = post(&state, &c, &payload);
        assert_eq!(result.status, 200);
        let text = body(&result);
        assert!(text.contains("<td>2</td><td>Reserved</td>"));
        assert!(text.contains("<td>1</td><td>No eligible item available</td>"));
        assert!(text.contains("<td>4</td><td>Reserved</td>"));
        assert!(text.contains("<td>6</td><td>Reserve limit reached</td>"));
        assert!(header(&result, "Cache-Control").contains("no-store"));
        assert_eq!(state.store.demo_reservations("M001").unwrap().len(), 2);
        assert!(state.store.demo_reservations("M002").unwrap().is_empty());
        let repeated = post(&state, &c, &payload);
        assert!(body(&repeated).contains("Already reserved"));
        assert_eq!(state.store.demo_reservations("M001").unwrap().len(), 2);
        let basket = get(&state, "/member?sec=title_basket", Some(&c));
        assert!(body(&basket).contains("4 of 20 titles"));
        assert_eq!(post(&state, &c, &format!("action=logout&csrf_token={t}")).status, 302);
        assert_eq!(post(&state, &c, &payload).status, 403);
    }
}

#[test]
fn unsupported_store_cannot_fall_back_to_unbound_sql_reservations() {
    let mut config = Config::default();
    config.member_enabled = true;
    config.demo_reservations = true;
    config.demo_member_hash = Some(HASH.into());
    let state = AppState::new(config).unwrap();
    assert!(state.store.test_reserve_bound("M001", 2, "").is_err());
    assert!(state.store.demo_reservations("M001").unwrap().is_empty());
}

#[test]
fn bound_bookmarks_refuse_stale_versions_and_preserve_owner_isolation() {
    use slims9::store::{memory::MemoryStore, Store, StoreError};
    let config = Config::default();
    let store = MemoryStore::load(&config.root, true);
    store.set_demo_member_password("M001", HASH);
    store.set_demo_member_password("M002", HASH);
    // SHA-256 of the fixture hash, the same marker held by member sessions.
    let version = "b3726e14ffe64b1b951cc9556a69daa28bba54a457cec9a9a64297cc04c19811";
    for id in ["M001", "M002"] {
        assert!(store.member_bookmark_write_bound(id, 2, version, true).unwrap());
        assert!(store.member_bookmark_write_bound(id, 2, version, true).unwrap());
        assert_eq!(store.member_bookmarks(id, 0).unwrap().len(), 1);
    }
    assert!(!store.member_bookmark_write_bound("m001", 2, version, false).unwrap());
    assert!(!store.member_bookmark_write_bound("M001", 2, "", false).unwrap());
    store.set_demo_member_password("M001", "admin-reset");
    assert!(!store.member_bookmark_write_bound("M001", 4, version, true).unwrap());
    assert!(!store.member_bookmark_write_bound("M001", 2, version, false).unwrap());
    assert_eq!(store.member_bookmarks("M001", 0).unwrap().len(), 1);
    store.set_demo_member_password("M001", HASH);
    assert_eq!(store.member_bookmark_write_bound("M001", 999999, version, true), Err(StoreError::NotFound));
    assert!(store.member_bookmark_write_bound("M001", 999999, version, false).unwrap());
    assert!(store.member_bookmark_write_bound("M001", 2, version, false).unwrap());
    assert!(store.member_bookmark_write_bound("M001", 2, version, false).unwrap());
    assert!(store.member_bookmarks("M001", 0).unwrap().is_empty());
    assert_eq!(store.member_bookmarks("M002", 0).unwrap().len(), 1);
    store.set_demo_member_password("M001", "");
    assert!(!store.member_bookmark_write_bound("M001", 2, version, true).unwrap());
}

#[test]
fn combined_loan_report_is_authenticated_scoped_read_only_and_has_legacy_alias() {
    let state = state(); // All SQL/test writes disabled.
    for path in ["/member?sec=loan_report", "/index.php?p=download_member_loan_report&format=csv"] {
        let anonymous = get(&state, path, None);
        assert!(body(&anonymous).contains("Library Member Login"));
        assert!(!anonymous.headers.iter().any(|(key, _)| key == "Content-Disposition"));
    }
    let anon = get(&state, "/member", None);
    let logged = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
    let c = cookie(&logged);
    let expected = state.store.member_loan_report("M001").unwrap();
    assert_eq!(expected.len(), 3);
    assert!(state.store.member_loan_report("m001").unwrap().is_empty());
    assert!(state.store.member_loan_report("M001' OR 1=1 --").unwrap().is_empty());
    for path in ["/member?sec=loan_report", "/index.php?p=download_member_loan_report"] {
        let report = get(&state, path, Some(&c));
        assert_eq!(report.status, 200);
        assert!(body(&report).contains("Current loans (1)"));
        assert!(body(&report).contains("Returned loans (2)"));
        assert!(body(&report).contains("Siti Rahma"));
        assert!(!body(&report).contains("Budi Santoso"));
        assert!(body(&report).contains("/static/member-report.css"));
        assert!(!body(&report).contains("<style>"));
        assert!(header(&report, "Cache-Control").contains("no-store"));
        assert_eq!(header(&report, "Referrer-Policy"), "no-referrer");
    }
    for path in ["/member?sec=loan_report&format=csv", "/index.php?p=download_member_loan_report&format=csv"] {
        let csv = get(&state, path, Some(&c));
        assert_eq!(csv.status, 200);
        assert_eq!(body(&csv).lines().count(), 4);
        assert!(body(&csv).contains("\"Status\",\"Item Code\",\"Title\",\"Loan Date\",\"Due Date\",\"Return Date\""));
        assert!(header(&csv, "Content-Disposition").contains("member-loan-report.csv"));
    }
    for query in ["page=1", "format=pdf", "format=", "memberID=M002", "member_id=M002", "biblio_id=2", "format=csv&format=csv"] {
        assert_eq!(get(&state, &format!("/member?sec=loan_report&{query}"), Some(&c)).status, 400);
    }
    assert_eq!(get(&state, "/index.php?p=download_member_loan_report&sec=my_account", Some(&c)).status, 400);
    assert_eq!(get(&state, "/index.php?p=download_member_loan_report&p=member", Some(&c)).status, 400);
    let mut request = Request::new("POST", "/index.php?p=download_member_loan_report");
    request.headers.push(("Cookie".into(), c.clone()));
    assert_eq!(state.handle(&request).status, 405);
    assert_eq!(get(&state, "/static/member-report.css", None).status, 200);
    let t = csrf(&get(&state, "/member", Some(&c)));
    assert_eq!(post(&state, &c, &format!("action=logout&csrf_token={t}")).status, 302);
    let revoked = get(&state, "/member?sec=loan_report&format=csv", Some(&c));
    assert!(body(&revoked).contains("Library Member Login"));
    assert!(!revoked.headers.iter().any(|(key, _)| key == "Content-Disposition"));
}

#[test]
fn current_and_history_downloads_are_scoped_authenticated_attachments() {
    use slims9::models::MemberLoanReportScope;
    let state = state();
    let anon = get(&state, "/member", None);
    let auth = post(&state, &cookie(&anon), &login_payload(&csrf(&anon), "M001", "siti rahma"));
    let c = cookie(&auth);
    for (legacy, scope, expected, rejected, count, filename) in [
        ("download_current_loan", "current", "Current loans (1)", "Returned loans", 1, "current_loan.html"),
        ("download_loan_history", "history", "Returned loans (2)", "Current loans", 2, "loan_history.html"),
    ] {
        let path = format!("/index.php?p={legacy}");
        let anonymous = get(&state, &path, None);
        assert!(body(&anonymous).contains("Library Member Login"));
        assert!(!anonymous.headers.iter().any(|(k, _)| k == "Content-Disposition"));
        let response = get(&state, &path, Some(&c));
        assert_eq!(response.status, 200);
        assert!(header(&response, "Content-Disposition").contains(filename));
        assert!(header(&response, "Content-Type").starts_with("text/html"));
        assert!(header(&response, "Cache-Control").contains("no-store"));
        assert!(body(&response).contains(expected));
        assert!(!body(&response).contains(rejected));
        assert!(body(&response).contains(&format!("scope={scope}&amp;format=csv")));
        let csv = get(&state, &format!("{path}&format=csv"), Some(&c));
        assert_eq!(csv.status, 200);
        assert_eq!(body(&csv).lines().count(), count + 1);
        let inline = get(&state, &format!("/member?sec=loan_report&scope={scope}"), Some(&c));
        assert_eq!(inline.status, 200);
        assert!(body(&inline).contains(expected));
        assert!(!inline.headers.iter().any(|(k, _)| k == "Content-Disposition"));
        for extra in ["scope=all", "page=1", "sec=my_account", "memberID=M002", "format=csv&format=html"] {
            assert_eq!(get(&state, &format!("{path}&{extra}"), Some(&c)).status, 400);
        }
        let mut post = Request::new("POST", &path); post.headers.push(("Cookie".into(), c.clone()));
        assert_eq!(state.handle(&post).status, 405);
    }
    for scope in [MemberLoanReportScope::Current, MemberLoanReportScope::History] {
        assert!(state.store.member_loan_report_scoped("M001", scope).unwrap().iter().all(|r| scope.includes(r.returned)));
        assert!(state.store.member_loan_report_scoped("m001", scope).unwrap().is_empty());
    }
    for query in ["scope=invalid", "scope=", "scope=current&scope=history"] {
        assert_eq!(get(&state, &format!("/member?sec=loan_report&{query}"), Some(&c)).status, 400);
    }
    let t = csrf(&get(&state, "/member", Some(&c)));
    post(&state, &c, &format!("action=logout&csrf_token={t}"));
    assert!(body(&get(&state, "/index.php?p=download_loan_history", Some(&c))).contains("Library Member Login"));
}
