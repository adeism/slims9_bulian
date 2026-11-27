//! Real binary tests: fail-closed startup, transport settings and redacted logs.
use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpStream;
use std::process::{Child, Command, Stdio};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use slims9::json::{self, Json};

fn command() -> Command {
    let mut command = Command::new(env!("CARGO_BIN_EXE_slims9"));
    command.env_clear().current_dir(env!("CARGO_MANIFEST_DIR"))
        .env("SLIMS_DB", "memory").env("SLIMS_ADDR", "0.0.0.0:0");
    command
}
#[test]
fn bad_process_configuration_exits_before_listening_without_secrets() {
    for (key, value) in [("SLIMS_DB", "mysql://alice:do-not-log-password@localhost/"),
        ("SLIMS_HTTP_MAX_CONNECTIONS", "0"), ("SLIMS_MEMBER_TEST_WRITES", "true")] {
        let output = command().env(key, value).output().unwrap();
        assert_eq!(output.status.code(), Some(2));
        let log = String::from_utf8(output.stderr).unwrap();
        assert!(!log.contains("do-not-log-password"));
        assert!(!log.contains("bound http"));
        let event = json::parse(log.trim()).unwrap();
        assert_eq!(event.get("event").and_then(Json::as_str), Some("configuration_refused"));
        assert_eq!(event.get("variable").and_then(Json::as_str), Some(key));
    }
}

struct Server { child: Child, reader: Option<std::thread::JoinHandle<()>>, logs: Arc<Mutex<Vec<String>>>, port: u16 }
impl Server {
    fn start() -> Self {
        let mut child = command().env("SLIMS_HTTP_MAX_CONNECTIONS", "1").env("SLIMS_HTTP_MAX_REQUESTS", "2")
            .env("SLIMS_HTTP_READ_SECONDS", "2").env("SLIMS_HTTP_WRITE_SECONDS", "2")
            .stdout(Stdio::null()).stderr(Stdio::piped()).spawn().unwrap();
        let stderr = child.stderr.take().unwrap();
        let logs = Arc::new(Mutex::new(Vec::new())); let recorded = logs.clone();
        let (send, receive) = std::sync::mpsc::channel();
        let reader = std::thread::spawn(move || {
            for line in BufReader::new(stderr).lines().map_while(Result::ok) {
                if let Some(address) = line.strip_prefix("slims9: bound http://0.0.0.0:") {
                    if let Ok(port) = address.parse::<u16>() { let _ = send.send(port); }
                }
                recorded.lock().unwrap().push(line);
            }
        });
        let mut server = Self { child, reader: Some(reader), logs, port: 0 };
        server.port = receive.recv_timeout(Duration::from_secs(5)).expect("server readiness");
        server
    }
    fn stop(&mut self) { let _ = self.child.kill(); let _ = self.child.wait(); if let Some(reader) = self.reader.take() { reader.join().unwrap(); } }
    fn connect(&self) -> TcpStream {
        let stream = TcpStream::connect(("127.0.0.1", self.port)).unwrap();
        stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap(); stream
    }
}
impl Drop for Server { fn drop(&mut self) { self.stop(); } }

#[test]
fn real_transport_applies_caps_and_server_owned_redacted_correlation() {
    let mut server = Server::start();
    let mut client = server.connect();
    let request = b"GET /healthz?token=private-query HTTP/1.1\r\nHost: localhost\r\nCookie: private-cookie\r\nAuthorization: private-auth\r\nX-Request-ID: attacker-id\r\n\r\n";
    let mut pipelined = Vec::new(); for _ in 0..3 { pipelined.extend_from_slice(request); }
    client.write_all(&pipelined).unwrap();
    let mut bytes = Vec::new(); client.read_to_end(&mut bytes).unwrap();
    let response = String::from_utf8(bytes).unwrap();
    assert_eq!(response.matches("HTTP/1.1 200").count(), 2);
    let ids: Vec<_> = response.lines().filter_map(|line| line.strip_prefix("X-Request-ID: ")).collect();
    assert_eq!(ids.len(), 2); assert_ne!(ids[0], ids[1]); assert!(!response.contains("attacker-id"));
    // After the capped connection closes its worker, one keep-alive HEAD
    // occupies the sole permit. An excess connection must not be dispatched.
    std::thread::sleep(Duration::from_millis(20));
    let mut held = server.connect();
    held.write_all(b"HEAD /healthz HTTP/1.1\r\nHost: localhost\r\n\r\n").unwrap();
    let mut head = Vec::new();
    while !head.ends_with(b"\r\n\r\n") { let mut b = [0]; held.read_exact(&mut b).unwrap(); head.push(b[0]); }
    let mut excess = server.connect();
    let mut byte = [0];
    match excess.read(&mut byte) { Ok(0) => (), Err(error) if error.kind() == std::io::ErrorKind::ConnectionReset => (), result => panic!("unexpected overload response {result:?}") }
    drop(held);
    server.stop();
    let logs = server.logs.lock().unwrap().join("\n");
    for secret in ["private-query", "private-cookie", "private-auth", "attacker-id"] { assert!(!logs.contains(secret)); }
    let events: Vec<_> = logs.lines().filter_map(|line| json::parse(line).ok())
        .filter(|event| event.get("event").and_then(Json::as_str) == Some("response_prepared")).collect();
    assert_eq!(events.len(), 3);
    assert_eq!(events[0].get("request_id").and_then(Json::as_str), Some(ids[0]));
}
