//! Destructive, explicitly opt-in tests against a disposable local MySQL database.
//! Fixture must contain SLiMS tables/sampledata and members M001/M002 with HASH.
#![cfg(feature = "member-crypt")]
use slims9::app::AppState;
use slims9::config::{Config, DbConfig, MySqlUrl};
use slims9::http::{url_encode, Request, Response};
use slims9::models::DemoReserveResult;
use slims9::store::mysql::{Connection, MySqlStore};
use slims9::store::{Store, StoreError};
use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::Arc;

const HASH: &str = "$2y$04$qGzOSwDpk3DwkERJbP.zsuFsyyjmAMwbQByLMBLb/Y77gxpIIJWGO";
fn header<'a>(r: &'a Response, key: &str) -> &'a str { &r.headers.iter().find(|(k, _)| k.eq_ignore_ascii_case(key)).unwrap().1 }
fn cookie(r: &Response) -> String { header(r, "Set-Cookie").split(';').next().unwrap().into() }
fn text(r: &Response) -> &str { std::str::from_utf8(&r.body).unwrap() }
fn token(r: &Response) -> String { text(r).split("name=\"csrf_token\" value=\"").nth(1).unwrap().split('"').next().unwrap().into() }
fn get(s: &AppState, path: &str, cookie: Option<&str>) -> Response {
    let mut r = Request::new("GET", path);
    if let Some(c) = cookie { r.headers.push(("Cookie".into(), c.into())); }
    s.handle(&r)
}
fn post(s: &AppState, c: &str, body: &str) -> Response {
    let mut r = Request::new("POST", "/member");
    r.headers.push(("Cookie".into(), c.into()));
    r.headers.push(("Content-Type".into(), "application/x-www-form-urlencoded".into()));
    r.body = body.as_bytes().into();
    s.handle(&r)
}
fn login(s: &AppState, member: &str, pass: &str) -> Response {
    let page = get(s, "/member", None);
    post(s, &cookie(&page), &format!("action=login&csrf_token={}&memberID={}&memberPassWord={}", token(&page), url_encode(member), url_encode(pass)))
}

// Forward a native-password handshake, deliver one query, then deliberately
// discard the committed response to exercise the ambiguous-write path.
fn discard_one_write_response(url: &MySqlUrl) -> (MySqlUrl, std::thread::JoinHandle<()>) {
    fn packet(from: &mut TcpStream, to: &mut TcpStream) -> Vec<u8> {
        let mut header = [0u8; 4]; from.read_exact(&mut header).unwrap();
        let len = header[0] as usize | ((header[1] as usize) << 8) | ((header[2] as usize) << 16);
        let mut body = vec![0; len]; from.read_exact(&mut body).unwrap();
        to.write_all(&header).unwrap(); to.write_all(&body).unwrap();
        body
    }
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let mut proxy_url = url.clone(); proxy_url.host = "127.0.0.1".into(); proxy_url.port = listener.local_addr().unwrap().port();
    let real_address = format!("{}:{}", url.host, url.port);
    let worker = std::thread::spawn(move || {
        let (mut client, _) = listener.accept().unwrap();
        drop(listener); // an automatic retry cannot reconnect through this proxy
        let mut server = TcpStream::connect(real_address).unwrap();
        packet(&mut server, &mut client); // greeting
        packet(&mut client, &mut server); // auth
        let auth = packet(&mut server, &mut client);
        assert_eq!(auth.first(), Some(&0x00)); // fixture uses native password
        let query = packet(&mut client, &mut server);
        assert_eq!(query.first(), Some(&0x03));
        let mut header = [0u8; 4]; server.read_exact(&mut header).unwrap();
        let len = header[0] as usize | ((header[1] as usize) << 8) | ((header[2] as usize) << 16);
        let mut response = vec![0; len]; server.read_exact(&mut response).unwrap();
        assert_eq!(response.first(), Some(&0x00)); // server committed, client sees EOF
    });
    (proxy_url, worker)
}

fn store_report_check(state: &AppState, cookie: &str) -> Vec<slims9::models::MemberLoanReportRow> {
    let rows = state.store.member_loan_report("M001").unwrap();
    let csv = get(state, "/member?sec=loan_report&format=csv", Some(cookie));
    assert_eq!(csv.status, 200);
    assert_eq!(text(&csv).matches("PAGE-").count(), 22);
    let legacy = get(state, "/index.php?p=download_member_loan_report", Some(cookie));
    assert_eq!(legacy.status, 200);
    assert!(text(&legacy).contains("Current loans (23)"));
    rows
}

#[test]
#[ignore = "destructive: explicit disposable localhost slims_test* database required"]
fn mysql_member_writes_are_scoped_idempotent_and_password_cas_is_atomic() {
    assert_eq!(std::env::var("SLIMS_TEST_ALLOW_RESET").as_deref(), Ok("1"));
    let url = MySqlUrl::parse(&std::env::var("SLIMS_TEST_MYSQL_URL").unwrap()).unwrap();
    assert!(url.host == "127.0.0.1" && url.database.starts_with("slims_test"));
    let mut conn = Connection::connect(&url).unwrap();
    conn.query("DELETE FROM biblio_mark WHERE member_id IN ('M001','M002')").unwrap();
    conn.query("DELETE FROM reserve WHERE member_id IN ('M001','M002','M003')").unwrap();
    conn.query(&format!("UPDATE member SET is_pending=0,expire_date='2030-01-01',mpasswd='{}' WHERE member_id IN ('M001','M002')", HASH)).unwrap();
    let mut config = Config::default();
    config.member_enabled = true;
    config.member_writes_enabled = true;
    config.member_test_reservations = true;
    config.db = DbConfig::MySql(url.clone());
    #[cfg(feature = "member-sqlite")]
    { config.member_session_db = std::env::var_os("SLIMS_TEST_SESSION_DB").map(Into::into); }
    let state = AppState::new(config).unwrap();
    conn.query("UPDATE member SET last_login=NULL,last_login_ip='198.51.100.7' WHERE member_id IN ('M001','M002')").unwrap();
    assert!(text(&login(&state, "M001", "wrong")).contains("incorrect"));
    assert!(state.store.member_profile("M001").unwrap().unwrap().last_login.is_none());
    let first = login(&state, "M001", "siti rahma");
    let second = login(&state, "M001", "siti rahma");
    let other = login(&state, "M002", "siti rahma");
    assert_eq!(first.status, 302);
    assert_eq!(second.status, 302);
    assert_eq!(other.status, 302);
    assert!(state.store.member_profile("M001").unwrap().unwrap().last_login.is_some());
    let ip = conn.query("SELECT last_login_ip FROM member WHERE member_id='M001'").unwrap();
    // No trusted peer-IP plumbing yet: clear stale historical IP instead of
    // associating an old address or attacker-supplied forwarding header.
    assert!(ip.string(0, "last_login_ip").is_none());

    let a = cookie(&first); let b = cookie(&second); let c = cookie(&other);
    // More than one page: no loan is silently lost and another member's
    // records never appear, including on later pages.
    conn.query("DELETE FROM loan WHERE loan_id BETWEEN 900000 AND 900021").unwrap();
    for n in 0..22 {
        conn.query(&format!("INSERT INTO loan (loan_id,item_code,member_id,loan_date,due_date,is_lent,is_return) VALUES ({},'PAGE-{:02}','M001','2099-01-01','2099-01-02',1,0)", 900000 + n, n)).unwrap();
    }
    let current = get(&state, "/member?sec=current_loan", Some(&a));
    assert_eq!(text(&current).matches("PAGE-").count(), 20);
    assert!(text(&current).contains("page=2\">Next"));
    let page2 = get(&state, "/member?sec=current_loan&page=2", Some(&a));
    assert_eq!(text(&page2).matches("PAGE-").count(), 2);
    assert!(text(&page2).contains("Previous"));
    assert!(!text(&get(&state, "/member?sec=current_loan", Some(&c))).contains("PAGE-"));
    assert_eq!(get(&state, "/member?sec=current_loan&page=0", Some(&a)).status, 400);
    for (page, expected) in [(1, 20), (2, 2)] {
        let export = get(&state, &format!("/member?sec=current_loan&page={page}&format=csv"), Some(&a));
        assert_eq!(export.status, 200);
        assert_eq!(text(&export).matches("PAGE-").count(), expected);
        assert!(header(&export, "Content-Type").starts_with("text/csv"));
    }
    assert!(!text(&get(&state, "/member?sec=current_loan&format=csv", Some(&c))).contains("PAGE-"));
    let report = store_report_check(&state, &a);
    assert_eq!(report.len(), 25); // 23 active + 2 returned; not just first page.
    assert_eq!(report.iter().filter(|r| !r.returned).count(), 23);
    assert!(!state.store.member_loan_report("m001").unwrap().iter().any(|r| r.loan.item_code.starts_with("PAGE-")));
    conn.query("INSERT INTO loan (loan_id,item_code,member_id,loan_date,due_date,is_lent,is_return) VALUES (900022,'CASE-ALIAS','m001','2099-01-01','2099-01-02',1,0),(900023,'NOT-LENT','M001','2099-01-01','2099-01-02',0,0)").unwrap();
    let exact = state.store.member_loan_report("M001").unwrap();
    assert_eq!(exact.len(), 25);
    assert!(exact.iter().filter(|r| r.loan.item_code.starts_with("PAGE-")).all(|r| r.loan.title.is_none()));
    conn.query("DELETE FROM loan WHERE loan_id BETWEEN 910000 AND 911000").unwrap();
    let bulk = (910000..=911000).map(|id| format!("({id},'REPORT-LIMIT','M001','2099-01-01','2099-01-02',1,0)")).collect::<Vec<_>>().join(",");
    conn.query(&format!("INSERT INTO loan (loan_id,item_code,member_id,loan_date,due_date,is_lent,is_return) VALUES {bulk}")).unwrap();
    assert_eq!(state.store.member_loan_report("M001").unwrap().len(), 1001);
    for path in ["/member?sec=loan_report", "/member?sec=loan_report&format=csv"] {
        let oversized = get(&state, path, Some(&a));
        assert_eq!(oversized.status, 413);
        assert!(!oversized.headers.iter().any(|(key, _)| key == "Content-Disposition"));
        assert!(!text(&oversized).contains("REPORT-LIMIT"));
    }
    conn.query("DELETE FROM loan WHERE loan_id BETWEEN 910000 AND 911000").unwrap();
    conn.query("DELETE FROM loan WHERE loan_id BETWEEN 900000 AND 900023").unwrap();
    let book_page = get(&state, "/member?sec=bookmark", Some(&a));
    assert_eq!(book_page.status, 200, "bookmark: {} / {:?}", text(&book_page), state.store.member_bookmarks("M001", 0));
    let csrf_a = token(&book_page);
    let csrf_c = token(&get(&state, "/member?sec=bookmark", Some(&c)));
    assert_eq!(post(&state, &a, "action=bookmark_add&csrf_token=bad&biblio_id=2").status, 403);
    assert_eq!(post(&state, &a, &format!("action=bookmark_add&csrf_token={}&biblio_id=999999", csrf_a)).status, 404);
    let add = format!("action=bookmark_add&csrf_token={}&biblio_id=2", csrf_a);
    assert_eq!(post(&state, &a, &add).status, 302);
    assert_eq!(post(&state, &a, &add).status, 302);
    assert_eq!(post(&state, &c, &format!("action=bookmark_add&csrf_token={}&biblio_id=2", csrf_c)).status, 302);
    let store = Arc::new(MySqlStore::new(url.clone()));
    assert!(!store.member_record_login("M001", "stale").unwrap());
    assert!(!store.member_record_login("m001", HASH).unwrap());
    assert!(!store.member_record_login("M001' OR 1=1 --", HASH).unwrap());
    for _ in 0..3 { assert!(store.member_record_login("M001", HASH).unwrap()); }

    // Simulate an administrator resetting a password after HTTP credential
    // refresh, while the reservation is waiting to acquire its table locks.
    // Bound reservation must recheck inside that critical section, not trust
    // the earlier session/credential read.
    conn.query("DELETE FROM reserve WHERE member_id='M004'").unwrap();
    conn.query(&format!("UPDATE member SET is_pending=0,expire_date='2030-01-01',mpasswd='{}' WHERE member_id='M004'", HASH)).unwrap();
    let version: String = "b3726e14ffe64b1b951cc9556a69daa28bba54a457cec9a9a64297cc04c19811".into();
    assert_eq!(store.test_reserve_bound("M004", 2, "").unwrap(), DemoReserveResult::NotAllowed);
    assert_eq!(store.test_reserve_bound("m004", 2, &version).unwrap(), DemoReserveResult::NotAllowed);
    conn.query("LOCK TABLES member WRITE").unwrap();
    conn.query("UPDATE member SET mpasswd=MD5('admin reset') WHERE member_id='M004'").unwrap();
    let writer = store.clone(); let stale_version = version.clone();
    let (started_tx, started_rx) = std::sync::mpsc::channel();
    let pending = std::thread::spawn(move || {
        started_tx.send(()).unwrap();
        writer.test_reserve_bound("M004", 2, &stale_version)
    });
    started_rx.recv().unwrap();
    conn.query("UNLOCK TABLES").unwrap();
    assert_eq!(pending.join().unwrap().unwrap(), DemoReserveResult::NotAllowed);
    assert!(store.member_reservations("M004").unwrap().is_empty());
    conn.query(&format!("UPDATE member SET mpasswd='{}' WHERE member_id='M004'", HASH)).unwrap();
    // Policy mutations by administration are read fresh under the same locks.
    let member_type = conn.query("SELECT member_type_id FROM member WHERE member_id='M004'").unwrap().i64(0, "member_type_id").unwrap();
    let policy = conn.query(&format!("SELECT enable_reserve,reserve_limit FROM mst_member_type WHERE member_type_id={member_type}")).unwrap();
    let enabled = policy.i64(0, "enable_reserve").unwrap();
    let limit = policy.i64(0, "reserve_limit").unwrap();
    conn.query(&format!("UPDATE mst_member_type SET enable_reserve=0 WHERE member_type_id={member_type}")).unwrap();
    assert_eq!(store.test_reserve_bound("M004", 2, &version).unwrap(), DemoReserveResult::NotAllowed);
    conn.query(&format!("UPDATE mst_member_type SET enable_reserve=1,reserve_limit=0 WHERE member_type_id={member_type}")).unwrap();
    assert_eq!(store.test_reserve_bound("M004", 2, &version).unwrap(), DemoReserveResult::LimitReached);
    conn.query(&format!("UPDATE mst_member_type SET enable_reserve={enabled},reserve_limit={limit} WHERE member_type_id={member_type}")).unwrap();
    assert_eq!(store.test_reserve_bound("M004", 2, &version).unwrap(), DemoReserveResult::Created);
    conn.query("DELETE FROM reserve WHERE member_id='M004'").unwrap();

    // Bookmark add/remove must enforce credential binding under their own
    // SQL locks too, not fall back to the unbound fixture helpers.
    conn.query("DELETE FROM biblio_mark WHERE member_id='M004'").unwrap();
    assert!(store.member_bookmark_write_bound("M004", 4, &version, true).unwrap());
    assert!(store.member_bookmark_write_bound("M004", 4, &version, true).unwrap());
    assert_eq!(store.member_bookmarks("M004", 0).unwrap().len(), 1);
    conn.query("LOCK TABLES member WRITE").unwrap();
    conn.query("UPDATE member SET mpasswd=MD5('admin reset') WHERE member_id='M004'").unwrap();
    let pending: Vec<_> = [(2, true), (4, false)].into_iter().map(|(title, add)| {
        let writer = store.clone(); let version = version.clone();
        std::thread::spawn(move || writer.member_bookmark_write_bound("M004", title, &version, add))
    }).collect();
    conn.query("UNLOCK TABLES").unwrap();
    for write in pending { assert!(!write.join().unwrap().unwrap()); }
    assert_eq!(store.member_bookmarks("M004", 0).unwrap().len(), 1);
    conn.query(&format!("UPDATE member SET mpasswd='{}' WHERE member_id='M004'", HASH)).unwrap();
    assert!(!store.member_bookmark_write_bound("m004", 4, &version, false).unwrap());
    assert!(!store.member_bookmark_write_bound("M004", 4, "", false).unwrap());
    let visibility = conn.query("SELECT opac_hide FROM biblio WHERE biblio_id=2").unwrap().i64(0, "opac_hide").unwrap();
    conn.query("LOCK TABLES biblio WRITE").unwrap();
    conn.query("UPDATE biblio SET opac_hide=1 WHERE biblio_id=2").unwrap();
    let writer = store.clone(); let bound_version = version.clone();
    let hidden_add = std::thread::spawn(move || writer.member_bookmark_write_bound("M004", 2, &bound_version, true));
    conn.query("UNLOCK TABLES").unwrap();
    assert_eq!(hidden_add.join().unwrap(), Err(StoreError::NotFound));
    conn.query(&format!("UPDATE biblio SET opac_hide={visibility} WHERE biblio_id=2")).unwrap();
    assert!(store.member_bookmark_write_bound("M004", 2, &version, true).unwrap());
    let key = conn.query("SELECT id,MD5('2M004') AS expected FROM biblio_mark WHERE member_id='M004' AND biblio_id=2").unwrap();
    assert_eq!(key.string(0, "id"), key.string(0, "expected"));
    assert!(store.member_bookmark_write_bound("M004", 4, &version, false).unwrap());
    assert!(store.member_bookmark_write_bound("M004", 4, &version, false).unwrap());
    assert!(store.member_bookmark_write_bound("M004", 999999, &version, false).unwrap());
    conn.query("DELETE FROM biblio_mark WHERE member_id='M004'").unwrap();

    assert_eq!(store.member_bookmarks("M001", 0).unwrap().len(), 1);
    assert_eq!(store.member_bookmarks("M002", 0).unwrap().len(), 1);
    let rs = conn.query("SELECT id, MD5('2M001') AS expected FROM biblio_mark WHERE member_id='M001' AND biblio_id=2").unwrap();
    assert_eq!(rs.string(0, "id"), rs.string(0, "expected"));
    assert_eq!(post(&state, &a, &format!("action=bookmark_remove&csrf_token={}&biblio_id=2", csrf_a)).status, 302);
    assert!(store.member_bookmarks("M001", 0).unwrap().is_empty());
    assert_eq!(store.member_bookmarks("M002", 0).unwrap().len(), 1);
    let threads: Vec<_> = (0..8).map(|_| {
        let store = store.clone();
        std::thread::spawn(move || store.member_bookmark_add("M001", 2).unwrap())
    }).collect();
    for t in threads { t.join().unwrap(); }
    assert_eq!(store.member_bookmarks("M001", 0).unwrap().len(), 1);
    assert!(store.member_bookmarks("M001' OR 1=1 --", 0).unwrap().is_empty());
    assert!(!store.member_change_password("M001' OR 1=1 --", HASH, "bad").unwrap());
    assert_eq!(store.test_reserve("M001' OR 1=1 --", 2).unwrap(), DemoReserveResult::NotAllowed);
    assert_eq!(store.test_reserve("missing", 2).unwrap(), DemoReserveResult::NotAllowed);
    // Status changes after login must prevent writes, without preventing
    // account/loan access. Restore fixture state before subsequent cases.
    for update in ["is_pending=1", "is_pending=0,expire_date='2000-01-01'"] {
        conn.query(&format!("UPDATE member SET {} WHERE member_id='M001'", update)).unwrap();
        assert_eq!(store.test_reserve("M001", 2).unwrap(), DemoReserveResult::NotAllowed);
        assert_eq!(post(&state, &a, &format!("action=test_reserve&csrf_token={}&biblio_id=2", csrf_a)).status, 200);
        assert!(store.test_reservations("M001").unwrap().is_empty());
        assert_eq!(get(&state, "/member?sec=my_account", Some(&a)).status, 200);
    }
    conn.query("UPDATE member SET is_pending=0,expire_date='2030-01-01' WHERE member_id='M001'").unwrap();
    assert_eq!(store.test_reserve("M001", 1).unwrap(), DemoReserveResult::Unavailable);
    assert_eq!(post(&state, &a, "action=test_reserve&csrf_token=bad&biblio_id=2").status, 403);
    assert_eq!(post(&state, &a, &format!("action=basket_add&csrf_token={}&biblio_id=2", csrf_a)).status, 302);
    let basket = get(&state, "/member?sec=title_basket", Some(&a));
    assert!(text(&basket).contains("Reserve in test database"));
    assert!(text(&basket).contains("name=\"action\" value=\"test_reserve\""));
    assert!(!text(&basket).contains("Reserve in demo"));
    let reserve = format!("action=test_reserve&csrf_token={}&biblio_id=2", csrf_a);
    // Expiration day is inclusive, using the same UTC clock as the Rust policy.
    conn.query(&format!("UPDATE member SET expire_date='{}' WHERE member_id='M001'", slims9::date::Date::today().iso())).unwrap();
    assert_eq!(post(&state, &a, &reserve).status, 302);
    conn.query("UPDATE member SET expire_date='2030-01-01' WHERE member_id='M001'").unwrap();
    assert!(text(&post(&state, &a, &reserve)).contains("already reserved"));
    assert!(text(&get(&state, "/member?sec=title_basket", Some(&a))).contains("1 of 20 titles"));
    let reservations = store.test_reservations("M001").unwrap();
    assert_eq!(reservations.len(), 1);
    assert_eq!(reservations[0].biblio_id, 2);
    assert!(text(&get(&state, "/member?sec=test_reservations", Some(&c))).contains("Use only test data"));
    assert!(store.test_reservations("M002").unwrap().is_empty());
    // Two copies of title 2; selection cycles across them.
    assert_eq!(post(&state, &c, &format!("action=test_reserve&csrf_token={}&biblio_id=2", csrf_c)).status, 302);
    assert_ne!(store.test_reservations("M001").unwrap()[0].item_code, store.test_reservations("M002").unwrap()[0].item_code);
    let threads: Vec<_> = [4, 6, 8, 12, 13, 14, 15].into_iter().map(|title| {
        let store = store.clone();
        std::thread::spawn(move || store.test_reserve("M001", title).unwrap())
    }).collect();
    let results: Vec<_> = threads.into_iter().map(|t| t.join().unwrap()).collect();
    assert_eq!(results.iter().filter(|r| **r == DemoReserveResult::Created).count(), 1);
    assert_eq!(results.iter().filter(|r| **r == DemoReserveResult::LimitReached).count(), 6);
    assert_eq!(store.test_reservations("M001").unwrap().len(), 2);
    // The stored-reservation page must work with all test writes OFF.
    let mut read_config = Config::default();
    read_config.member_enabled = true;
    read_config.db = DbConfig::MySql(url.clone());
    let read_only = AppState::new(read_config).unwrap();
    let logged_in = login(&read_only, "M001", "siti rahma");
    assert_eq!(logged_in.status, 302);
    let reader_cookie = cookie(&logged_in);
    let list = get(&read_only, "/member?sec=reservation_list", Some(&reader_cookie));
    assert_eq!(list.status, 200);
    assert!(text(&list).contains("My Stored Reservations"));
    assert!(text(&list).contains("Read-only"));
    assert!(text(&list).contains("Linux In a Nutshell"));
    assert_eq!(read_only.store.member_reservations("M001").unwrap().len(), 2);
    assert_eq!(read_only.store.member_reservations("M002").unwrap().len(), 1);
    assert!(read_only.store.member_reservations("M001' OR 1=1 --").unwrap().is_empty());
    assert!(read_only.store.member_reservations("m001").unwrap().is_empty());
    assert_eq!(post(&read_only, &reader_cookie, &format!("action=test_reserve&csrf_token={}&biblio_id=2", token(&list))).status, 404);
    // Bulk writes use the current session basket, report partial success and
    // preserve it. M003 is isolated from earlier per-member limit scenarios.
    let batch_login = login(&state, "M003", "siti rahma");
    assert_eq!(batch_login.status, 302);
    let batch_cookie = cookie(&batch_login);
    let batch_token = token(&get(&state, "/member?sec=title_basket", Some(&batch_cookie)));
    for title in [2, 4, 6] {
        assert_eq!(post(&state, &batch_cookie, &format!("action=basket_add&csrf_token={batch_token}&biblio_id={title}")).status, 302);
    }
    let batch_body = format!("action=basket_reserve&csrf_token={batch_token}");
    let batch = post(&state, &batch_cookie, &batch_body);
    assert_eq!(batch.status, 200);
    assert!(text(&batch).contains("<td>2</td><td>Reserved</td>"));
    assert!(text(&batch).contains("<td>4</td><td>Reserved</td>"));
    assert!(text(&batch).contains("<td>6</td><td>Reserve limit reached</td>"));
    assert_eq!(store.member_reservations("M003").unwrap().len(), 2);
    assert!(text(&get(&state, "/member?sec=title_basket", Some(&batch_cookie))).contains("3 of 20 titles"));
    assert_eq!(post(&state, &batch_cookie, &batch_body).status, 200);
    assert_eq!(store.member_reservations("M003").unwrap().len(), 2);
    let change = |old: &str, new: &str, confirm: &str| format!("action=change_password&csrf_token={}&current_password={}&new_password={}&confirm_password={}", csrf_a, url_encode(old), url_encode(new), url_encode(confirm));
    assert_eq!(post(&state, &a, &change("wrong", "NewPassword1!", "NewPassword1!")).status, 200);
    assert!(text(&post(&state, &a, &change("siti rahma", "weak", "weak"))).contains("Passwords must match"));
    let changed = post(&state, &a, &change("siti rahma", "NewPassword1!", "NewPassword1!"));
    assert_eq!(changed.status, 200);
    assert!(text(&changed).contains("Sign in again"));
    assert!(header(&changed, "Set-Cookie").contains("Max-Age=0"));
    assert_eq!(get(&state, "/member", Some(&b)).status, 302);
    assert!(text(&get(&state, "/member", Some(&c))).contains("Hi, Budi"));
    assert!(text(&login(&state, "M001", "siti rahma")).contains("incorrect"));
    assert_eq!(login(&state, "M001", "NewPassword1!").status, 302);
    let old = store.member_credential("M001").unwrap().unwrap().password_hash;
    let threads: Vec<_> = (0..8).map(|n| {
        let store = store.clone(); let old = old.clone();
        std::thread::spawn(move || store.member_change_password("M001", &old, &format!("test-hash-{n}")).unwrap())
    }).collect();
    assert_eq!(threads.into_iter().map(|t| t.join().unwrap()).filter(|won| *won).count(), 1);
    // A legacy native account is upgraded once, with PHP-compatible bcrypt.
    conn.query("UPDATE member SET mpasswd=MD5('legacy password'), last_update='2000-01-01' WHERE member_id='M002'").unwrap();
    assert!(text(&login(&state, "M002", "wrong")).contains("incorrect"));
    let upgraded = login(&state, "M002", "legacy password");
    assert_eq!(upgraded.status, 302);
    assert!(store.member_credential("M002").unwrap().unwrap().password_hash.starts_with("$2b$10$"));
    let date = conn.query("SELECT last_update FROM member WHERE member_id='M002'").unwrap();
    assert_ne!(date.string(0, "last_update").unwrap(), "2000-01-01");
    assert_eq!(get(&state, "/member", Some(&c)).status, 302);
    // Do not silently truncate legacy passwords when converting to bcrypt.
    conn.query("UPDATE member SET mpasswd=MD5(REPEAT('a',73)) WHERE member_id='M002'").unwrap();
    assert!(text(&login(&state, "M002", &"a".repeat(73))).contains("incorrect"));
    assert_eq!(store.member_credential("M002").unwrap().unwrap().password_hash.len(), 32);
    let (proxy_url, proxy) = discard_one_write_response(&url);
    let proxy_store = MySqlStore::new(proxy_url);
    assert!(matches!(proxy_store.member_bookmark_add("M001", 4), Err(StoreError::WriteUnknown)));
    proxy.join().unwrap();
    assert!(store.member_bookmarks("M001", 0).unwrap().iter().any(|b| b.biblio_id == 4));
    // Reset the fixture, so a subsequent run starts clean.
    conn.query("DELETE FROM biblio_mark WHERE member_id IN ('M001','M002')").unwrap();
    conn.query("DELETE FROM reserve WHERE member_id IN ('M001','M002','M003')").unwrap();
    conn.query(&format!("UPDATE member SET is_pending=0,expire_date='2030-01-01',mpasswd='{}' WHERE member_id IN ('M001','M002')", HASH)).unwrap();
    let (login_proxy_url, login_proxy) = discard_one_write_response(&url);
    conn.query("UPDATE member SET last_login='2000-01-01 00:00:00' WHERE member_id='M001'").unwrap();
    let login_proxy_store = MySqlStore::new(login_proxy_url);
    assert!(matches!(login_proxy_store.member_record_login("M001", HASH), Err(StoreError::WriteUnknown)));
    login_proxy.join().unwrap();
    assert_ne!(store.member_profile("M001").unwrap().unwrap().last_login.as_deref(), Some("2000-01-01 00:00:00"));
    // Isolate this scenario from earlier deliberately exhausted login budgets.
    let state = AppState::new(state.config.clone()).unwrap();
    let x = login(&state, "M001", "siti rahma");
    let y = login(&state, "M001", "siti rahma");
    let z = login(&state, "M002", "siti rahma");
    assert_eq!(x.status, 302);
    assert_eq!(y.status, 302);
    assert_eq!(z.status, 302);
    let account = get(&state, "/member?sec=my_account", Some(&cookie(&x)));
    assert_eq!(post(&state, &cookie(&x), &format!("action=logout_all&csrf_token={}", token(&account))).status, 302);
    assert!(text(&get(&state, "/member", Some(&cookie(&y)))).contains("Library Member Login"));
    assert!(text(&get(&state, "/member", Some(&cookie(&z)))).contains("Hi, Budi"));

}
