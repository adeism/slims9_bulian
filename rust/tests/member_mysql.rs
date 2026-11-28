//! Opt-in protocol integration test; run against tools/fake_mysql.py or a
//! disposable database loaded with the same demo fixture, never production.
use slims9::config::MySqlUrl;
use slims9::store::mysql::MySqlStore;
use slims9::store::Store;

#[test]
#[ignore = "requires SLIMS_TEST_MYSQL_URL pointing to a disposable MySQL fixture"]
fn collection_filter_works_with_default_mysql_sql_mode() {
    let url = std::env::var("SLIMS_TEST_MYSQL_URL").expect("set SLIMS_TEST_MYSQL_URL");
    let store = MySqlStore::new(MySqlUrl::parse(&url).unwrap());
    store.biblio_by_coll_type("Textbook", 20).unwrap();
}

#[test]
#[ignore = "requires SLIMS_TEST_MYSQL_URL pointing to the disposable demo fixture"]
fn member_loan_queries_use_the_wire_and_isolate_members() {
    let url = std::env::var("SLIMS_TEST_MYSQL_URL").expect("set SLIMS_TEST_MYSQL_URL");
    let store = MySqlStore::new(MySqlUrl::parse(&url).unwrap());
    let current = store.member_loans("M001", false).unwrap();
    let history = store.member_loans("M001", true).unwrap();
    assert_eq!(current.len(), 1);
    assert_eq!(history.len(), 2);
    assert!(current[0].title.is_some());
    assert!(current[0].return_date.is_none());
    assert!(history.iter().all(|loan| loan.return_date.is_some()));
    assert!(history[0].loan_date >= history[1].loan_date);
    assert!(store.member_loans("M001' OR 1=1 --", false).unwrap().is_empty());
    assert!(store.member_loans("M001\\' OR 1=1 --", false).unwrap().is_empty());
    assert!(store.member_loans("missing", true).unwrap().is_empty());
    let profile = store.member_profile("M001").unwrap().unwrap();
    assert_eq!(profile.name, "Siti Rahma");
    assert_eq!(profile.email.as_deref(), Some("siti.rahma@example.org"));
    let report = store.member_loan_report("M001").unwrap();
    assert_eq!(report.len(), 3);
    assert_eq!(report.iter().filter(|r| !r.returned).count(), 1);
    assert_eq!(report.iter().filter(|r| r.returned).count(), 2);
    assert!(!report[0].returned);
    assert!(store.member_loan_report("m001").unwrap().is_empty());
    assert!(store.member_loan_report("M001' OR 1=1 --").unwrap().is_empty());
    assert!(store.member_loan_report("missing").unwrap().is_empty());
    assert!(store.member_profile("M001' OR 1=1 --").unwrap().is_none());
}

#[cfg(feature = "member-crypt")]
#[test]
#[ignore = "requires fresh setup_member_mysql_test.py fixture or --demo-member-login wire double"]
fn credential_lookup_and_member_flow_through_wire() {
    use slims9::app::AppState;
    use slims9::config::{Config, DbConfig};
    use slims9::http::Request;
    let mut config = Config::default();
    config.member_enabled = true;
    #[cfg(feature = "member-sqlite")]
    { config.member_session_db = std::env::var_os("SLIMS_TEST_SESSION_DB").map(Into::into); }
    config.db = DbConfig::MySql(MySqlUrl::parse(&std::env::var("SLIMS_TEST_MYSQL_URL").unwrap()).unwrap());
    let state = AppState::new(config).unwrap();
    let page = state.handle(&Request::new("GET", "/member"));
    let cookie = page.headers.iter().find(|(key, _)| key == "Set-Cookie").unwrap().1.split(';').next().unwrap();
    let page_text = std::str::from_utf8(&page.body).unwrap();
    let csrf = page_text.split("name=\"csrf_token\" value=\"").nth(1).unwrap().split('"').next().unwrap();
    let mut req = Request::new("POST", "/member");
    req.headers.push(("Cookie".into(), cookie.into()));
    req.headers.push(("Content-Type".into(), "application/x-www-form-urlencoded".into()));
    req.body = format!("action=login&csrf_token={}&memberID=M001&memberPassWord=siti+rahma", csrf).into_bytes();
    let login = state.handle(&req);
    assert_eq!(login.status, 302);
    let authenticated = login.headers.iter().find(|(key, _)| key == "Set-Cookie").unwrap().1.split(';').next().unwrap();
    let mut history = Request::new("GET", "/member?sec=loan_history");
    history.headers.push(("Cookie".into(), authenticated.into()));
    let response = state.handle(&history);
    assert_eq!(response.status, 200);
    assert!(String::from_utf8(response.body).unwrap().contains("My Loan History"));
    let mut profile = Request::new("GET", "/member?sec=my_account");
    profile.headers.push(("Cookie".into(), authenticated.into()));
    let response = state.handle(&profile);
    assert_eq!(response.status, 200);
    let text = String::from_utf8(response.body).unwrap();
    assert!(text.contains("siti.rahma@example.org"));
    assert!(!text.contains("budi.santoso@example.org"));
    let first = state.store.member_bookmarks("M001", 0).unwrap();
    assert_eq!(first.len(), 21);
    assert!(first.iter().all(|b| b.biblio_id == 2));
    assert_eq!(state.store.member_bookmarks("M001", 20).unwrap().len(), 2);
    assert_eq!(state.store.member_bookmarks("M002", 0).unwrap()[0].biblio_id, 4);
    assert!(state.store.member_bookmarks("M001' OR 1=1 --", 0).unwrap().is_empty());
    assert!(state.store.member_bookmarks("M001", 100_001).is_err());
    let mut bookmarks = Request::new("GET", "/member?sec=bookmark");
    bookmarks.headers.push(("Cookie".into(), authenticated.into()));
    let response = state.handle(&bookmarks);
    assert_eq!(response.status, 200);
    assert!(response.headers.iter().any(|(key, value)| key == "Cache-Control" && value.contains("no-store")));
    let text = String::from_utf8(response.body).unwrap();
    assert_eq!(text.matches("Linux In a Nutshell</a>").count(), 20);
    assert!(text.contains("page=2\">Next"));
    let mut next = Request::new("GET", "/member?sec=bookmark&page=2");
    next.headers.push(("Cookie".into(), authenticated.into()));
    let text = String::from_utf8(state.handle(&next).body).unwrap();
    assert_eq!(text.matches("Linux In a Nutshell</a>").count(), 2);
    assert!(text.contains("Previous"));
    let mut basket = Request::new("GET", "/member?sec=title_basket");
    basket.headers.push(("Cookie".into(), authenticated.into()));
    let response = state.handle(&basket);
    let body = std::str::from_utf8(&response.body).unwrap();
    let token = body.split("name=\"csrf_token\" value=\"").nth(1).unwrap().split('"').next().unwrap();
    let mut add = Request::new("POST", "/member");
    add.headers.push(("Cookie".into(), authenticated.into()));
    add.headers.push(("Content-Type".into(), "application/x-www-form-urlencoded".into()));
    add.body = format!("action=basket_add&csrf_token={}&biblio_id=2", token).into_bytes();
    assert_eq!(state.handle(&add).status, 302);
    let response = state.handle(&basket);
    assert_eq!(response.status, 200);
    assert!(String::from_utf8(response.body).unwrap().contains("Linux In a Nutshell"));
    assert!(state.store.member_credential("M001' OR 1=1 --").unwrap().is_none());
}
