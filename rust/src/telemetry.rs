//! Small dependency-free operational event boundary. Not an audit trail or a
//! replacement claim for tracing/OpenTelemetry. Never accept client request IDs
//! or log request targets, headers, cookies, bodies, database URLs or member IDs.
use std::io::Write;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Instant, SystemTime, UNIX_EPOCH};
use crate::http::{Request, Response};
use crate::json::Json;

static NEXT_REQUEST: AtomicU64 = AtomicU64::new(1);

fn envelope(event: &str) -> Json {
    let mut out = Json::obj();
    out.set("event", Json::str(event));
    let seconds = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_secs());
    out.set("unix_seconds", Json::int(seconds.min(i64::MAX as u64) as i64));
    out
}
fn emit(event: &Json) {
    // Log sink errors must not panic or replay an application action.
    let mut stderr = std::io::stderr().lock();
    let _ = writeln!(stderr, "{}", event.to_string_utf8());
}
pub fn lifecycle(event: &str) { emit(&envelope(event)); }
pub fn config_error(error: &crate::runtime::ConfigError) {
    let mut out = envelope("configuration_refused");
    out.set("variable", Json::str(error.variable));
    out.set("reason", Json::str(error.reason));
    emit(&out);
}
fn route_class(request: &Request) -> &'static str {
    let path = request.path.trim_end_matches('/');
    if path == "/member" || (path == "/index.php" && matches!(request.query("p"), Some("member" | "download_member_loan_report"))) { "member" }
    else if path == "/healthz" { "health" }
    else if path == "/api" || path.starts_with("/api/") { "api" }
    else if path.starts_with("/static/") { "static" }
    else { "opac" }
}
fn request_event(request: &Request, id: &str, status: u16, elapsed: u128) -> Json {
    let mut out = envelope("response_prepared");
    let method = match request.method.as_str() { "GET" => "GET", "HEAD" => "HEAD", "POST" => "POST", "OPTIONS" => "OPTIONS", _ => "OTHER" };
    out.set("request_id", Json::str(id)); out.set("method", Json::str(method));
    out.set("route_class", Json::str(route_class(request)));
    out.set("status", Json::int(status as i64));
    out.set("handler_micros", Json::int(elapsed.min(i64::MAX as u128) as i64));
    out
}
pub fn dispatch(request: &Request, log: bool, handler: impl FnOnce() -> Response) -> Response {
    let serial = NEXT_REQUEST.fetch_add(1, Ordering::Relaxed);
    let stamp = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_nanos());
    let id = format!("{:x}-{stamp:x}-{serial:x}", std::process::id());
    let start = Instant::now();
    let mut response = handler();
    response.headers.retain(|(key, _)| !key.eq_ignore_ascii_case("x-request-id"));
    response.headers.push(("X-Request-ID".into(), id.clone()));
    if log { emit(&request_event(request, &id, response.status, start.elapsed().as_micros())); }
    response
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn operational_events_omit_all_request_secrets() {
        let mut request = Request::new("POST", "/member?memberID=secret-member&token=secret-query");
        request.headers.push(("Cookie".into(), "secret-cookie".into()));
        request.headers.push(("Authorization".into(), "secret-auth".into()));
        request.body = b"password=secret-password".to_vec();
        let event = request_event(&request, "server-id", 503, 10).to_string_utf8();
        assert!(!event.contains("secret")); assert!(event.contains("member"));
        assert_eq!(crate::json::parse(&event).unwrap().get("status").unwrap().as_i64(), Some(503));
    }
    #[test]
    fn correlation_is_server_owned_unique_and_cannot_be_duplicated() {
        let mut request = Request::new("GET", "/");
        request.headers.push(("X-Request-ID".into(), "attacker".into()));
        let mut ids = Vec::new();
        for _ in 0..2 {
            let response = dispatch(&request, false, || Response::text(200, "ok").with_header("X-Request-ID", "wrong"));
            let headers: Vec<_> = response.headers.iter().filter(|(k, _)| k.eq_ignore_ascii_case("x-request-id")).collect();
            assert_eq!(headers.len(), 1); assert_ne!(headers[0].1, "attacker"); assert_ne!(headers[0].1, "wrong");
            ids.push(headers[0].1.clone());
        }
        assert_ne!(ids[0], ids[1]);
    }
}
