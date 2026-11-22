//! Opt-in protocol integration test; run against tools/fake_mysql.py or a
//! disposable database loaded with the same demo fixture, never production.
use slims9::config::MySqlUrl;
use slims9::store::mysql::MySqlStore;
use slims9::store::Store;

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
    assert!(store.member_profile("M001' OR 1=1 --").unwrap().is_none());
}

#[cfg(feature = "member-crypt")]
#[test]
#[ignore = "requires --demo-member-login on the disposable SQL-speaking double"]
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
    assert!(state.store.member_credential("M001' OR 1=1 --").unwrap().is_none());
}
