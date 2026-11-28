//! The behavior gate.
//!
//! `fixtures/behavior/cases.json` is the recording: every case is a request and
//! what the endpoint must answer. `cargo test` replays all of them against an
//! in-process server. `tools/gate.py` replays the same file against a running
//! binary (Rust or a future build), and `tools/record.py` records a live SLiMS
//! PHP instance into the same shape, which is how the gate becomes a real
//! before/after comparison once the original can be run.

use std::sync::Arc;

use slims9::app::AppState;
use slims9::config::Config;
use slims9::date::Date;
use slims9::http::{self, request};
use slims9::json::{self, Json};

#[test]
fn behavior_gate() {
    let config = Config::default();
    let state = Arc::new(AppState::new(config).expect("state"));
    let (base, _server) = http::spawn_test_server(state.handler()).expect("server");

    let fixture_path = concat!(env!("CARGO_MANIFEST_DIR"), "/fixtures/behavior/cases.json");
    let fixture = std::fs::read_to_string(fixture_path).expect("read cases.json");
    let document = json::parse(&fixture).expect("parse cases.json");
    let cases = document
        .get("cases")
        .and_then(Json::as_array)
        .expect("cases array");

    let today = Date::today().iso();
    let year = Date::today().year.to_string();
    let mut failures = Vec::new();
    let mut passed = 0;
    let mut skipped = 0;

    for case in cases {
        let name = string_field(case, "name").unwrap_or_else(|| "<unnamed>".into());
        // The in-process server is the memory backend; cases that assert the
        // MySQL backend's own values (health, ...) are replayed by tools/gate.py.
        if let Some(backends) = case.get("backends").and_then(Json::as_array) {
            let applies = backends
                .iter()
                .any(|backend| backend.as_str() == Some("memory"));
            if !applies {
                skipped += 1;
                continue;
            }
        }
        let method = string_field(case, "method").unwrap_or_else(|| "GET".into());
        let path = case
            .get("path")
            .or_else(|| case.get("path_template"))
            .and_then(Json::as_str)
            .unwrap_or("/")
            .replace("{today}", &today)
            .replace("{year}", &year);

        let response = match request(&format!("{}{}", base, path), &method, None) {
            Ok(response) => response,
            Err(error) => {
                failures.push(format!("{}: request failed: {}", name, error));
                continue;
            }
        };
        let body = String::from_utf8_lossy(&response.body).into_owned();
        let expect = case.get("expect").cloned().unwrap_or(Json::obj());
        let mut problems = Vec::new();

        let expected_status = expect.get("status").and_then(Json::as_i64).unwrap_or(200) as u16;
        if response.status != expected_status {
            problems.push(format!(
                "status: expected {}, got {} ({})",
                expected_status,
                response.status,
                truncate(&body)
            ));
        }

        if let Some(expected_json) = expect.get("json") {
            match json::parse(&body) {
                Ok(actual) => {
                    if actual != *expected_json {
                        problems.push(format!(
                            "json mismatch\n  expected: {}\n  actual:   {}",
                            expected_json.to_string_utf8(),
                            actual.to_string_utf8()
                        ));
                    }
                }
                Err(error) => problems.push(format!("response is not JSON: {}", error)),
            }
        }

        if let Some(shape) = expect.get("shape") {
            match json::parse(&body) {
                Ok(actual) => {
                    if let Err(error) = check_shape(&actual, shape, "$") {
                        problems.push(format!("shape: {}", error));
                    }
                }
                Err(error) => problems.push(format!("response is not JSON: {}", error)),
            }
        }

        if let Some(needles) = expect.get("contains").and_then(Json::as_array) {
            for needle in needles {
                let needle = needle.as_str().unwrap_or_default();
                if !body.contains(needle) {
                    problems.push(format!("body does not contain {:?}", needle));
                }
            }
        }

        if problems.is_empty() {
            passed += 1;
        } else {
            failures.push(format!("{} ({} {}):\n  {}", name, method, path, problems.join("\n  ")));
        }
    }

    if !failures.is_empty() {
        panic!(
            "behavior gate failed: {} of {} cases passed\n{}",
            passed,
            cases.len() - skipped,
            failures.join("\n")
        );
    }
    assert_eq!(passed + skipped, cases.len());
}

fn string_field(value: &Json, key: &str) -> Option<String> {
    value.get(key).and_then(Json::as_str).map(|s| s.to_string())
}

fn check_shape(value: &Json, shape: &Json, path: &str) -> Result<(), String> {
    if let Some(name) = shape.as_str() {
        let ok = match name {
            "string" => matches!(value, Json::Str(_)),
            "number" => matches!(value, Json::Number(_)),
            "boolean" => matches!(value, Json::Bool(_)),
            "object" => matches!(value, Json::Obj(_)),
            "array" => matches!(value, Json::Arr(_)),
            "null" => value.is_null(),
            "any" => true,
            other => return Err(format!("{}: unknown shape {:?}", path, other)),
        };
        return if ok {
            Ok(())
        } else {
            Err(format!(
                "{}: expected {}, got {}",
                path,
                name,
                type_name(value)
            ))
        };
    }
    if let Some(entries) = shape.as_array() {
        let actual = value
            .as_array()
            .ok_or_else(|| format!("{}: expected array, got {}", path, type_name(value)))?;
        if entries.len() == 1 {
            for (index, item) in actual.iter().enumerate() {
                check_shape(item, &entries[0], &format!("{}[{}]", path, index))?;
            }
        }
        return Ok(());
    }
    if let Some(entries) = shape.as_object() {
        let actual = value
            .as_object()
            .ok_or_else(|| format!("{}: expected object, got {}", path, type_name(value)))?;
        for (key, expected) in entries {
            let Some(child) = actual.iter().find(|(k, _)| k == key).map(|(_, v)| v) else {
                return Err(format!("{}: missing key {:?}", path, key));
            };
            check_shape(child, expected, &format!("{}.{}", path, key))?;
        }
        return Ok(());
    }
    Ok(())
}

fn type_name(value: &Json) -> &'static str {
    match value {
        Json::Null => "null",
        Json::Bool(_) => "boolean",
        Json::Number(_) => "number",
        Json::Str(_) => "string",
        Json::Arr(_) => "array",
        Json::Obj(_) => "object",
    }
}

fn truncate(text: &str) -> String {
    if text.len() > 160 {
        format!("{}…", &text[..160])
    } else {
        text.to_string()
    }
}
