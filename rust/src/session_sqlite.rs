//! Experimental local SQLite session store. A private directory and SQLite's
//! own file locks provide crash-safe atomic rotation and cross-process logout.
//! This is NOT intended for shared/network filesystems or multiple hosts.
use std::ffi::CString;
use std::fs::{self, OpenOptions};
use std::io;
use std::os::raw::{c_char, c_int, c_void};
use std::os::unix::ffi::OsStrExt;
use std::os::unix::fs::{MetadataExt, OpenOptionsExt};
use std::path::{Path, PathBuf};
use std::ptr;

use crate::crypto::sha256;
use crate::member::{constant_eq, now_seconds, random_token, Session, SessionMember, BasketAction, BasketResult, apply_basket, LIFETIME_SECONDS, MAX_SESSIONS, MAX_BASKET};

#[link(name = ":libsqlite3.so.0")]
extern "C" {
    fn sqlite3_open_v2(name: *const c_char, db: *mut *mut c_void, flags: c_int, vfs: *const c_char) -> c_int;
    fn sqlite3_close(db: *mut c_void) -> c_int;
    fn sqlite3_busy_timeout(db: *mut c_void, millis: c_int) -> c_int;
    fn sqlite3_prepare_v2(db: *mut c_void, sql: *const c_char, n: c_int, stmt: *mut *mut c_void, tail: *mut *const c_char) -> c_int;
    fn sqlite3_bind_text(stmt: *mut c_void, index: c_int, text: *const c_char, len: c_int, destroy: Option<unsafe extern "C" fn(*mut c_void)>) -> c_int;
    fn sqlite3_step(stmt: *mut c_void) -> c_int;
    fn sqlite3_column_count(stmt: *mut c_void) -> c_int;
    fn sqlite3_column_type(stmt: *mut c_void, index: c_int) -> c_int;
    fn sqlite3_column_text(stmt: *mut c_void, index: c_int) -> *const u8;
    fn sqlite3_column_bytes(stmt: *mut c_void, index: c_int) -> c_int;
    fn sqlite3_finalize(stmt: *mut c_void) -> c_int;
    fn sqlite3_changes(db: *mut c_void) -> c_int;
    fn geteuid() -> u32;
}

fn error() -> io::Error { io::Error::new(io::ErrorKind::Other, "session storage unavailable") }
fn hashed(id: &str) -> String { sha256(id.as_bytes()).iter().map(|b| format!("{b:02x}")).collect() }
fn valid_hex(s: &str) -> bool { s.len() == 64 && s.bytes().all(|b| b.is_ascii_hexdigit()) }

struct Db(*mut c_void);
impl Drop for Db { fn drop(&mut self) { unsafe { sqlite3_close(self.0); } } }
impl Db {
    fn open(path: &Path) -> io::Result<Self> {
        // The directory and file are rechecked before every open; SQLite may
        // create journal files alongside the db, hence the private directory.
        check_path(path)?;
        let name = CString::new(path.as_os_str().as_bytes()).map_err(|_| error())?;
        let mut db = ptr::null_mut();
        let rc = unsafe { sqlite3_open_v2(name.as_ptr(), &mut db, 2 | 0x10000, ptr::null()) };
        if rc != 0 { if !db.is_null() { unsafe { sqlite3_close(db); } } return Err(error()); }
        let db = Self(db);
        if unsafe { sqlite3_busy_timeout(db.0, 3000) } != 0 { return Err(error()); }
        db.execute("PRAGMA synchronous=FULL", &[])?;
        let mode = db.query("PRAGMA journal_mode", &[])?;
        if mode.first().and_then(|row| row.first()).and_then(|cell| cell.as_deref()) != Some("delete") {
            return Err(error());
        }
        Ok(db)
    }
    fn query(&self, sql: &str, params: &[&str]) -> io::Result<Vec<Vec<Option<String>>>> {
        let sql = CString::new(sql).map_err(|_| error())?;
        let values: Vec<CString> = params.iter().map(|v| CString::new(*v).map_err(|_| error())).collect::<io::Result<_>>()?;
        let mut raw = ptr::null_mut();
        if unsafe { sqlite3_prepare_v2(self.0, sql.as_ptr(), -1, &mut raw, ptr::null_mut()) } != 0 { return Err(error()); }
        struct Statement(*mut c_void);
        impl Drop for Statement { fn drop(&mut self) { unsafe { sqlite3_finalize(self.0); } } }
        let stmt = Statement(raw);
        // SQLITE_STATIC is safe: `values` outlives the statement and its step.
        for (i, value) in values.iter().enumerate() {
            if unsafe { sqlite3_bind_text(stmt.0, (i + 1) as c_int, value.as_ptr(), -1, None) } != 0 { return Err(error()); }
        }
        let mut rows = Vec::new();
        loop {
            match unsafe { sqlite3_step(stmt.0) } {
                101 => break, // SQLITE_DONE
                100 => { // SQLITE_ROW
                    let mut row = Vec::new();
                    for index in 0..unsafe { sqlite3_column_count(stmt.0) } {
                        if unsafe { sqlite3_column_type(stmt.0, index) } == 5 { row.push(None); continue; }
                        let text = unsafe { sqlite3_column_text(stmt.0, index) };
                        let len = unsafe { sqlite3_column_bytes(stmt.0, index) };
                        if text.is_null() || len < 0 { return Err(error()); }
                        let bytes = unsafe { std::slice::from_raw_parts(text, len as usize) };
                        row.push(Some(std::str::from_utf8(bytes).map_err(|_| error())?.to_owned()));
                    }
                    rows.push(row);
                }
                _ => return Err(error()),
            }
        }
        Ok(rows)
    }
    fn execute(&self, sql: &str, params: &[&str]) -> io::Result<usize> {
        self.query(sql, params)?;
        Ok(unsafe { sqlite3_changes(self.0) } as usize)
    }
    fn transaction(&self) -> io::Result<Txn<'_>> {
        self.execute("BEGIN IMMEDIATE", &[])?;
        Ok(Txn { db: self, finished: false })
    }
}
struct Txn<'a> { db: &'a Db, finished: bool }
impl Txn<'_> {
    fn commit(mut self) -> io::Result<()> {
        self.db.execute("COMMIT", &[])?;
        self.finished = true;
        Ok(())
    }
}
impl Drop for Txn<'_> {
    fn drop(&mut self) { if !self.finished { let _ = self.db.execute("ROLLBACK", &[]); } }
}

fn check_path(path: &Path) -> io::Result<()> {
    if !path.is_absolute() { return Err(error()); }
    let parent = path.parent().ok_or_else(error)?;
    let dir = fs::symlink_metadata(parent)?;
    let file = fs::symlink_metadata(path)?;
    let uid = unsafe { geteuid() };
    if !dir.file_type().is_dir() || dir.uid() != uid || dir.mode() & 0o077 != 0 ||
       !file.file_type().is_file() || file.uid() != uid || file.mode() & 0o077 != 0 || file.nlink() != 1 {
        return Err(error());
    }
    Ok(())
}

pub(crate) struct SqliteSessions { path: PathBuf }
impl SqliteSessions {
    pub fn new(path: PathBuf) -> io::Result<Self> {
        if !path.is_absolute() { return Err(error()); }
        let parent = path.parent().ok_or_else(error)?;
        let dir = fs::symlink_metadata(parent)?;
        if !dir.file_type().is_dir() || dir.uid() != unsafe { geteuid() } || dir.mode() & 0o077 != 0 { return Err(error()); }
        // Never follow a final symlink and never change permissions of an
        // existing file. create_new is atomic; an existing file is checked.
        match OpenOptions::new().write(true).create_new(true).mode(0o600).open(&path) {
            Ok(file) => { file.sync_all()?; }
            Err(e) if e.kind() == io::ErrorKind::AlreadyExists => (),
            Err(e) => return Err(e),
        }
        check_path(&path)?;
        let store = Self { path };
        let db = Db::open(&store.path)?;
        let txn = db.transaction()?;
        db.execute("CREATE TABLE IF NOT EXISTS rust_member_sessions (id_hash TEXT PRIMARY KEY NOT NULL, csrf TEXT NOT NULL, member_id TEXT, member_name TEXT, expires INTEGER NOT NULL, credential_version TEXT, basket TEXT NOT NULL DEFAULT '')", &[])?;
        let columns = db.query("PRAGMA table_info(rust_member_sessions)", &[])?;
        let has_version = columns.iter().any(|row| row.get(1).and_then(|v| v.as_deref()) == Some("credential_version"));
        if !has_version {
            db.execute("ALTER TABLE rust_member_sessions ADD COLUMN credential_version TEXT", &[])?;
            // Old authenticated sessions have no credential binding; revoke
            // them atomically rather than accepting unverifiable logins.
            db.execute("DELETE FROM rust_member_sessions WHERE member_id IS NOT NULL", &[])?;
        }
        let has_basket = columns.iter().any(|row| row.get(1).and_then(|v| v.as_deref()) == Some("basket"));
        if !has_basket { db.execute("ALTER TABLE rust_member_sessions ADD COLUMN basket TEXT NOT NULL DEFAULT ''", &[])?; }
        db.query("SELECT id_hash, csrf, member_id, member_name, expires, credential_version, basket FROM rust_member_sessions LIMIT 0", &[])?;
        txn.commit()?;
        Ok(store)
    }
    fn read_from(db: &Db, id: &str, now: u64) -> io::Result<Option<Session>> {
        if !valid_hex(id) { return Ok(None); }
        let rows = db.query("SELECT csrf, member_id, member_name, expires, credential_version, basket FROM rust_member_sessions WHERE id_hash=? AND expires>?", &[&hashed(id), &now.to_string()])?;
        if rows.is_empty() { return Ok(None); }
        let row = &rows[0];
        let csrf = row[0].clone().ok_or_else(error)?;
        let expires = row[3].as_ref().and_then(|v| v.parse::<u64>().ok()).ok_or_else(error)?;
        if !valid_hex(&csrf) { return Err(error()); }
        let member = match (&row[1], &row[2], &row[4]) {
            (None, None, None) => None,
            (Some(id), Some(name), Some(version)) if !id.is_empty() && id.len() <= 80 && name.len() <= 1024 && valid_hex(version) => Some(SessionMember { id: id.clone(), name: name.clone(), credential_version: version.clone() }),
            _ => return Err(error()),
        };
        let raw_basket = row[5].as_deref().ok_or_else(error)?;
        let mut basket = Vec::new();
        if !raw_basket.is_empty() {
            for value in raw_basket.split(',') {
                let id = value.parse::<i64>().ok().filter(|id| *id > 0).ok_or_else(error)?;
                if basket.contains(&id) || basket.len() >= MAX_BASKET { return Err(error()); }
                basket.push(id);
            }
        }
        Ok(Some(Session { csrf, member, expires, basket }))
    }
    pub fn read(&self, id: &str) -> io::Result<Option<Session>> {
        Self::read_from(&Db::open(&self.path)?, id, now_seconds()?)
    }
    pub fn issue(&self, member: Option<SessionMember>) -> io::Result<(String, Session)> {
        let now = now_seconds()?;
        let id = random_token()?;
        let session = Session { csrf: random_token()?, member, expires: now.saturating_add(LIFETIME_SECONDS), basket: Vec::new() };
        let db = Db::open(&self.path)?;
        let txn = db.transaction()?;
        db.execute("DELETE FROM rust_member_sessions WHERE expires<=?", &[&now.to_string()])?;
        let rows = db.query("SELECT COUNT(*) FROM rust_member_sessions", &[])?;
        let count = rows[0][0].as_ref().and_then(|n| n.parse::<usize>().ok()).ok_or_else(error)?;
        if count >= MAX_SESSIONS { return Err(error()); }
        insert(&db, &id, &session)?;
        txn.commit()?;
        Ok((id, session))
    }
    pub fn rotate(&self, old: &str, csrf: &str, member: SessionMember) -> io::Result<Option<String>> {
        let id = random_token()?;
        let new_csrf = random_token()?;
        let db = Db::open(&self.path)?;
        let txn = db.transaction()?;
        let now = now_seconds()?;
        let old_session = Self::read_from(&db, old, now)?;
        if !old_session.as_ref().map_or(false, |s| s.member.is_none() && constant_eq(&s.csrf, csrf)) { return Ok(None); }
        db.execute("DELETE FROM rust_member_sessions WHERE id_hash=?", &[&hashed(old)])?;
        insert(&db, &id, &Session { csrf: new_csrf, member: Some(member), expires: now.saturating_add(LIFETIME_SECONDS), basket: Vec::new() })?;
        txn.commit()?;
        Ok(Some(id))
    }
    pub fn modify_basket(&self, id: &str, csrf: &str, action: BasketAction) -> io::Result<Option<BasketResult>> {
        let db = Db::open(&self.path)?;
        let txn = db.transaction()?;
        let Some(mut session) = Self::read_from(&db, id, now_seconds()?)? else { return Ok(None) };
        if session.member.is_none() || !constant_eq(&session.csrf, csrf) { return Ok(None); }
        let result = apply_basket(&mut session.basket, action);
        if result == BasketResult::Changed {
            let basket = session.basket.iter().map(i64::to_string).collect::<Vec<_>>().join(",");
            db.execute("UPDATE rust_member_sessions SET basket=? WHERE id_hash=?", &[&basket, &hashed(id)])?;
        }
        txn.commit()?;
        Ok(Some(result))
    }
    pub fn invalidate_all(&self, id: &str, csrf: &str) -> io::Result<bool> {
        let db = Db::open(&self.path)?;
        let txn = db.transaction()?;
        let Some(session) = Self::read_from(&db, id, now_seconds()?)? else { return Ok(false); };
        if !constant_eq(&session.csrf, csrf) { return Ok(false); }
        let Some(member) = session.member else { return Ok(false); };
        db.execute("DELETE FROM rust_member_sessions WHERE member_id=? COLLATE BINARY", &[&member.id])?;
        txn.commit()?;
        Ok(true)
    }
    pub fn invalidate(&self, id: &str, csrf: &str) -> io::Result<bool> {
        let db = Db::open(&self.path)?;
        let txn = db.transaction()?;
        let session = Self::read_from(&db, id, now_seconds()?)?;
        if !session.as_ref().map_or(false, |s| constant_eq(&s.csrf, csrf)) { return Ok(false); }
        db.execute("DELETE FROM rust_member_sessions WHERE id_hash=?", &[&hashed(id)])?;
        txn.commit()?;
        Ok(true)
    }
    #[cfg(test)]
    pub fn with_authenticated<T>(&self, id: &str, csrf: &str, f: impl FnOnce(&str) -> T) -> io::Result<Option<T>> {
        self.with_basket_authenticated(id, csrf, |member, _| f(member))
    }
    pub fn with_basket_authenticated<T>(&self, id: &str, csrf: &str, f: impl FnOnce(&str, &[i64]) -> T) -> io::Result<Option<T>> {
        let db = Db::open(&self.path)?;
        let _txn = db.transaction()?; // serializes against logout across processes
        let session = Self::read_from(&db, id, now_seconds()?)?;
        let Some(session) = session else { return Ok(None) };
        if !constant_eq(&session.csrf, csrf) { return Ok(None); }
        let Some(member) = session.member else { return Ok(None) };
        Ok(Some(f(&member.id, &session.basket)))
    }
}
fn insert(db: &Db, id: &str, session: &Session) -> io::Result<()> {
    let hash = hashed(id);
    let expires = session.expires.to_string();
    match &session.member {
        Some(member) => { db.execute("INSERT INTO rust_member_sessions (id_hash, csrf, member_id, member_name, expires, credential_version) VALUES (?, ?, ?, ?, ?, ?)", &[&hash, &session.csrf, &member.id, &member.name, &expires, &member.credential_version])?; }
        None => { db.execute("INSERT INTO rust_member_sessions (id_hash, csrf, member_id, member_name, expires, credential_version) VALUES (?, ?, NULL, NULL, ?, NULL)", &[&hash, &session.csrf, &expires])?; }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::{symlink, PermissionsExt};
    use std::sync::Arc;

    struct TempDir(PathBuf);
    impl TempDir {
        fn new() -> Self {
            let path = std::env::temp_dir().join(format!("slims-session-test-{}-{}", std::process::id(), random_token().unwrap()));
            fs::create_dir(&path).unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o700)).unwrap();
            Self(path)
        }
        fn db(&self) -> PathBuf { self.0.join("sessions.sqlite") }
    }
    impl Drop for TempDir { fn drop(&mut self) { let _ = fs::remove_dir_all(&self.0); } }

    #[test]
    fn restart_rotation_expiry_and_revocation() {
        let dir = TempDir::new();
        let first = SqliteSessions::new(dir.db()).unwrap();
        let (anonymous, s) = first.issue(None).unwrap();
        let second = SqliteSessions::new(dir.db()).unwrap();
        assert!(second.read(&anonymous).unwrap().is_some());
        let authenticated = second.rotate(&anonymous, &s.csrf, SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "a".repeat(64) }).unwrap().unwrap();
        assert!(first.read(&anonymous).unwrap().is_none());
        let auth = first.read(&authenticated).unwrap().unwrap();
        assert_eq!(auth.member.unwrap().id, "M001");
        assert_ne!(auth.csrf, s.csrf);
        assert!(!second.invalidate(&authenticated, "bad").unwrap());
        assert!(second.invalidate(&authenticated, &auth.csrf).unwrap());
        assert!(first.read(&authenticated).unwrap().is_none());
        let (expired, _) = first.issue(None).unwrap();
        let db = Db::open(&dir.db()).unwrap();
        db.execute("UPDATE rust_member_sessions SET expires=0 WHERE id_hash=?", &[&hashed(&expired)]).unwrap();
        assert!(first.read(&expired).unwrap().is_none());
        assert!(!fs::read(&dir.db()).unwrap().windows(expired.len()).any(|bytes| bytes == expired.as_bytes()));
    }

    #[test]
    fn logout_all_is_atomic_fails_closed_and_matches_member_ids_exactly() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let member = |id: &str| Some(SessionMember { id: id.into(), name: "Name".into(), credential_version: "a".repeat(64) });
        let (a, sa) = store.issue(member("M001")).unwrap();
        let (b, _) = store.issue(member("M001")).unwrap();
        let (lowercase, _) = store.issue(member("m001")).unwrap();
        let (anon, anonymous) = store.issue(None).unwrap();
        assert!(!store.invalidate_all(&anon, &anonymous.csrf).unwrap());
        assert!(!store.invalidate_all(&a, "bad").unwrap());
        let db = Db::open(&dir.db()).unwrap();
        db.execute("CREATE TRIGGER deny_logout BEFORE DELETE ON rust_member_sessions WHEN OLD.member_id='M001' BEGIN SELECT RAISE(FAIL, 'test'); END", &[]).unwrap();
        assert!(store.invalidate_all(&a, &sa.csrf).is_err());
        for id in [&a, &b, &lowercase, &anon] { assert!(store.read(id).unwrap().is_some()); }
        db.execute("DROP TRIGGER deny_logout", &[]).unwrap();
        db.execute("UPDATE rust_member_sessions SET expires=0 WHERE id_hash=?", &[&hashed(&a)]).unwrap();
        assert!(!store.invalidate_all(&a, &sa.csrf).unwrap());
        assert!(store.read(&b).unwrap().is_some());
        let sb = store.read(&b).unwrap().unwrap();
        assert!(store.invalidate_all(&b, &sb.csrf).unwrap());
        assert!(store.read(&b).unwrap().is_none());
        assert!(store.read(&lowercase).unwrap().is_some());
        assert!(store.read(&anon).unwrap().is_some());
    }

    #[test]
    fn basket_migration_preserves_bound_sessions() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let (id, session) = store.issue(Some(SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "a".repeat(64) })).unwrap();
        // Recreate the schema used before baskets, keeping an authenticated row.
        let db = Db::open(&dir.db()).unwrap();
        db.execute("ALTER TABLE rust_member_sessions RENAME TO old_sessions", &[]).unwrap();
        db.execute("CREATE TABLE rust_member_sessions (id_hash TEXT PRIMARY KEY NOT NULL, csrf TEXT NOT NULL, member_id TEXT, member_name TEXT, expires INTEGER NOT NULL, credential_version TEXT)", &[]).unwrap();
        db.execute("INSERT INTO rust_member_sessions SELECT id_hash, csrf, member_id, member_name, expires, credential_version FROM old_sessions", &[]).unwrap();
        db.execute("DROP TABLE old_sessions", &[]).unwrap();
        drop(db);
        let migrated = SqliteSessions::new(dir.db()).unwrap();
        let restored = migrated.read(&id).unwrap().unwrap();
        assert_eq!(restored.csrf, session.csrf);
        assert_eq!(restored.member.unwrap().id, "M001");
        assert!(restored.basket.is_empty());
    }

    #[test]
    fn concurrent_basket_updates_are_serialized_across_instances() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let (id, session) = store.issue(Some(SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "a".repeat(64) })).unwrap();
        let barrier = Arc::new(std::sync::Barrier::new(8));
        let threads: Vec<_> = (1..=8).map(|title_id| {
            let path = dir.db(); let id = id.clone(); let csrf = session.csrf.clone(); let barrier = barrier.clone();
            std::thread::spawn(move || {
                let instance = SqliteSessions::new(path).unwrap();
                barrier.wait();
                instance.modify_basket(&id, &csrf, BasketAction::Add(title_id)).unwrap()
            })
        }).collect();
        for thread in threads { assert_eq!(thread.join().unwrap(), Some(BasketResult::Changed)); }
        let mut basket = store.read(&id).unwrap().unwrap().basket;
        basket.sort();
        assert_eq!(basket, (1..=8).collect::<Vec<_>>());
    }

    #[test]
    fn concurrent_instances_rotate_exactly_once() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let (id, s) = store.issue(None).unwrap();
        let barrier = Arc::new(std::sync::Barrier::new(8));
        let threads: Vec<_> = (0..8).map(|_| {
            let path = dir.db(); let id = id.clone(); let csrf = s.csrf.clone(); let barrier = barrier.clone();
            std::thread::spawn(move || {
                let instance = SqliteSessions::new(path).unwrap();
                barrier.wait();
                instance.rotate(&id, &csrf, SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "a".repeat(64) }).unwrap()
            })
        }).collect();
        let ids: Vec<_> = threads.into_iter().filter_map(|t| t.join().unwrap()).collect();
        assert_eq!(ids.len(), 1);
        assert!(store.read(&id).unwrap().is_none());
        assert!(store.read(&ids[0]).unwrap().is_some());
    }

    #[test]
    fn authenticated_mutation_serializes_against_logout() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let (anon, session) = store.issue(None).unwrap();
        let id = store.rotate(&anon, &session.csrf, SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "a".repeat(64) }).unwrap().unwrap();
        let csrf = store.read(&id).unwrap().unwrap().csrf;
        let (entered_tx, entered_rx) = std::sync::mpsc::channel();
        let (release_tx, release_rx) = std::sync::mpsc::channel();
        let (done_tx, done_rx) = std::sync::mpsc::channel();
        let path = dir.db();
        let id2 = id.clone(); let csrf2 = csrf.clone();
        let mutation = std::thread::spawn(move || {
            let store = SqliteSessions::new(path).unwrap();
            store.with_authenticated(&id2, &csrf2, |mid| {
                assert_eq!(mid, "M001");
                entered_tx.send(()).unwrap();
                release_rx.recv().unwrap();
            }).unwrap().unwrap();
        });
        entered_rx.recv().unwrap();
        let path = dir.db(); let id2 = id.clone(); let csrf2 = csrf.clone();
        let logout = std::thread::spawn(move || {
            let store = SqliteSessions::new(path).unwrap();
            done_tx.send(store.invalidate(&id2, &csrf2).unwrap()).unwrap();
        });
        assert!(done_rx.recv_timeout(std::time::Duration::from_millis(100)).is_err());
        release_tx.send(()).unwrap();
        mutation.join().unwrap();
        assert!(done_rx.recv_timeout(std::time::Duration::from_secs(3)).unwrap());
        logout.join().unwrap();
        assert!(store.read(&id).unwrap().is_none());
    }

    #[test]
    fn migration_revokes_old_authenticated_sessions_and_preserves_anonymous() {
        let dir = TempDir::new();
        fs::write(dir.db(), "").unwrap();
        fs::set_permissions(dir.db(), fs::Permissions::from_mode(0o600)).unwrap();
        let db = Db::open(&dir.db()).unwrap();
        db.execute("CREATE TABLE rust_member_sessions (id_hash TEXT PRIMARY KEY NOT NULL, csrf TEXT NOT NULL, member_id TEXT, member_name TEXT, expires INTEGER NOT NULL)", &[]).unwrap();
        let anonymous = random_token().unwrap();
        let authenticated = random_token().unwrap();
        let csrf = random_token().unwrap();
        let expiry = (now_seconds().unwrap() + 1800).to_string();
        db.execute("INSERT INTO rust_member_sessions VALUES (?, ?, NULL, NULL, ?)", &[&hashed(&anonymous), &csrf, &expiry]).unwrap();
        db.execute("INSERT INTO rust_member_sessions VALUES (?, ?, 'M001', 'Siti', ?)", &[&hashed(&authenticated), &csrf, &expiry]).unwrap();
        drop(db);
        let barrier = Arc::new(std::sync::Barrier::new(2));
        let threads: Vec<_> = (0..2).map(|_| {
            let path = dir.db(); let barrier = barrier.clone();
            std::thread::spawn(move || { barrier.wait(); SqliteSessions::new(path).unwrap() })
        }).collect();
        let stores: Vec<_> = threads.into_iter().map(|t| t.join().unwrap()).collect();
        for store in &stores {
            assert!(store.read(&anonymous).unwrap().unwrap().member.is_none());
            assert!(store.read(&authenticated).unwrap().is_none());
        }
        let id = stores[0].rotate(&anonymous, &csrf, SessionMember { id: "M001".into(), name: "Siti".into(), credential_version: "b".repeat(64) }).unwrap().unwrap();
        assert!(stores[1].read(&id).unwrap().is_some());
    }

    #[test]
    fn malformed_schema_rolls_back_migration() {
        let dir = TempDir::new();
        fs::write(dir.db(), "").unwrap();
        fs::set_permissions(dir.db(), fs::Permissions::from_mode(0o600)).unwrap();
        let db = Db::open(&dir.db()).unwrap();
        db.execute("CREATE TABLE rust_member_sessions (id_hash TEXT PRIMARY KEY, member_id TEXT)", &[]).unwrap();
        drop(db);
        assert!(SqliteSessions::new(dir.db()).is_err());
        let db = Db::open(&dir.db()).unwrap();
        let columns = db.query("PRAGMA table_info(rust_member_sessions)", &[]).unwrap();
        assert!(!columns.iter().any(|r| r.get(1).and_then(|v| v.as_deref()) == Some("credential_version")));
    }

    #[test]
    fn rejects_non_delete_journal_modes() {
        let dir = TempDir::new();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let db = Db::open(&dir.db()).unwrap();
        let mode = db.query("PRAGMA journal_mode=WAL", &[]).unwrap();
        assert_eq!(mode[0][0].as_deref(), Some("wal"));
        drop(db);
        assert!(store.issue(None).is_err());
        assert!(SqliteSessions::new(dir.db()).is_err());
    }

    #[test]
    fn rejects_insecure_paths_and_fails_closed_on_corruption() {
        let dir = TempDir::new();
        assert!(SqliteSessions::new(PathBuf::from("relative.sqlite")).is_err());
        let target = dir.0.join("target");
        fs::write(&target, "").unwrap();
        fs::set_permissions(&target, fs::Permissions::from_mode(0o600)).unwrap();
        symlink(&target, dir.db()).unwrap();
        assert!(SqliteSessions::new(dir.db()).is_err());
        fs::remove_file(dir.db()).unwrap();
        fs::write(dir.db(), "").unwrap();
        fs::set_permissions(dir.db(), fs::Permissions::from_mode(0o644)).unwrap();
        assert!(SqliteSessions::new(dir.db()).is_err());
        fs::set_permissions(dir.db(), fs::Permissions::from_mode(0o600)).unwrap();
        fs::set_permissions(&dir.0, fs::Permissions::from_mode(0o755)).unwrap();
        assert!(SqliteSessions::new(dir.db()).is_err());
        fs::set_permissions(&dir.0, fs::Permissions::from_mode(0o700)).unwrap();
        fs::remove_file(dir.db()).unwrap();
        let store = SqliteSessions::new(dir.db()).unwrap();
        let (id, _) = store.issue(None).unwrap();
        fs::write(dir.db(), "corrupt database").unwrap();
        assert!(store.read(&id).is_err());
    }
}
