//! Runtime configuration, read from environment variables so the binary can be
//! dropped behind the same reverse proxy as the PHP app.

use std::path::{Path, PathBuf};

#[derive(Clone, PartialEq, Eq)]
pub struct MySqlUrl {
    pub user: String,
    pub password: String,
    pub host: String,
    pub port: u16,
    pub database: String,
}

impl std::fmt::Debug for MySqlUrl {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MySqlUrl").field("user", &self.user).field("password", &"[REDACTED]")
            .field("host", &self.host).field("port", &self.port).field("database", &self.database).finish()
    }
}

impl MySqlUrl {
    /// Parse `mysql://user:password@host:port/database`.
    pub fn parse(url: &str) -> Result<MySqlUrl, String> {
        let rest = url
            .strip_prefix("mysql://")
            .ok_or_else(|| "database URL must start with mysql://".to_string())?;
        let (credentials, host) = rest
            .rsplit_once('@')
            .ok_or_else(|| "database URL must contain user@host".to_string())?;
        let (user, password) = credentials.split_once(':').unwrap_or((credentials, ""));
        let (host, database) = host.split_once('/').unwrap_or((host, ""));
        let (host, port) = match host.rsplit_once(':') {
            Some((h, p)) => (
                h.to_string(),
                p.parse::<u16>().map_err(|_| "invalid port".to_string())?,
            ),
            None => (host.to_string(), 3306),
        };
        if host.is_empty() || database.is_empty() {
            return Err("database URL must include a host and a database name".to_string());
        }
        Ok(MySqlUrl {
            user: user.to_string(),
            password: password.to_string(),
            host,
            port,
            database: database.to_string(),
        })
    }
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum DbConfig {
    /// Seed the in-process store from `install/*.sql`; no server needed.
    Memory,
    MySql(MySqlUrl),
}

#[derive(Clone, Debug)]
pub struct Config {
    pub addr: String,
    pub root: PathBuf,
    pub db: DbConfig,
    /// Invalid SLIMS_DB is tolerated by Phase 1 only, never by member mode.
    pub db_configuration_error: Option<String>,
    pub library_name: String,
    pub library_subname: String,
    pub popular_limit: usize,
    pub latest_limit: usize,
    pub cache_ttl_secs: u64,
    pub http_cache_lifetime: u32,
    /// Experimental opt-in; not a production authentication service.
    pub member_enabled: bool,
    pub member_insecure_cookie: bool,
    pub demo_reservations: bool,
    /// Dangerous experimental writes, off by default; disposable databases only.
    pub member_writes_enabled: bool,
    /// Additional test-only reservation path; requires exclusive writers.
    pub member_test_reservations: bool,
    /// Absolute path to an optional private, local SQLite session database.
    pub member_session_db: Option<PathBuf>,
    /// Optional hash for M001 on the memory demo backend only.
    pub demo_member_hash: Option<String>,
}

impl Default for Config {
    fn default() -> Self {
        Config {
            addr: "0.0.0.0:8080".to_string(),
            root: find_root(&std::env::current_dir().unwrap_or_else(|_| PathBuf::from("."))),
            db: DbConfig::Memory,
            db_configuration_error: None,
            library_name: "SLiMS 9 Bulian".to_string(),
            library_subname: "Senayan Library Management System".to_string(),
            popular_limit: 6,
            latest_limit: 6,
            cache_ttl_secs: 18_000,
            http_cache_lifetime: 0,
            member_enabled: false,
            member_insecure_cookie: false,
            demo_reservations: false,
            member_writes_enabled: false,
            member_test_reservations: false,
            member_session_db: None,
            demo_member_hash: None,
        }
    }
}

impl Config {
    pub fn from_env() -> Config {
        let mut config = Config::default();
        if let Ok(addr) = std::env::var("SLIMS_ADDR") {
            if !addr.trim().is_empty() {
                config.addr = addr.trim().to_string();
            }
        }
        if let Ok(root) = std::env::var("SLIMS_ROOT") {
            if !root.trim().is_empty() {
                config.root = PathBuf::from(root.trim());
            }
        }
        if let Ok(db) = std::env::var("SLIMS_DB") {
            let db = db.trim();
            if !db.is_empty() && !db.eq_ignore_ascii_case("memory") {
                match MySqlUrl::parse(db) {
                    Ok(url) => config.db = DbConfig::MySql(url),
                    Err(err) => {
                        eprintln!("slims9: invalid SLIMS_DB ({}); member mode will refuse to start", err);
                        config.db_configuration_error = Some(err);
                    }
                }
            }
        }
        if let Ok(name) = std::env::var("SLIMS_LIBRARY_NAME") {
            if !name.trim().is_empty() {
                config.library_name = name.trim().to_string();
            }
        }
        if let Ok(name) = std::env::var("SLIMS_LIBRARY_SUBNAME") {
            if !name.trim().is_empty() {
                config.library_subname = name.trim().to_string();
            }
        }
        if let Ok(limit) = std::env::var("SLIMS_POPULAR_LIMIT") {
            if let Ok(limit) = limit.trim().parse::<usize>() {
                config.popular_limit = limit.clamp(1, 50);
            }
        }
        if let Ok(limit) = std::env::var("SLIMS_LATEST_LIMIT") {
            if let Ok(limit) = limit.trim().parse::<usize>() {
                config.latest_limit = limit.clamp(1, 50);
            }
        }
        if let Ok(ttl) = std::env::var("SLIMS_CACHE_TTL") {
            if let Ok(ttl) = ttl.trim().parse::<u64>() {
                config.cache_ttl_secs = ttl;
            }
        }
        if let Ok(lifetime) = std::env::var("SLIMS_HTTP_CACHE_LIFETIME") {
            if let Ok(lifetime) = lifetime.trim().parse::<u32>() {
                config.http_cache_lifetime = lifetime;
            }
        }
        config.member_enabled = std::env::var("SLIMS_MEMBER_EXPERIMENTAL").as_deref() == Ok("1");
        config.member_insecure_cookie = std::env::var("SLIMS_MEMBER_INSECURE_COOKIE").as_deref() == Ok("1");
        config.demo_reservations = std::env::var("SLIMS_DEMO_RESERVATIONS").as_deref() == Ok("1");
        config.member_writes_enabled = std::env::var("SLIMS_MEMBER_TEST_WRITES").as_deref() == Ok("1");
        config.member_test_reservations = std::env::var("SLIMS_MEMBER_TEST_RESERVATIONS").as_deref() == Ok("1");
        config.member_session_db = std::env::var_os("SLIMS_SESSION_DB").filter(|v| !v.is_empty()).map(PathBuf::from);
        config.demo_member_hash = std::env::var("SLIMS_DEMO_MEMBER_HASH").ok().filter(|v| !v.is_empty());
        config
    }

    pub fn is_demo(&self) -> bool {
        matches!(self.db, DbConfig::Memory)
    }
}

/// Walk upwards until `install/senayan.sql` exists. This lets `cargo run` work
/// from `rust/` while the OPAC keeps serving the PHP app's images and sample
/// data from the repository root.
pub fn find_root(start: &Path) -> PathBuf {
    let mut current = Some(start.to_path_buf());
    while let Some(path) = current {
        if path.join("install/senayan.sql").is_file() {
            return path;
        }
        current = path.parent().map(|p| p.to_path_buf());
    }
    start.to_path_buf()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_mysql_urls() {
        let url = MySqlUrl::parse("mysql://slims:secret@db.internal:3307/senayan").unwrap();
        assert_eq!(url.user, "slims");
        assert_eq!(url.password, "secret");
        assert_eq!(url.host, "db.internal");
        assert_eq!(url.port, 3307);
        assert_eq!(url.database, "senayan");
    }

    #[test]
    fn defaults_the_port() {
        let url = MySqlUrl::parse("mysql://root@localhost/slims").unwrap();
        assert_eq!(url.port, 3306);
        assert_eq!(url.password, "");
    }

    #[test]
    fn rejects_non_mysql_urls() {
        assert!(MySqlUrl::parse("postgres://x/y").is_err());
    }

    #[test]
    fn finds_the_repository_root() {
        let root = find_root(Path::new(env!("CARGO_MANIFEST_DIR")));
        assert!(root.join("install/senayan.sql").is_file());
    }
}
