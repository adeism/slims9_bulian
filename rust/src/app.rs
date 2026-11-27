//! Application state and the top-level router.

use std::path::{Path, PathBuf};
use std::sync::Arc;

use crate::api::{self, ApiContext, Cache};
use crate::config::{Config, DbConfig};
use crate::http::{url_decode, Handler, Request, Response};
use crate::json::Json;
use crate::opac;
use crate::store::memory::MemoryStore;
use crate::store::mysql::MySqlStore;
use crate::store::Store;

pub struct AppState {
    pub config: Config,
    pub store: Box<dyn Store>,
    pub cache: Cache,
    #[cfg(feature = "member-crypt")]
    pub member_sessions: crate::member::Sessions,
    #[cfg(feature = "member-crypt")]
    pub(crate) login_limiter: crate::member_limit::LoginLimiter,
}

impl AppState {
    pub fn new(config: Config) -> Result<AppState, String> {
        #[cfg(not(feature = "member-crypt"))]
        if config.member_enabled {
            return Err("SLIMS_MEMBER_EXPERIMENTAL requires the member-crypt feature".into());
        }
        if config.member_enabled && config.db_configuration_error.is_some() {
            return Err("member mode refuses an invalid SLIMS_DB".into());
        }
        if config.member_session_db.is_some() && !config.member_enabled {
            return Err("SLIMS_SESSION_DB requires experimental member mode".into());
        }
        if config.member_writes_enabled && !config.member_enabled {
            return Err("test member writes require experimental member mode".into());
        }
        if config.member_writes_enabled {
            if let DbConfig::MySql(url) = &config.db {
                if url.host != "127.0.0.1" || !url.database.starts_with("slims_test") {
                    return Err("test member writes require 127.0.0.1 and a disposable slims_test* database".into());
                }
            }
        }
        if config.member_test_reservations && (!config.member_writes_enabled || !matches!(config.db, DbConfig::MySql(_))) {
            return Err("test reservations require test member writes and a disposable MySQL database".into());
        }
        if config.demo_reservations && (!config.member_enabled || !matches!(config.db, DbConfig::Memory)) {
            return Err("demo reservations require experimental member mode and the memory backend".into());
        }
        let store: Box<dyn Store> = match &config.db {
            DbConfig::Memory => {
                let mut store = MemoryStore::load(&config.root, true);
                if config.demo_reservations { store.enable_demo_reservations(); }
                if let Some(hash) = &config.demo_member_hash { store.set_demo_member_password("M001", hash); }
                Box::new(store)
            },
            DbConfig::MySql(url) => Box::new(MySqlStore::new(url.clone())),
        };
        let cache = Cache::new(config.cache_ttl_secs);
        #[cfg(feature = "member-crypt")]
        let member_sessions = crate::member::Sessions::new(config.member_session_db.as_deref()).map_err(|e| format!("session store: {}", e))?;
        Ok(AppState { config, store, cache,
            #[cfg(feature = "member-crypt")]
            member_sessions,
            #[cfg(feature = "member-crypt")]
            login_limiter: crate::member_limit::LoginLimiter::default(),
        })
    }

    pub fn backend_name(&self) -> &'static str {
        self.store.backend_name()
    }

    pub fn handler(self: Arc<Self>) -> Handler {
        Arc::new(move |request: &Request| self.handle(request))
    }

    pub fn handle(&self, request: &Request) -> Response {
        let path = request.path.trim_end_matches('/');
        let path = if path.is_empty() { "/" } else { path };

        #[cfg(feature = "member-crypt")]
        if self.config.member_enabled && (path == "/member" || (path == "/index.php" && matches!(request.query("p"), Some("member" | "download_member_loan_report")))) {
            return crate::member::handle(request, self);
        }

        if path == "/healthz" {
            let mut body = Json::obj();
            body.set("status", Json::str("ok"));
            body.set("backend", Json::str(self.backend_name()));
            body.set("library", Json::str(self.config.library_name.clone()));
            return Response::json(200, &body);
        }

        if path == "/api" || path.starts_with("/api/") {
            let ctx = ApiContext {
                store: self.store.as_ref(),
                cache: &self.cache,
                http_cache_lifetime: self.config.http_cache_lifetime,
                popular_limit: self.config.popular_limit,
            };
            return api::handle(request, &ctx);
        }

        if path.starts_with("/static/") {
            let relative = path.trim_start_matches("/static/");
            return serve_static(&self.config.root.join("rust").join("static"), relative);
        }

        if path == "/lib/minigalnano/createthumb.php" {
            return self.thumbnail(request);
        }

        for (prefix, directory) in [
            ("/images/", "images"),
            ("/css/", "css"),
            ("/js/", "js"),
            ("/template/", "template"),
            ("/files/", "files"),
        ] {
            if let Some(relative) = path.strip_prefix(prefix) {
                return serve_static(&self.config.root.join(directory), relative);
            }
        }

        if path == "/favicon.ico" || path == "/webicon.ico" {
            return serve_static(&self.config.root, "webicon.ico");
        }

        if path == "/robots.txt" {
            return Response::text(
                200,
                "User-agent: *\nAllow: /\nDisallow: /api/\n",
            );
        }

        opac::handle(request, self)
    }

    /// PHP's `createthumb.php` resizes cover images. Without an image library
    /// the rewrite serves the original file; the stylesheet sizes it. The URL
    /// shape stays identical so API responses remain byte-compatible.
    fn thumbnail(&self, request: &Request) -> Response {
        let filename = request
            .query("filename")
            .map(url_decode)
            .unwrap_or_else(|| "images/default/image.png".to_string());
        let relative = filename.strip_prefix("images/").unwrap_or(&filename);
        let candidate = self.config.root.join("images").join(relative);
        let root = canonical(&self.config.root.join("images"));
        let path = canonical(&candidate);
        let allowed = path.starts_with(&root);
        let image_extensions = ["jpg", "jpeg", "png", "gif", "webp", "svg"];
        let has_image_extension = path
            .extension()
            .and_then(|e| e.to_str())
            .map(|ext| image_extensions.contains(&ext.to_ascii_lowercase().as_str()))
            .unwrap_or(false);
        if allowed && has_image_extension {
            return serve_path(&path);
        }
        serve_static(&self.config.root, "images/default/image.png")
    }
}

fn canonical(path: &Path) -> PathBuf {
    path.canonicalize().unwrap_or_else(|_| path.to_path_buf())
}

fn serve_static(root: &Path, relative: &str) -> Response {
    if relative.is_empty() || relative.contains("..") || relative.contains('\\') {
        return not_found_text();
    }
    let path = root.join(relative);
    let canonical_root = canonical(root);
    let canonical_path = canonical(&path);
    if !canonical_path.starts_with(&canonical_root) {
        return not_found_text();
    }
    if !canonical_path.is_file() {
        return not_found_text();
    }
    serve_path(&canonical_path)
}

fn serve_path(path: &Path) -> Response {
    match std::fs::read(path) {
        Ok(bytes) => {
            let mime = mime_for(path);
            Response::new(200)
                .with_header("Content-Type", mime)
                .with_header("Cache-Control", "public, max-age=3600")
                .with_body(bytes)
        }
        Err(_) => not_found_text(),
    }
}

fn not_found_text() -> Response {
    Response::text(404, "Not found")
}

pub fn mime_for(path: &Path) -> &'static str {
    match path
        .extension()
        .and_then(|ext| ext.to_str())
        .map(|ext| ext.to_ascii_lowercase())
        .as_deref()
    {
        Some("html") | Some("htm") => "text/html; charset=utf-8",
        Some("css") => "text/css; charset=utf-8",
        Some("js") | Some("mjs") => "text/javascript; charset=utf-8",
        Some("json") => "application/json",
        Some("png") => "image/png",
        Some("jpg") | Some("jpeg") => "image/jpeg",
        Some("gif") => "image/gif",
        Some("webp") => "image/webp",
        Some("svg") => "image/svg+xml",
        Some("ico") => "image/x-icon",
        Some("txt") => "text/plain; charset=utf-8",
        Some("pdf") => "application/pdf",
        Some("woff") => "font/woff",
        Some("woff2") => "font/woff2",
        Some("ttf") => "font/ttf",
        Some("otf") => "font/otf",
        Some("xml") => "application/xml",
        Some("xsl") => "application/xml",
        Some("sql") => "text/plain; charset=utf-8",
        _ => "application/octet-stream",
    }
}
