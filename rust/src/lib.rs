//! SLiMS 9 Bulian, rewritten in Rust.
//!
//! Layout:
//! - [`json`], [`http`], [`date`]: the small runtime the app needs, with no
//!   external crates.
//! - [`store`]: the storage boundary, with an in-process store seeded from the
//!   installer SQL and a MySQL client that speaks to the existing schema.
//! - [`api`]: `api/v1` routes, byte-compatible with the PHP JSON.
//! - [`opac`], [`templates`]: the public catalog.
//! - [`app`]: routing and static files.
//!
//! See `docs/rust-port.md` for the migration map and the record → port → gate
//! workflow that produced this code.

pub mod api;
pub mod app;
pub mod config;
pub mod content;
pub mod librarians;
pub mod crypto;
pub mod date;
pub mod http;
pub mod json;
pub mod models;
#[cfg(feature = "member-crypt")]
pub mod member;
#[cfg(feature = "member-crypt")]
mod member_limit;
#[cfg(feature = "member-crypt")]
mod member_report;
#[cfg(feature = "member-sqlite")]
mod session_sqlite;
pub mod opac;
pub mod store;
pub mod templates;

pub const VERSION: &str = env!("CARGO_PKG_VERSION");

/// Validated process configuration and transport limits.
pub mod runtime;
/// Redacted request correlation and structured operational events.
pub mod telemetry;
