use slims9::app::AppState;
use slims9::config::Config;
use slims9::content::{formatting, public_path};
use slims9::http::{self, Request};
use slims9::store::memory::MemoryStore;
use slims9::store::seed::{SeedData, SqlValue, Table};
use slims9::store::Store;
use std::sync::Arc;

fn store() -> MemoryStore {
    let mut table = Table {
        columns: ["content_id", "content_title", "content_desc", "content_path", "is_news", "is_draft", "last_update", "publish_date"]
            .iter().map(|s| s.to_string()).collect(), rows: vec![],
    };
    for id in 1..=13 {
        table.rows.push(vec![SqlValue::Int(id), SqlValue::Text(format!("News {id}")),
            SqlValue::Text("<p>Library café <strong>update</strong></p><script>alert(1)</script>".into()),
            SqlValue::Text(format!("news-{id}")), SqlValue::Int(1), SqlValue::Int(0),
            SqlValue::Text("2026-01-01 12:00:00".into()), SqlValue::Null]);
    }
    for (id, path, draft, news) in [(14, "draft", 1, 1), (15, "adminhome", 0, 1),
                                    (16, "headerinfo", 0, 1), (17, "about", 0, 0),
                                    (18, "bad/path", 0, 1)] {
        table.rows.push(vec![SqlValue::Int(id), SqlValue::Text(format!("Private {path}")),
            SqlValue::Text("<p>About the library</p>".into()), SqlValue::Text(path.into()),
            SqlValue::Int(news), SqlValue::Int(draft), SqlValue::Text("2026-02-01".into()), SqlValue::Null]);
    }
    let mut null_draft = table.rows[0].clone();
    null_draft[3] = SqlValue::Text("null-draft".into());
    null_draft[5] = SqlValue::Null;
    table.rows.push(null_draft);
    let mut data = SeedData::default();
    data.tables.insert("content".into(), table);
    MemoryStore::from_seed(&data, false)
}

fn state() -> AppState {
    let mut state = AppState::new(Config::default()).unwrap();
    state.store = Box::new(store());
    state
}

#[test]
fn published_rows_scoped_before_pagination_and_ties_are_stable() {
    let s = store();
    let first = s.public_news("", 0).unwrap();
    assert_eq!(first.len(), 11);
    assert_eq!(first[0].id, 13);
    assert_eq!(s.public_news("", 10).unwrap().len(), 3);
    assert_eq!(s.public_news("News 12", 0).unwrap()[0].id, 12);
    assert!(s.public_news("%", 0).unwrap().is_empty());
    assert!(s.public_news("' OR 1=1 --", 0).unwrap().is_empty());
    assert!(s.public_news("", 100_001).is_err());
    assert!(s.public_news(&"a".repeat(201), 0).is_err());
    for path in ["draft", "null-draft", "adminhome", "headerinfo", "bad/path", "ABOUT"] {
        assert!(s.public_content(path).unwrap().is_none(), "{path}");
    }
    assert!(s.public_content("about").unwrap().is_some());
}

#[test]
fn minimal_formatting_never_emits_active_stored_markup() {
    let input = "<p>Hi <strong>reader</strong></p><img src=x onerror=alert(1)><svg/onload=alert(1)><a href='javascript:x'>link</a>&lt;script&gt;<script>x</script><!--x--><p onclick=x>bad</p>";
    let output = formatting(input);
    assert!(output.starts_with("<p>Hi <strong>reader</strong></p>"));
    for tag in ["<img", "<svg", "<a ", "<script", "<!--", "<p onclick"] { assert!(!output.contains(tag)); }
    assert!(output.contains("&amp;lt;script&amp;gt;"));
    assert_eq!(formatting("é <unfinished"), "é &lt;unfinished");
    for path in ["", "../about", "admin", "adminhome", "headerinfo", "About", "a/b", "a?b", "a b"] {
        assert!(!public_path(path), "{path}");
    }
}

#[test]
fn news_content_and_legacy_routes_render_safely() {
    let s = state();
    for target in ["/news", "/index.php?p=news"] {
        let r = s.handle(&Request::new("GET", target));
        assert_eq!(r.status, 200);
        let text = String::from_utf8(r.body).unwrap();
        assert!(text.contains("Library News"));
        assert!(text.contains("Next</a>"));
        assert!(!text.contains("Private draft"));
        assert!(!text.contains("<script>"));
    }
    for target in ["/pages/about", "/index.php?p=about"] {
        let r = s.handle(&Request::new("GET", target));
        assert_eq!(r.status, 200);
        assert!(String::from_utf8(r.body).unwrap().contains("<p>About the library</p>"));
        assert!(r.headers.iter().any(|(k, v)| k == "Content-Security-Policy" && v.contains("default-src 'none'")));
    }
    for target in ["/pages/draft", "/pages/adminhome", "/index.php?p=adminhome", "/pages/%2e%2e%2fabout"] {
        assert_eq!(s.handle(&Request::new("GET", target)).status, 404);
    }
    let r = s.handle(&Request::new("GET", "/news?page=2&keywords=Library"));
    let text = String::from_utf8(r.body).unwrap();
    assert!(text.contains("Previous</a>"));
    assert!(!text.contains("Next</a>"));
}

#[test]
fn invalid_queries_and_mutations_fail_without_falling_into_catalog_search() {
    let s = state();
    for target in ["/news?page=0", "/news?page=10002", "/news?page=wat", "/news?page=1&page=2",
                   "/index.php?p=news&p=about", "/news?keywords=%00x"] {
        assert_eq!(s.handle(&Request::new("GET", target)).status, 400, "{target}");
    }
    for target in ["/news", "/pages/about", "/index.php?p=news", "/index.php?p=about"] {
        let r = s.handle(&Request::new("POST", target));
        assert_eq!(r.status, 405);
        assert!(r.headers.iter().any(|(k, v)| k == "Allow" && v == "GET, HEAD"));
    }
    let r = s.handle(&Request::new("GET", "/news?keywords=%3Cscript%3E"));
    let text = String::from_utf8(r.body).unwrap();
    assert!(!text.contains("<script>"));
    assert!(text.contains("&lt;script&gt;"));
}

#[test]
fn actual_http_head_has_no_body_and_same_content_length() {
    let (base, _server) = http::spawn_test_server(Arc::new(state()).handler()).unwrap();
    for path in ["/news", "/pages/about", "/index.php?p=news", "/index.php?p=about"] {
        let get = http::request(&(base.clone() + path), "GET", None).unwrap();
        let head = http::request(&(base.clone() + path), "HEAD", None).unwrap();
        assert_eq!(head.status, get.status);
        assert!(head.body.is_empty());
        assert_eq!(head.headers.iter().find(|(k,_)| k.eq_ignore_ascii_case("Content-Length")),
                   get.headers.iter().find(|(k,_)| k.eq_ignore_ascii_case("Content-Length")));
    }
}

#[test]
fn oversized_published_content_fails_without_partial_body() {
    let table = Table {
        columns: ["content_id", "content_title", "content_desc", "content_path", "is_news", "is_draft"]
            .iter().map(|s| s.to_string()).collect(),
        rows: vec![vec![SqlValue::Int(1), SqlValue::Text("Huge content".into()),
                       SqlValue::Text("DO_NOT_RENDER".repeat(25_000)), SqlValue::Text("huge".into()),
                       SqlValue::Int(1), SqlValue::Int(0)]],
    };
    let mut data = SeedData::default();
    data.tables.insert("content".into(), table);
    let mut state = state();
    state.store = Box::new(MemoryStore::from_seed(&data, false));
    for target in ["/news", "/pages/huge"] {
        let response = state.handle(&Request::new("GET", target));
        assert_eq!(response.status, 413);
        assert!(!String::from_utf8(response.body).unwrap().contains("DO_NOT_RENDER"));
    }
}
