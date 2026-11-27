//! A tiny HTTP/1.1 server and request/response model.
//!
//! Deliberately small: SLiMS is a server-rendered PHP app, so the Rust rewrite
//! only needs request parsing, a handler, keep-alive and static files. Threads
//! are per connection, which is plenty for an OPAC and keeps the port honest
//! about what it is: a rewrite of the PHP request/response cycle, not a framework.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::Arc;
use std::time::{Duration, Instant};
use std::sync::atomic::{AtomicUsize, Ordering};

const MAX_HEADER_BYTES: usize = 64 * 1024;
const MAX_BODY_BYTES: usize = 1024 * 1024;
const MAX_CONNECTIONS: usize = 128;
const MAX_REQUESTS_PER_CONNECTION: usize = 100;
const REQUEST_READ_DEADLINE: Duration = Duration::from_secs(15);
const RESPONSE_WRITE_DEADLINE: Duration = Duration::from_secs(15);

/// Validated transport policy; application handlers remain transport-independent.
#[derive(Clone, Copy, Debug)]
pub struct ServerSettings {
    pub max_connections: usize,
    pub max_requests: usize,
    pub read_budget: Duration,
    pub write_budget: Duration,
    pub access_log: bool,
}
impl Default for ServerSettings {
    fn default() -> Self { Self { max_connections: MAX_CONNECTIONS, max_requests: MAX_REQUESTS_PER_CONNECTION,
        read_budget: REQUEST_READ_DEADLINE, write_budget: RESPONSE_WRITE_DEADLINE, access_log: false } }
}
impl ServerSettings {
    fn validate(&self) -> std::io::Result<()> {
        if !(1..=1024).contains(&self.max_connections) || !(1..=1000).contains(&self.max_requests)
            || self.read_budget.is_zero() || self.write_budget.is_zero()
            || self.read_budget > Duration::from_secs(60) || self.write_budget > Duration::from_secs(60) {
            return Err(std::io::Error::new(std::io::ErrorKind::InvalidInput, "invalid HTTP server settings"));
        }
        Ok(())
    }
}

#[derive(Clone, Debug)]
pub struct Request {
    pub method: String,
    pub path: String,
    pub query: Vec<(String, String)>,
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
}

impl Request {
    pub fn new(method: &str, target: &str) -> Request {
        let (path, query) = match target.split_once('?') {
            Some((p, q)) => (p.to_string(), parse_query(q)),
            None => (target.to_string(), Vec::new()),
        };
        Request {
            method: method.to_string(),
            path,
            query,
            headers: Vec::new(),
            body: Vec::new(),
        }
    }

    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers
            .iter()
            .find(|(k, _)| k.eq_ignore_ascii_case(name))
            .map(|(_, v)| v.as_str())
    }

    pub fn query(&self, name: &str) -> Option<&str> {
        self.query
            .iter()
            .find(|(k, _)| k == name)
            .map(|(_, v)| v.as_str())
    }

    pub fn query_or<'a>(&'a self, name: &str, default: &'a str) -> &'a str {
        self.query(name).unwrap_or(default)
    }

    /// Last path segment that is non-empty, e.g. `/api/biblio/gmd/Text` -> `Text`.
    pub fn path_param(&self, index: usize) -> Option<&str> {
        self.path.split('/').filter(|s| !s.is_empty()).nth(index)
    }
}

#[derive(Clone, Debug)]
pub struct Response {
    pub status: u16,
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
}

impl Response {
    pub fn new(status: u16) -> Response {
        Response {
            status,
            headers: Vec::new(),
            body: Vec::new(),
        }
    }

    pub fn with_header(mut self, name: &str, value: &str) -> Response {
        self.headers.push((name.to_string(), value.to_string()));
        self
    }

    pub fn with_body(mut self, body: impl Into<Vec<u8>>) -> Response {
        self.body = body.into();
        self
    }

    pub fn text(status: u16, body: impl Into<String>) -> Response {
        Response::new(status)
            .with_header("Content-Type", "text/plain; charset=utf-8")
            .with_body(body.into().into_bytes())
    }

    pub fn html(status: u16, body: impl Into<String>) -> Response {
        Response::new(status)
            .with_header("Content-Type", "text/html; charset=utf-8")
            .with_body(body.into().into_bytes())
    }

    pub fn json(status: u16, body: &crate::json::Json) -> Response {
        Response::new(status)
            .with_header("Content-Type", "application/json")
            .with_body(body.to_string().into_bytes())
    }

    pub fn redirect(location: &str) -> Response {
        Response::new(302)
            .with_header("Location", location)
            .with_header("Content-Type", "text/html; charset=utf-8")
            .with_body(
                format!(
                    "<!doctype html><html><body>Redirecting to <a href=\"{}\">{}</a>.</body></html>",
                    crate::templates::escape(location),
                    crate::templates::escape(location)
                )
                .into_bytes(),
            )
    }

    pub fn not_found(body: impl Into<String>) -> Response {
        Response::html(404, body.into())
    }

    fn status_text(code: u16) -> &'static str {
        match code {
            200 => "OK",
            201 => "Created",
            204 => "No Content",
            302 => "Found",
            304 => "Not Modified",
            400 => "Bad Request",
            401 => "Unauthorized",
            403 => "Forbidden",
            404 => "Not Found",
            405 => "Method Not Allowed",
            413 => "Payload Too Large",
            422 => "Unprocessable Entity",
            429 => "Too Many Requests",
            500 => "Internal Server Error",
            502 => "Bad Gateway",
            503 => "Service Unavailable",
            _ => "OK",
        }
    }

    pub fn serialize(&self, head_only: bool) -> Vec<u8> {
        let mut out = Vec::with_capacity(self.body.len() + 256);
        out.extend_from_slice(
            format!("HTTP/1.1 {} {}\r\n", self.status, Self::status_text(self.status)).as_bytes(),
        );
        let mut has_type = false;
        for (name, value) in &self.headers {
            if name.eq_ignore_ascii_case("content-type") {
                has_type = true;
            }
            out.extend_from_slice(name.as_bytes());
            out.extend_from_slice(b": ");
            out.extend_from_slice(value.as_bytes());
            out.extend_from_slice(b"\r\n");
        }
        if !has_type {
            out.extend_from_slice(b"Content-Type: text/plain; charset=utf-8\r\n");
        }
        out.extend_from_slice(format!("Content-Length: {}\r\n", self.body.len()).as_bytes());
        // Defaults must not append a second, potentially weaker member policy.
        for (name, value) in [("X-Content-Type-Options", "nosniff"),
            ("X-Frame-Options", "SAMEORIGIN"), ("Referrer-Policy", "strict-origin-when-cross-origin")] {
            if !self.headers.iter().any(|(key, _)| key.eq_ignore_ascii_case(name)) {
                out.extend_from_slice(format!("{name}: {value}\r\n").as_bytes());
            }
        }
        out.extend_from_slice(b"\r\n");
        if !head_only {
            out.extend_from_slice(&self.body);
        }
        out
    }
}

/// Parse `a=1&b=two` with `+` as space and percent-decoding.
pub fn parse_query(query: &str) -> Vec<(String, String)> {
    let mut out = Vec::new();
    for pair in query.split('&') {
        if pair.is_empty() {
            continue;
        }
        let (key, value) = match pair.split_once('=') {
            Some((k, v)) => (k, v),
            None => (pair, ""),
        };
        out.push((url_decode(key), url_decode(value)));
    }
    out
}

pub fn parse_query_map(query: &str) -> HashMap<String, String> {
    parse_query(query).into_iter().collect()
}

pub fn url_decode(input: &str) -> String {
    let bytes = input.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            b'%' if i + 2 < bytes.len() => {
                let hi = (bytes[i + 1] as char).to_digit(16);
                let lo = (bytes[i + 2] as char).to_digit(16);
                if let (Some(hi), Some(lo)) = (hi, lo) {
                    out.push((hi * 16 + lo) as u8);
                    i += 3;
                } else {
                    out.push(bytes[i]);
                    i += 1;
                }
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

pub fn url_encode(input: &str) -> String {
    // PHP's urlencode(): space becomes '+', everything outside [A-Za-z0-9-_.]
    // becomes %XX. SLiMS uses it for image paths, so the rewrite must too.
    let mut out = String::new();
    for byte in input.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => {
                out.push(byte as char)
            }
            b' ' => out.push('+'),
            _ => out.push_str(&format!("%{:02X}", byte)),
        }
    }
    out
}

pub type Handler = Arc<dyn Fn(&Request) -> Response + Send + Sync>;

/// Bind and serve forever. Blocks the calling thread.
pub fn serve(addr: &str, handler: Handler) -> std::io::Result<()> {
    serve_with_settings(addr, handler, ServerSettings::default())
}

pub fn serve_with_settings(addr: &str, handler: Handler, settings: ServerSettings) -> std::io::Result<()> {
    settings.validate()?;
    let listener = TcpListener::bind(addr)?;
    eprintln!("slims9: bound http://{}", listener.local_addr()?);
    serve_listener_with_settings(listener, handler, settings)
}

struct ConnectionLimit {
    active: AtomicUsize,
    max: usize,
}
struct ConnectionPermit(Arc<ConnectionLimit>);
impl ConnectionLimit {
    fn new(max: usize) -> Arc<Self> { Arc::new(Self { active: AtomicUsize::new(0), max }) }
    fn acquire(self: &Arc<Self>) -> Option<ConnectionPermit> {
        self.active.fetch_update(Ordering::AcqRel, Ordering::Relaxed,
            |n| if n < self.max { Some(n + 1) } else { None }).ok()?;
        Some(ConnectionPermit(self.clone()))
    }
}
impl Drop for ConnectionPermit {
    fn drop(&mut self) { self.0.active.fetch_sub(1, Ordering::AcqRel); }
}

#[cfg(test)]
fn spawn_connection(stream: TcpStream, handler: Handler, limit: &Arc<ConnectionLimit>)
    -> std::io::Result<Option<std::thread::JoinHandle<()>>> {
    spawn_connection_with_settings(stream, handler, limit, ServerSettings::default())
}
fn spawn_connection_with_settings(stream: TcpStream, handler: Handler, limit: &Arc<ConnectionLimit>, settings: ServerSettings)
    -> std::io::Result<Option<std::thread::JoinHandle<()>>> {
    let Some(permit) = limit.acquire() else {
        // Do not block the accept loop writing to an overloaded/slow peer.
        let _ = stream.shutdown(std::net::Shutdown::Both);
        return Ok(None);
    };
    std::thread::Builder::new().name("slims-http".into()).spawn(move || {
        let _permit = permit; // released on EOF/error/panic, including spawn failure
        let _ = handle_connection_with_settings(stream, handler, settings);
    }).map(Some)
}

/// Serve on an already-bound listener; at most 128 worker connections per listener.
pub fn serve_listener(listener: TcpListener, handler: Handler) -> std::io::Result<()> {
    serve_listener_with_settings(listener, handler, ServerSettings::default())
}
pub fn serve_listener_with_settings(listener: TcpListener, handler: Handler, settings: ServerSettings) -> std::io::Result<()> {
    settings.validate()?;
    let limit = ConnectionLimit::new(settings.max_connections);
    for stream in listener.incoming() {
        if let Ok(stream) = stream {
            // Spawn failure drops the stream and permit; keep accepting.
            let _ = spawn_connection_with_settings(stream, handler.clone(), &limit, settings);
        }
    }
    Ok(())
}

/// Bind to `127.0.0.1:0`, return the bound address and a join handle.
pub fn spawn_test_server(handler: Handler) -> std::io::Result<(String, std::thread::JoinHandle<()>)> {
    let listener = TcpListener::bind(("127.0.0.1", 0))?;
    let addr = listener.local_addr()?;
    let handle = std::thread::spawn(move || {
        let _ = serve_listener(listener, handler);
    });
    Ok((format!("http://{}", addr), handle))
}

/// Reset the socket timeout to the *remaining* budget before each underlying
/// read; trickled bytes cannot renew a per-syscall 15-second timeout forever.
struct DeadlineReader {
    stream: TcpStream,
    deadline: Instant,
}
impl Read for DeadlineReader {
    fn read(&mut self, output: &mut [u8]) -> std::io::Result<usize> {
        let remaining = self.deadline.checked_duration_since(Instant::now())
            .filter(|duration| !duration.is_zero())
            .ok_or_else(|| std::io::Error::new(std::io::ErrorKind::TimedOut, "request read deadline"))?;
        self.stream.set_read_timeout(Some(remaining))?;
        self.stream.read(output)
    }
}

/// A response gets one budget, not a fresh timeout after every partial write.
struct DeadlineWriter {
    stream: TcpStream,
    deadline: Instant,
}
impl DeadlineWriter {
    fn remaining(&self) -> std::io::Result<Duration> {
        self.deadline.checked_duration_since(Instant::now())
            .filter(|duration| !duration.is_zero())
            .ok_or_else(|| std::io::Error::new(std::io::ErrorKind::TimedOut, "response write deadline"))
    }
}
impl Write for DeadlineWriter {
    fn write(&mut self, input: &[u8]) -> std::io::Result<usize> {
        self.stream.set_write_timeout(Some(self.remaining()?))?;
        self.stream.write(input)
    }
    fn flush(&mut self) -> std::io::Result<()> {
        self.stream.set_write_timeout(Some(self.remaining()?))?;
        self.stream.flush()
    }
}

#[cfg(test)]
fn handle_connection(stream: TcpStream, handler: Handler) -> std::io::Result<()> {
    handle_connection_with_settings(stream, handler, ServerSettings::default())
}
#[cfg(test)]
fn handle_connection_with_deadline(stream: TcpStream, handler: Handler, read_budget: Duration) -> std::io::Result<()> {
    handle_connection_with_settings(stream, handler, ServerSettings { read_budget, ..ServerSettings::default() })
}
fn handle_connection_with_settings(stream: TcpStream, handler: Handler, settings: ServerSettings) -> std::io::Result<()> {
    let read_budget = settings.read_budget;
    let mut writer = DeadlineWriter { stream: stream.try_clone()?, deadline: Instant::now() + settings.write_budget };
    let mut reader = BufReader::new(DeadlineReader { stream, deadline: Instant::now() + read_budget });

    for request_index in 0..settings.max_requests {
        reader.get_mut().deadline = Instant::now() + read_budget;
        let request = match read_request(&mut reader) {
            Ok(Some(request)) => request,
            Ok(None) => return Ok(()), // clean EOF between requests
            Err(_) => return Ok(()),   // malformed request: drop the connection
        };

        if Instant::now() >= reader.get_ref().deadline { return Ok(()); }

        let keep_alive = request_index + 1 < settings.max_requests && !request.headers.iter().filter(|(name, _)| name.eq_ignore_ascii_case("connection"))
            .flat_map(|(_, value)| value.split(','))
            .any(|token| token.trim().eq_ignore_ascii_case("close"));
        let head_only = request.method == "HEAD";

        let mut response = crate::telemetry::dispatch(&request, settings.access_log, || if request.method == "OPTIONS" {
            Response::new(204)
                .with_header("Allow", "GET, HEAD, OPTIONS")
        } else {
            handler(&request)
        });

        if !keep_alive { response = response.with_header("Connection", "close"); }
        // Starts after handler completion, before serialization. This does not
        // impose a deadline on database/handler work or cancel a committed write.
        writer.deadline = Instant::now() + settings.write_budget;
        let raw = response.serialize(head_only);
        if writer.write_all(&raw).is_err() || writer.flush().is_err() {
            return Ok(());
        }
        if !keep_alive {
            return Ok(());
        }
    }
    Ok(())
}

fn invalid_request(message: &'static str) -> std::io::Error {
    std::io::Error::new(std::io::ErrorKind::InvalidData, message)
}

fn token_byte(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || b"!#$%&'*+-.^_`|~".contains(&byte)
}

/// Bound allocation *during* the read, including lines without a terminator.
fn read_header_line<R: BufRead>(reader: &mut R, remaining: usize) -> std::io::Result<String> {
    let mut line = String::new();
    (&mut *reader).take(remaining.saturating_add(1) as u64).read_line(&mut line)?;
    if line.len() > remaining { return Err(invalid_request("headers too large")); }
    if !line.is_empty() && !line.ends_with("\r\n") { return Err(invalid_request("incomplete header line")); }
    Ok(line)
}

fn read_request<R: BufRead>(reader: &mut R) -> std::io::Result<Option<Request>> {
    let line = read_header_line(reader, MAX_HEADER_BYTES)?;
    if line.is_empty() { return Ok(None); } // clean EOF between requests only
    let mut parts = line[..line.len() - 2].split(' ');
    let method = parts.next().unwrap_or("");
    let target = parts.next().unwrap_or("");
    let version = parts.next().unwrap_or("");
    if parts.next().is_some() || method.is_empty() || !method.bytes().all(token_byte) ||
        !target.starts_with('/') || !target.bytes().all(|b| (0x21..=0x7e).contains(&b) && b != b'#') ||
        !matches!(version, "HTTP/1.0" | "HTTP/1.1") {
        return Err(invalid_request("invalid request line"));
    }
    let mut request = Request::new(method, target);
    let mut total = line.len();
    loop {
        let header_line = read_header_line(reader, MAX_HEADER_BYTES - total)?;
        if header_line.is_empty() { return Err(invalid_request("incomplete headers")); }
        total += header_line.len();
        let header_line = &header_line[..header_line.len() - 2];
        if header_line.is_empty() { break; }
        let Some((name, value)) = header_line.split_once(':') else {
            return Err(invalid_request("invalid header"));
        };
        // No whitespace before colon, obs-fold, CR/LF or control-byte values.
        if name.is_empty() || !name.bytes().all(token_byte) ||
            value.bytes().any(|b| b == 0x7f || (b < 0x20 && b != b'\t')) {
            return Err(invalid_request("invalid header"));
        }
        request.headers.push((name.to_string(), value.trim_matches([' ', '\t']).to_string()));
    }
    let count = |name: &str| request.headers.iter().filter(|(key, _)| key.eq_ignore_ascii_case(name)).count();
    if count("host") > 1 || (version == "HTTP/1.1" && request.header("host").map_or(true, str::is_empty)) {
        return Err(invalid_request("invalid host"));
    }
    // Chunked bodies, 100-continue and transfer coding are not implemented.
    // Never leave their bytes to be interpreted as another keep-alive request.
    if count("transfer-encoding") > 0 || count("expect") > 0 || count("content-length") > 1 {
        return Err(invalid_request("unsupported or ambiguous framing"));
    }
    let content_length = match request.header("content-length") {
        None => 0,
        Some(raw) if !raw.is_empty() && raw.bytes().all(|b| b.is_ascii_digit()) =>
            raw.parse::<usize>().map_err(|_| invalid_request("invalid content length"))?,
        Some(_) => return Err(invalid_request("invalid content length")),
    };
    if content_length > MAX_BODY_BYTES { return Err(invalid_request("body too large")); }
    if version == "HTTP/1.0" {
        // This minimal server does not negotiate HTTP/1.0 keep-alive.
        request.headers.retain(|(name, _)| !name.eq_ignore_ascii_case("connection"));
        request.headers.push(("Connection".into(), "close".into()));
    }
    if content_length > 0 {
        let mut body = vec![0u8; content_length];
        reader.read_exact(&mut body)?;
        request.body = body;
    }
    Ok(Some(request))
}

/// Issue a raw HTTP request over a TcpStream. Used by tests and by the
/// behavior gate, so neither needs an HTTP client dependency.
pub fn request(url: &str, method: &str, body: Option<&str>) -> std::io::Result<Response> {
    let (host, path) = split_url(url);
    let stream = TcpStream::connect(&host)?;
    stream.set_read_timeout(Some(Duration::from_secs(20)))?;
    stream.set_write_timeout(Some(Duration::from_secs(20)))?;
    let mut stream = stream;

    let body_bytes = body.unwrap_or("").as_bytes();
    let mut raw = format!("{} {} HTTP/1.1\r\nHost: {}\r\nConnection: close\r\n", method, path, host);
    if let Some(body) = body {
        raw.push_str(&format!(
            "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: {}\r\n",
            body.len()
        ));
    }
    raw.push_str("\r\n");
    stream.write_all(raw.as_bytes())?;
    if !body_bytes.is_empty() {
        stream.write_all(body_bytes)?;
    }

    let mut reader = BufReader::new(stream);
    let mut status_line = String::new();
    reader.read_line(&mut status_line)?;
    let status = status_line
        .split_whitespace()
        .nth(1)
        .and_then(|s| s.parse::<u16>().ok())
        .unwrap_or(0);
    let mut headers = Vec::new();
    loop {
        let mut line = String::new();
        let n = reader.read_line(&mut line)?;
        if n == 0 {
            break;
        }
        let line = line.trim_end_matches(['\r', '\n']);
        if line.is_empty() {
            break;
        }
        if let Some((name, value)) = line.split_once(':') {
            headers.push((name.trim().to_string(), value.trim().to_string()));
        }
    }
    let mut body_buf = Vec::new();
    let content_length = headers
        .iter()
        .find(|(k, _)| k.eq_ignore_ascii_case("content-length"))
        .and_then(|(_, v)| v.parse::<usize>().ok());
    match content_length {
        Some(len) => {
            body_buf = vec![0u8; len];
            reader.read_exact(&mut body_buf)?;
        }
        None => {
            reader.read_to_end(&mut body_buf)?;
        }
    }
    Ok(Response {
        status,
        headers,
        body: body_buf,
    })
}

fn split_url(url: &str) -> (String, String) {
    let rest = url
        .strip_prefix("http://")
        .map(|s| s.to_string())
        .unwrap_or_else(|| url.to_string());
    match rest.split_once('/') {
        Some((host, path)) => (host.to_string(), format!("/{}", path)),
        None => (rest, "/".to_string()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn absolute_writer_deadline_does_not_renew_after_partial_progress() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let mut client = TcpStream::connect(listener.local_addr().unwrap()).unwrap();
        client.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        let (server, _) = listener.accept().unwrap();
        let mut writer = DeadlineWriter { stream: server, deadline: Instant::now() + Duration::from_secs(2) };
        writer.write_all(b"a").unwrap();
        let mut byte = [0];
        client.read_exact(&mut byte).unwrap();
        assert_eq!(&byte, b"a");
        writer.deadline = Instant::now() - Duration::from_millis(1);
        assert_eq!(writer.write(b"b").unwrap_err().kind(), std::io::ErrorKind::TimedOut);
        assert_eq!(writer.flush().unwrap_err().kind(), std::io::ErrorKind::TimedOut);
        drop(writer);
        assert_eq!(client.read(&mut byte).unwrap(), 0);
    }

    #[test]
    fn nonreading_peer_exhausts_write_budget_and_releases_permit() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let client = TcpStream::connect(listener.local_addr().unwrap()).unwrap();
        let (server, _) = listener.accept().unwrap();
        let limit = ConnectionLimit::new(1);
        let permit = limit.acquire().unwrap();
        let (completed, result) = std::sync::mpsc::channel();
        let worker = std::thread::spawn(move || {
            let _permit = permit;
            let bytes = vec![b'x'; 16 * 1024 * 1024];
            let mut writer = DeadlineWriter { stream: server, deadline: Instant::now() + Duration::from_millis(40) };
            completed.send(writer.write_all(&bytes).unwrap_err().kind()).unwrap();
        });
        // Wait boundedly; a regression must not hang the test suite for minutes.
        let outcome = result.recv_timeout(Duration::from_secs(3));
        drop(client);
        worker.join().unwrap();
        assert!(matches!(outcome.unwrap(), std::io::ErrorKind::TimedOut | std::io::ErrorKind::WouldBlock));
        assert_eq!(limit.active.load(Ordering::Acquire), 0);
        assert!(limit.acquire().is_some());
    }

    #[test]
    fn connection_permits_are_bounded_and_released_on_unwind() {
        let limit = ConnectionLimit::new(1);
        let permit = limit.acquire().unwrap();
        assert!(limit.acquire().is_none());
        drop(permit);
        let cloned = limit.clone();
        assert!(std::thread::spawn(move || {
            let _permit = cloned.acquire().unwrap();
            panic!("simulated worker panic");
        }).join().is_err());
        assert_eq!(limit.active.load(Ordering::Acquire), 0);
        assert!(limit.acquire().is_some());
    }

    #[test]
    fn excess_connection_is_closed_and_capacity_recovers() {
        let limit = ConnectionLimit::new(1);
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let handler: Handler = Arc::new(|_| Response::text(200, "ok"));
        let first = TcpStream::connect(address).unwrap();
        let (server, _) = listener.accept().unwrap();
        let worker = spawn_connection(server, handler.clone(), &limit).unwrap().unwrap();
        let mut excess = TcpStream::connect(address).unwrap();
        excess.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        let (server, _) = listener.accept().unwrap();
        assert!(spawn_connection(server, handler.clone(), &limit).unwrap().is_none());
        let mut byte = [0];
        match excess.read(&mut byte) { Ok(n) => assert_eq!(n, 0), Err(e) => assert_eq!(e.kind(), std::io::ErrorKind::ConnectionReset) }
        drop(first);
        worker.join().unwrap();
        assert_eq!(limit.active.load(Ordering::Acquire), 0);
        let mut next = TcpStream::connect(address).unwrap();
        next.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        let (server, _) = listener.accept().unwrap();
        let worker = spawn_connection(server, handler, &limit).unwrap().unwrap();
        next.write_all(b"GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n").unwrap();
        let mut body = String::new();
        next.read_to_string(&mut body).unwrap();
        worker.join().unwrap();
        assert!(body.ends_with("ok"));
    }

    #[test]
    fn keep_alive_request_cap_closes_without_dispatching_extra_pipeline() {
        let calls = Arc::new(AtomicUsize::new(0));
        let recorded = calls.clone();
        let handler: Handler = Arc::new(move |_| { recorded.fetch_add(1, Ordering::SeqCst); Response::text(200, "ok") });
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let mut client = TcpStream::connect(listener.local_addr().unwrap()).unwrap();
        client.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        let (server, _) = listener.accept().unwrap();
        let worker = std::thread::spawn(move || handle_connection(server, handler));
        client.write_all(b"GET / HTTP/1.1\r\nHost: a\r\n\r\n".repeat(MAX_REQUESTS_PER_CONNECTION + 1).as_slice()).unwrap();
        let mut output = Vec::new();
        // Unread pipeline bytes may cause reset after the cap; still no dispatch.
        let _ = client.read_to_end(&mut output);
        worker.join().unwrap().unwrap();
        assert_eq!(calls.load(Ordering::SeqCst), MAX_REQUESTS_PER_CONNECTION);
        assert!(String::from_utf8(output).unwrap().contains("Connection: close"));
    }

    #[test]
    fn absolute_reader_deadline_does_not_renew_for_available_bytes() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let mut client = TcpStream::connect(listener.local_addr().unwrap()).unwrap();
        let (server, _) = listener.accept().unwrap();
        client.write_all(b"ab").unwrap();
        let mut reader = DeadlineReader { stream: server, deadline: Instant::now() + Duration::from_secs(2) };
        let mut byte = [0];
        reader.read_exact(&mut byte).unwrap();
        assert_eq!(&byte, b"a");
        reader.deadline = Instant::now() - Duration::from_millis(1);
        assert_eq!(reader.read(&mut byte).unwrap_err().kind(), std::io::ErrorKind::TimedOut);
    }

    #[test]
    fn incomplete_request_times_out_before_handler_dispatch() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let mut client = TcpStream::connect(listener.local_addr().unwrap()).unwrap();
        client.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        let (server, _) = listener.accept().unwrap();
        let calls = Arc::new(AtomicUsize::new(0));
        let recorded = calls.clone();
        let handler: Handler = Arc::new(move |_| { recorded.fetch_add(1, Ordering::SeqCst); Response::text(200, "bad") });
        let worker = std::thread::spawn(move || handle_connection_with_deadline(server, handler, Duration::from_millis(30)));
        client.write_all(b"GET / HTTP/1.1\r\nHost: unfinished").unwrap();
        let mut output = Vec::new();
        let _ = client.read_to_end(&mut output);
        worker.join().unwrap().unwrap();
        assert!(output.is_empty());
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    }

    #[test]
    fn parser_reads_body_and_preserves_pipeline_boundaries() {
        let mut input = std::io::Cursor::new(b"POST /member HTTP/1.1\r\nHost: library\r\nContent-Length: 3\r\n\r\na=bGET /healthz HTTP/1.1\r\nHost: library\r\n\r\n");
        let first = read_request(&mut input).unwrap().unwrap();
        assert_eq!(first.method, "POST");
        assert_eq!(first.body, b"a=b");
        assert_eq!(read_request(&mut input).unwrap().unwrap().path, "/healthz");
        assert!(read_request(&mut input).unwrap().is_none());
        let mut old = std::io::Cursor::new(b"GET / HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
        assert_eq!(read_request(&mut old).unwrap().unwrap().header("connection"), Some("close"));
    }

    #[test]
    fn parser_rejects_ambiguous_and_unsupported_body_framing() {
        for headers in ["Content-Length: 0\r\ncontent-length: 0", "Content-Length: 1\r\nContent-Length: 9",
            "Content-Length: 0, 0", "Content-Length: +1", "Content-Length: -1", "Content-Length:",
            "Content-Length: 99999999999999999999999999999999", "Content-Length: 1048577",
            "Transfer-Encoding: chunked", "Transfer-Encoding: identity\r\nContent-Length: 0",
            "Expect: 100-continue"] {
            let raw = format!("POST /member HTTP/1.1\r\nHost: library\r\n{headers}\r\n\r\n");
            assert!(read_request(&mut std::io::Cursor::new(raw)).is_err(), "{headers}");
        }
        assert!(read_request(&mut std::io::Cursor::new(b"POST /member HTTP/1.1\r\nHost: library\r\nContent-Length: 5\r\n\r\nabc")).is_err());
    }

    #[test]
    fn parser_rejects_incomplete_malformed_and_folded_headers() {
        for raw in ["GET / HTTP/1.1\r\n", "GET / HTTP/1.1\r\nHost: library\r\n",
            "GET / HTTP/1.1\r\n\r\n", "GET / HTTP/1.1\r\nHost: a\r\nhost: b\r\n\r\n",
            "GET / HTTP/1.1\r\nHost : a\r\n\r\n", "GET / HTTP/1.1\r\nNoColon\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\n folded: b\r\n\r\n", "GET / HTTP/1.1\r\nHost: a\0\r\n\r\n",
            "GET / HTTP/1.1\nHost: a\n\n", "GET / HTTP/1.1 extra\r\nHost: a\r\n\r\n",
            "GET / HTTP/2\r\n\r\n", "GET http://example/ HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET /#fragment HTTP/1.1\r\nHost: a\r\n\r\n"] {
            assert!(read_request(&mut std::io::Cursor::new(raw)).is_err(), "{raw:?}");
        }
    }

    #[test]
    fn unterminated_and_cumulative_headers_have_bounded_reads() {
        let mut huge = std::io::Cursor::new(vec![b'A'; MAX_HEADER_BYTES * 10]);
        assert!(read_request(&mut huge).is_err());
        assert_eq!(huge.position(), (MAX_HEADER_BYTES + 1) as u64);
        let raw = format!("GET / HTTP/1.1\r\nHost: a\r\nX-One: {}\r\nX-Two: {}\r\n\r\n", "a".repeat(40000), "b".repeat(40000));
        let mut cumulative = std::io::Cursor::new(raw);
        assert!(read_request(&mut cumulative).is_err());
        assert_eq!(cumulative.position(), (MAX_HEADER_BYTES + 1) as u64);
    }

    #[test]
    fn bad_framing_closes_socket_without_dispatching_pipelined_actions() {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let calls = Arc::new(AtomicUsize::new(0));
        let recorded = calls.clone();
        let handler: Handler = Arc::new(move |_| { recorded.fetch_add(1, Ordering::SeqCst); Response::text(200, "UNSAFE") });
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let worker = std::thread::spawn(move || {
            let (stream, _) = listener.accept().unwrap();
            let _ = handle_connection(stream, handler);
        });
        let mut client = TcpStream::connect(address).unwrap();
        client.set_read_timeout(Some(Duration::from_secs(2))).unwrap();
        client.write_all(b"POST /member HTTP/1.1\r\nHost: a\r\nContent-Length: 0\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\nGET /member HTTP/1.1\r\nHost: a\r\n\r\n").unwrap();
        client.shutdown(std::net::Shutdown::Write).unwrap();
        let mut output = Vec::new();
        let _ = client.read_to_end(&mut output); // EOF or reset are both closed
        worker.join().unwrap();
        assert!(output.is_empty());
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    }

    #[test]
    fn explicit_member_policies_are_not_duplicated_or_weakened_on_wire() {
        let response = Response::text(200, "private")
            .with_header("Referrer-Policy", "no-referrer")
            .with_header("X-Content-Type-Options", "nosniff");
        let wire = String::from_utf8(response.serialize(false)).unwrap();
        assert_eq!(wire.matches("Referrer-Policy:").count(), 1);
        assert!(!wire.contains("strict-origin-when-cross-origin"));
        assert_eq!(wire.matches("X-Content-Type-Options:").count(), 1);
        assert_eq!(wire.matches("Content-Length:").count(), 1);
    }

    #[test]
    fn decodes_query_values() {
        let query = parse_query("keywords=web+development&author=O%27Reilly");
        assert_eq!(query[0], ("keywords".into(), "web development".into()));
        assert_eq!(query[1], ("author".into(), "O'Reilly".into()));
    }

    #[test]
    fn encodes_like_php_urlencode() {
        assert_eq!(url_encode("images/docs/php5_dummies.jpg"), "images%2Fdocs%2Fphp5_dummies.jpg");
        assert_eq!(url_encode("a b&c"), "a+b%26c");
    }

    #[test]
    fn serializes_headers_and_body() {
        let response = Response::json(200, &crate::json::Json::obj());
        let raw = String::from_utf8(response.serialize(false)).unwrap();
        assert!(raw.starts_with("HTTP/1.1 200 OK\r\n"));
        assert!(raw.contains("Content-Type: application/json\r\n"));
        assert!(raw.ends_with("\r\n\r\n{}"));
    }
}
