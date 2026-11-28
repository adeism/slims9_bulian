use std::sync::Arc;
use slims9::app::AppState;
use slims9::config::Config;
use slims9::http::{self, Request};
use slims9::librarians::portrait_url;
use slims9::store::memory::MemoryStore;
use slims9::store::seed::{SeedData, SqlValue, Table};
use slims9::store::Store;

fn fixture() -> MemoryStore {
    let columns = ["user_id", "username", "passwd", "2fa", "forgot", "last_login_ip", "realname", "email", "user_image", "user_type", "social_media"];
    let mut table = Table { columns: columns.iter().map(|c| c.to_string()).collect(), rows: vec![] };
    // Invalid/private types would sort ahead of public types without prefiltering.
    for id in 1..=25 {
        let role = if id < 4 { 3 } else if id < 6 { 2 } else { 1 };
        table.rows.push(vec![SqlValue::Int(id), SqlValue::Text(format!("PRIVATE_LOGIN_{id}")),
            SqlValue::Text("PASSWORD_SECRET".into()), SqlValue::Text("TOTP_SECRET".into()),
            SqlValue::Text("RESET_SECRET".into()), SqlValue::Text("PRIVATE_IP".into()),
            SqlValue::Text(format!("Librarian {id} <script>alert(1)</script>")),
            SqlValue::Text("<img src=x onerror=alert(1)>@example.invalid".into()),
            SqlValue::Text("../../private.svg".into()), SqlValue::Int(role),
            SqlValue::Text("SERIALIZED_PRIVATE_SOCIAL".into())]);
    }
    for kind in [SqlValue::Null, SqlValue::Int(0), SqlValue::Int(-1), SqlValue::Int(99)] {
        let mut row = table.rows[0].clone(); row[9] = kind; table.rows.push(row);
    }
    let mut seed = SeedData::default(); seed.tables.insert("user".into(), table);
    MemoryStore::from_seed(&seed, false)
}

fn state() -> AppState {
    let mut state = AppState::new(Config::default()).unwrap();
    state.store = Box::new(fixture()); state
}

#[test]
fn public_roles_filter_before_limit_with_deterministic_order() {
    let rows = fixture().public_librarians().unwrap();
    assert_eq!(rows.len(), 20);
    assert!(rows[0].name.starts_with("Librarian 4 "));
    assert!(rows[1].name.starts_with("Librarian 5 "));
    assert!(rows[2].name.starts_with("Librarian 6 "));
    assert!(rows[19].name.starts_with("Librarian 23 "));
    assert_eq!(rows[0].role.label(), "Senior Librarian");
    assert_eq!(rows[2].role.label(), "Librarian");
}

#[test]
fn portrait_paths_are_local_raster_basenames_only() {
    for image in [None, Some(""), Some("../person.jpg"), Some("a/b.png"), Some("a.svg"),
                  Some("https://example.org/a.jpg"), Some("a.png?x"), Some("a%2ejpg"),
                  Some("a\" onerror=x.png"), Some(".png"), Some("a\\b.png")] {
        assert_eq!(portrait_url(image), "/images/persons/person.png");
    }
    assert_eq!(portrait_url(Some("staff-2_ABC.JPG")), "/images/persons/staff-2_ABC.JPG");
}

#[test]
fn directory_routes_escape_fields_and_never_render_private_columns() {
    let state = state();
    for target in ["/librarians", "/index.php?p=librarian"] {
        let response = state.handle(&Request::new("GET", target));
        assert_eq!(response.status, 200);
        assert!(response.headers.iter().any(|(k,v)| k == "Cache-Control" && v == "no-store"));
        let text = String::from_utf8(response.body).unwrap();
        assert!(text.contains("Senior Librarian"));
        assert!(text.contains("&lt;script&gt;"));
        assert!(text.contains("&lt;img src=x onerror=alert(1)&gt;"));
        assert!(!text.contains("<script>"));
        for private in ["PRIVATE_LOGIN", "PASSWORD_SECRET", "TOTP_SECRET", "RESET_SECRET", "PRIVATE_IP", "SERIALIZED_PRIVATE_SOCIAL", "../../private.svg"] {
            assert!(!text.contains(private), "{private}");
        }
        assert_eq!(text.matches("<article ").count(), 20);
    }
}

#[test]
fn no_role_selector_override_or_mutations() {
    let state = state();
    for target in ["/librarians?user_type=3", "/librarians?user_id=1", "/librarians?limit=999",
                   "/index.php?p=librarian&p=librarian", "/index.php?p=librarian&user_type=3"] {
        assert_eq!(state.handle(&Request::new("GET", target)).status, 400);
    }
    for target in ["/librarians", "/index.php?p=librarian"] {
        let response = state.handle(&Request::new("POST", target));
        assert_eq!(response.status, 405);
        assert!(response.headers.iter().any(|(k,v)| k == "Allow" && v == "GET, HEAD"));
    }
}

#[test]
fn no_public_staff_is_a_successful_empty_directory() {
    let mut state = state(); state.store = Box::new(MemoryStore::default());
    let response = state.handle(&Request::new("GET", "/librarians"));
    assert_eq!(response.status, 200);
    assert!(String::from_utf8(response.body).unwrap().contains("No librarian data yet."));
}

#[test]
fn tcp_head_matches_get_headers_and_suppresses_body() {
    let (base, _server) = http::spawn_test_server(Arc::new(state()).handler()).unwrap();
    for target in ["/librarians", "/index.php?p=librarian"] {
        let get = http::request(&(base.clone() + target), "GET", None).unwrap();
        let head = http::request(&(base.clone() + target), "HEAD", None).unwrap();
        assert_eq!(get.status, 200); assert_eq!(head.status, 200);
        assert!(head.body.is_empty());
        let stable = |headers: Vec<(String, String)>| headers.into_iter()
            .filter(|(k, _)| !k.eq_ignore_ascii_case("X-Request-ID")).collect::<Vec<_>>();
        assert_eq!(stable(get.headers), stable(head.headers));
    }
}
