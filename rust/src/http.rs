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
use std::time::Duration;

const MAX_HEADER_BYTES: usize = 64 * 1024;
const MAX_BODY_BYTES: usize = 1024 * 1024;

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
        out.extend_from_slice(b"X-Content-Type-Options: nosniff\r\n");
        out.extend_from_slice(b"X-Frame-Options: SAMEORIGIN\r\n");
        out.extend_from_slice(b"Referrer-Policy: strict-origin-when-cross-origin\r\n");
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
    let listener = TcpListener::bind(addr)?;
    serve_listener(listener, handler)
}

/// Serve on an already-bound listener; used by tests and `serve`.
pub fn serve_listener(listener: TcpListener, handler: Handler) -> std::io::Result<()> {
    for stream in listener.incoming() {
        match stream {
            Ok(stream) => {
                let handler = Arc::clone(&handler);
                std::thread::spawn(move || {
                    let _ = handle_connection(stream, handler);
                });
            }
            Err(_) => continue,
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

fn handle_connection(stream: TcpStream, handler: Handler) -> std::io::Result<()> {
    stream.set_read_timeout(Some(Duration::from_secs(15)))?;
    stream.set_write_timeout(Some(Duration::from_secs(15)))?;
    let mut writer = stream.try_clone()?;
    let mut reader = BufReader::new(stream);

    loop {
        let request = match read_request(&mut reader) {
            Ok(Some(request)) => request,
            Ok(None) => return Ok(()), // clean EOF between requests
            Err(_) => return Ok(()),   // malformed request: drop the connection
        };

        let keep_alive = request
            .header("connection")
            .map(|v| !v.eq_ignore_ascii_case("close"))
            .unwrap_or(true);
        let head_only = request.method == "HEAD";

        let response = if request.method == "OPTIONS" {
            Response::new(204)
                .with_header("Allow", "GET, HEAD, OPTIONS")
                .with_header("Content-Length", "0")
        } else {
            handler(&request)
        };

        let raw = response.serialize(head_only);
        if writer.write_all(&raw).is_err() || writer.flush().is_err() {
            return Ok(());
        }
        if !keep_alive {
            return Ok(());
        }
    }
}

fn read_request(reader: &mut BufReader<TcpStream>) -> std::io::Result<Option<Request>> {
    let mut line = String::new();
    let read = reader.read_line(&mut line)?;
    if read == 0 {
        return Ok(None);
    }
    if line.len() > MAX_HEADER_BYTES {
        return Ok(None);
    }
    let mut parts = line.trim_end().splitn(3, ' ');
    let method = parts.next().unwrap_or("").to_uppercase();
    let target = parts.next().unwrap_or("/");
    let _version = parts.next().unwrap_or("HTTP/1.1");
    if method.is_empty() {
        return Ok(None);
    }

    let mut request = Request::new(&method, target);
    let mut total = line.len();
    loop {
        let mut header_line = String::new();
        let n = reader.read_line(&mut header_line)?;
        if n == 0 {
            break;
        }
        total += n;
        if total > MAX_HEADER_BYTES {
            return Ok(None);
        }
        let header_line = header_line.trim_end_matches(['\r', '\n']);
        if header_line.is_empty() {
            break;
        }
        if let Some((name, value)) = header_line.split_once(':') {
            request
                .headers
                .push((name.trim().to_string(), value.trim().to_string()));
        }
    }

    let content_length = request
        .header("content-length")
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(0);
    if content_length > MAX_BODY_BYTES {
        return Ok(None);
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
