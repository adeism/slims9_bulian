//! Strict process configuration. The compatibility Config::from_env loader is
//! retained for library users; the executable uses this fail-closed boundary.
use std::net::SocketAddr;
use std::path::PathBuf;
use std::time::Duration;
use crate::config::{Config, DbConfig, MySqlUrl};
use crate::http::ServerSettings;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ConfigError {
    pub variable: &'static str,
    pub reason: &'static str,
}
impl std::fmt::Display for ConfigError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result { write!(f, "{}: {}", self.variable, self.reason) }
}
impl std::error::Error for ConfigError {}
fn invalid(variable: &'static str, reason: &'static str) -> ConfigError { ConfigError { variable, reason } }

pub struct RuntimeConfig {
    pub app: Config,
    pub server: ServerSettings,
}

impl RuntimeConfig {
    pub fn from_env() -> Result<Self, ConfigError> {
        // Only inspect recognized variables; reject non-Unicode settings rather
        // than treating them as absent. No untrusted value enters an error.
        let keys = ["SLIMS_ADDR", "SLIMS_ROOT", "SLIMS_DB", "SLIMS_LIBRARY_NAME", "SLIMS_LIBRARY_SUBNAME",
            "SLIMS_POPULAR_LIMIT", "SLIMS_LATEST_LIMIT", "SLIMS_CACHE_TTL", "SLIMS_HTTP_CACHE_LIFETIME",
            "SLIMS_MEMBER_EXPERIMENTAL", "SLIMS_MEMBER_INSECURE_COOKIE", "SLIMS_DEMO_RESERVATIONS",
            "SLIMS_MEMBER_TEST_WRITES", "SLIMS_MEMBER_TEST_RESERVATIONS", "SLIMS_SESSION_DB", "SLIMS_DEMO_MEMBER_HASH",
            "SLIMS_HTTP_MAX_CONNECTIONS", "SLIMS_HTTP_MAX_REQUESTS", "SLIMS_HTTP_READ_SECONDS", "SLIMS_HTTP_WRITE_SECONDS", "SLIMS_HTTP_ACCESS_LOG"];
        let mut values = std::collections::HashMap::new();
        for key in keys {
            if let Some(value) = std::env::var_os(key) {
                values.insert(key, value.into_string().map_err(|_| invalid(key, "must be Unicode"))?);
            }
        }
        Self::from_lookup(|key| values.get(key).cloned())
    }

    /// Injectable input avoids process-global environment mutation in tests.
    pub fn from_lookup(mut get: impl FnMut(&'static str) -> Option<String>) -> Result<Self, ConfigError> {
        let mut app = Config::default();
        let mut server = ServerSettings { access_log: true, ..ServerSettings::default() };
        if let Some(value) = get("SLIMS_ADDR") {
            let address = value.parse::<SocketAddr>().map_err(|_| invalid("SLIMS_ADDR", "must be an IP:port socket address"))?;
            app.addr = address.to_string(); // Port zero is intentional for test listeners.
        }
        if let Some(value) = get("SLIMS_ROOT") { app.root = PathBuf::from(value); }
        if !app.root.is_absolute() || !app.root.join("install/senayan.sql").is_file() {
            return Err(invalid("SLIMS_ROOT", "must be an absolute repository root containing install/senayan.sql"));
        }
        if let Some(value) = get("SLIMS_DB") {
            if value == "memory" { app.db = DbConfig::Memory; }
            else {
                let url = MySqlUrl::parse(&value).map_err(|_| invalid("SLIMS_DB", "must be memory or a valid mysql:// connection URL"))?;
                let host = url.host.strip_prefix('[').and_then(|h| h.strip_suffix(']')).unwrap_or(&url.host);
                let valid_host = host.parse::<std::net::IpAddr>().is_ok() ||
                    (host.len() <= 253 && host.split('.').all(|label| !label.is_empty() && label.len() <= 63 &&
                        !label.starts_with('-') && !label.ends_with('-') && label.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')));
                if !valid_host || url.user.is_empty() || url.port == 0 || value.chars().any(char::is_control)
                    || url.database.contains(['/', '\\', '?', '#', '%']) || url.host.contains(['?', '#', '%', '/', '\\']) {
                    return Err(invalid("SLIMS_DB", "invalid user, port, host or database (URL options/escapes are unsupported)"));
                }
                app.db = DbConfig::MySql(url);
            }
        }
        for (key, target) in [("SLIMS_LIBRARY_NAME", &mut app.library_name), ("SLIMS_LIBRARY_SUBNAME", &mut app.library_subname)] {
            if let Some(value) = get(key) {
                if value.trim().is_empty() || value.len() > 512 || value.chars().any(char::is_control) { return Err(invalid(key, "must be 1–512 bytes of non-control text")); }
                *target = value;
            }
        }
        app.popular_limit = number(&mut get, "SLIMS_POPULAR_LIMIT", app.popular_limit, 1, 50)?;
        app.latest_limit = number(&mut get, "SLIMS_LATEST_LIMIT", app.latest_limit, 1, 50)?;
        app.cache_ttl_secs = number(&mut get, "SLIMS_CACHE_TTL", app.cache_ttl_secs as usize, 0, 604800)? as u64;
        app.http_cache_lifetime = number(&mut get, "SLIMS_HTTP_CACHE_LIFETIME", app.http_cache_lifetime as usize, 0, 86400)? as u32;
        for (key, target) in [
            ("SLIMS_MEMBER_EXPERIMENTAL", &mut app.member_enabled), ("SLIMS_MEMBER_INSECURE_COOKIE", &mut app.member_insecure_cookie),
            ("SLIMS_DEMO_RESERVATIONS", &mut app.demo_reservations), ("SLIMS_MEMBER_TEST_WRITES", &mut app.member_writes_enabled),
            ("SLIMS_MEMBER_TEST_RESERVATIONS", &mut app.member_test_reservations), ("SLIMS_HTTP_ACCESS_LOG", &mut server.access_log),
        ] { *target = boolean(&mut get, key, *target)?; }
        if let Some(value) = get("SLIMS_SESSION_DB") {
            let path = PathBuf::from(value);
            if !path.is_absolute() { return Err(invalid("SLIMS_SESSION_DB", "must be an absolute private local path")); }
            app.member_session_db = Some(path);
        }
        if let Some(value) = get("SLIMS_DEMO_MEMBER_HASH") {
            if value.is_empty() || value.len() > 128 || value.chars().any(char::is_control) { return Err(invalid("SLIMS_DEMO_MEMBER_HASH", "must be a nonempty hash of at most 128 bytes")); }
            app.demo_member_hash = Some(value);
        }
        if app.member_insecure_cookie && !app.member_enabled { return Err(invalid("SLIMS_MEMBER_INSECURE_COOKIE", "requires experimental member mode")); }
        if app.demo_member_hash.is_some() && (!app.member_enabled || !app.is_demo()) { return Err(invalid("SLIMS_DEMO_MEMBER_HASH", "requires experimental memory member mode")); }
        server.max_connections = number(&mut get, "SLIMS_HTTP_MAX_CONNECTIONS", server.max_connections, 1, 1024)?;
        server.max_requests = number(&mut get, "SLIMS_HTTP_MAX_REQUESTS", server.max_requests, 1, 1000)?;
        server.read_budget = Duration::from_secs(number(&mut get, "SLIMS_HTTP_READ_SECONDS", 15, 1, 60)? as u64);
        server.write_budget = Duration::from_secs(number(&mut get, "SLIMS_HTTP_WRITE_SECONDS", 15, 1, 60)? as u64);
        Ok(Self { app, server })
    }
}
fn boolean(get: &mut impl FnMut(&'static str) -> Option<String>, key: &'static str, default: bool) -> Result<bool, ConfigError> {
    match get(key).as_deref() { None => Ok(default), Some("1") => Ok(true), Some("0") => Ok(false), _ => Err(invalid(key, "must be exactly 0 or 1")) }
}
fn number(get: &mut impl FnMut(&'static str) -> Option<String>, key: &'static str, default: usize, min: usize, max: usize) -> Result<usize, ConfigError> {
    match get(key) {
        None => Ok(default),
        Some(value) => value.parse::<usize>().ok().filter(|n| (min..=max).contains(n))
            .ok_or_else(|| invalid(key, "must be an integer within the documented range")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    fn config(values: &[(&str, &str)]) -> Result<RuntimeConfig, ConfigError> { RuntimeConfig::from_lookup(|key| values.iter().find(|(k, _)| *k == key).map(|(_, v)| v.to_string())) }
    #[test]
    fn defaults_and_typed_overrides() {
        let c = config(&[]).unwrap();
        assert_eq!(c.server.max_connections, 128);
        assert!(c.server.access_log);
        let c = config(&[("SLIMS_ADDR", "0.0.0.0:0"), ("SLIMS_HTTP_MAX_CONNECTIONS", "4"), ("SLIMS_HTTP_READ_SECONDS", "2"), ("SLIMS_HTTP_ACCESS_LOG", "0")]).unwrap();
        assert_eq!(c.app.addr, "0.0.0.0:0"); assert_eq!(c.server.max_connections, 4);
        assert_eq!(c.server.read_budget, Duration::from_secs(2)); assert!(!c.server.access_log);
    }
    #[test]
    fn invalid_values_never_silently_fallback_or_leak_secrets() {
        for (key, value) in [("SLIMS_DB", "mysql://alice:super-secret@db/"), ("SLIMS_DB", "mysql://alice:super-secret@db:0/lib"),
            ("SLIMS_MEMBER_TEST_WRITES", "true"), ("SLIMS_POPULAR_LIMIT", "51"), ("SLIMS_CACHE_TTL", "-1"),
            ("SLIMS_ADDR", "bad-host:port"), ("SLIMS_ROOT", "/missing-root"), ("SLIMS_HTTP_MAX_REQUESTS", "0"),
            ("SLIMS_HTTP_WRITE_SECONDS", "61"), ("SLIMS_SESSION_DB", "relative.sqlite"), ("SLIMS_DEMO_MEMBER_HASH", "secret"),
            ("SLIMS_MEMBER_INSECURE_COOKIE", "1"), ("SLIMS_LIBRARY_NAME", "name\nsecret")] {
            let error = match config(&[(key, value)]) { Err(e) => e, Ok(_) => panic!("unexpected accepted setting {key}") };
            assert_eq!(error.variable, key); assert!(!error.to_string().contains("secret"));
        }
        let url = MySqlUrl::parse("mysql://alice:super-secret@localhost/lib").unwrap();
        assert!(!format!("{url:?}").contains("super-secret"));
    }
}
