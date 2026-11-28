//! Run only against a NEW fixture from tools/setup_member_mysql_test.py.
use slims9::config::MySqlUrl;
use slims9::store::mysql::MySqlStore;
use slims9::store::Store;

#[test]
#[ignore = "requires fresh disposable setup_member_mysql_test.py content fixture"]
fn published_content_scope_literal_search_and_pagination_through_mysql() {
    let raw = std::env::var("SLIMS_TEST_MYSQL_URL").expect("disposable fixture URL");
    let store = MySqlStore::new(MySqlUrl::parse(&raw).unwrap());
    let first = store.public_news("Fixture news", 0).unwrap();
    assert_eq!(first.len(), 11);
    assert_eq!(first[0].path, "fixture-13");
    assert_eq!(store.public_news("Fixture news", 10).unwrap().len(), 3);
    assert_eq!(store.public_news("", 0).unwrap()[0].path, "fixture-13");
    for needle in ["%", "' OR 1=1 --", "\\", "_not_a_wildcard_"] {
        assert!(store.public_news(needle, 0).unwrap().is_empty());
    }
    for path in ["fixture-draft", "adminfixture", "headerinfo", "bad/path", "fixture-1' OR 1=1"] {
        assert!(store.public_content(path).unwrap().is_none());
    }
    let page = store.public_content("fixture-1").unwrap().unwrap();
    assert_eq!(page.title, "Fixture news 1");
    assert!(page.is_news);
    assert!(store.public_content("FIXTURE-1").unwrap().is_none());
    assert!(store.public_news("", 100_001).is_err());
}
