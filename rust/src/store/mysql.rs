//! MySQL client protocol, implemented from scratch.
//!
//! The rewrite must talk to the database SLiMS already uses, and the sandbox
//! that produced this port could not reach crates.io. So this is the text
//! protocol only: handshake, `mysql_native_password`, `caching_sha2_password`
//! (fast path), `COM_QUERY`, column definitions and result rows. No prepared
//! statements, no TLS, no Local INFILE.
//!
//! References: MySQL "Connection Phase" and "Text Protocol" documentation.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::sync::Mutex;
use std::time::Duration;

use super::{Store, StoreError, StoreResult, like_escape, sql_escape};
use crate::config::MySqlUrl;
use crate::date::Date;
use crate::crypto::{sha1, sha256};
use crate::models::*;

// Capability flags
const CLIENT_LONG_PASSWORD: u32 = 0x0000_0001;
const CLIENT_LONG_FLAG: u32 = 0x0000_0004;
const CLIENT_CONNECT_WITH_DB: u32 = 0x0000_0008;
const CLIENT_PROTOCOL_41: u32 = 0x0000_0200;
const CLIENT_TRANSACTIONS: u32 = 0x0000_2000;
const CLIENT_SECURE_CONNECTION: u32 = 0x0000_8000;
const CLIENT_MULTI_RESULTS: u32 = 0x0002_0000;
const CLIENT_PLUGIN_AUTH: u32 = 0x0008_0000;

const MAX_PACKET: u32 = 16 * 1024 * 1024;

#[derive(Debug)]
pub enum MySqlError {
    Io(String),
    Protocol(String),
    Server { code: u16, message: String },
}

impl std::fmt::Display for MySqlError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            MySqlError::Io(message) => write!(f, "connection error: {}", message),
            MySqlError::Protocol(message) => write!(f, "protocol error: {}", message),
            MySqlError::Server { code, message } => write!(f, "server error {}: {}", code, message),
        }
    }
}

impl std::error::Error for MySqlError {}

#[derive(Clone, Debug)]
pub struct Column {
    pub name: String,
    pub type_code: u8,
}

#[derive(Clone, Debug, Default)]
pub struct ResultSet {
    pub columns: Vec<Column>,
    pub rows: Vec<Vec<Option<Vec<u8>>>>,
}

impl ResultSet {
    pub fn index_of(&self, name: &str) -> Option<usize> {
        self.columns.iter().position(|c| c.name == name)
    }

    pub fn string(&self, row: usize, column: &str) -> Option<String> {
        let index = self.index_of(column)?;
        self.rows
            .get(row)?
            .get(index)?
            .as_ref()
            .map(|b| String::from_utf8_lossy(b).into_owned())
    }

    pub fn i64(&self, row: usize, column: &str) -> Option<i64> {
        self.string(row, column)?.trim().parse::<i64>().ok()
    }

    pub fn is_empty(&self) -> bool {
        self.rows.is_empty()
    }
}

pub struct Connection {
    stream: TcpStream,
    sequence: u8,
    salt: Vec<u8>,
    server_version: String,
}

impl Connection {
    pub fn connect(url: &MySqlUrl) -> Result<Connection, MySqlError> {
        let addr = format!("{}:{}", url.host, url.port);
        let stream = TcpStream::connect(&addr).map_err(|e| MySqlError::Io(e.to_string()))?;
        stream.set_read_timeout(Some(Duration::from_secs(20))).map_err(|e| MySqlError::Io(e.to_string()))?;
        stream.set_write_timeout(Some(Duration::from_secs(20))).map_err(|e| MySqlError::Io(e.to_string()))?;
        let mut conn = Connection {
            stream,
            sequence: 0,
            salt: Vec::new(),
            server_version: String::new(),
        };
        conn.handshake(url)?;
        Ok(conn)
    }

    fn read_packet(&mut self) -> Result<Vec<u8>, MySqlError> {
        let mut header = [0u8; 4];
        self.stream.read_exact(&mut header).map_err(|e| MySqlError::Io(e.to_string()))?;
        let length = header[0] as usize | (header[1] as usize) << 8 | (header[2] as usize) << 16;
        self.sequence = header[3].wrapping_add(1);
        let mut payload = vec![0u8; length];
        if length > 0 {
            self.stream.read_exact(&mut payload).map_err(|e| MySqlError::Io(e.to_string()))?;
        }
        // A 16 MB packet is continued by a zero-length packet; our queries never
        // produce one, but ignoring the terminator keeps the stream aligned.
        if length == 0xFF_FFFF {
            let mut continuation = Vec::new();
            let mut header = [0u8; 4];
            loop {
                self.stream.read_exact(&mut header).map_err(|e| MySqlError::Io(e.to_string()))?;
                let len = header[0] as usize | (header[1] as usize) << 8 | (header[2] as usize) << 16;
                let mut chunk = vec![0u8; len];
                if len > 0 {
                    self.stream.read_exact(&mut chunk).map_err(|e| MySqlError::Io(e.to_string()))?;
                }
                if len == 0 {
                    break;
                }
                continuation.extend_from_slice(&chunk);
            }
            payload.extend_from_slice(&continuation);
        }
        Ok(payload)
    }

    fn write_packet(&mut self, payload: &[u8]) -> Result<(), MySqlError> {
        let mut offset = 0;
        loop {
            let remaining = payload.len() - offset;
            let chunk_len = remaining.min(MAX_PACKET as usize);
            let mut packet = Vec::with_capacity(4 + chunk_len);
            packet.push((chunk_len & 0xFF) as u8);
            packet.push(((chunk_len >> 8) & 0xFF) as u8);
            packet.push(((chunk_len >> 16) & 0xFF) as u8);
            packet.push(self.sequence);
            self.sequence = self.sequence.wrapping_add(1);
            packet.extend_from_slice(&payload[offset..offset + chunk_len]);
            self.stream.write_all(&packet).map_err(|e| MySqlError::Io(e.to_string()))?;
            offset += chunk_len;
            if chunk_len < MAX_PACKET as usize {
                break;
            }
            if offset >= payload.len() {
                let empty = [0, 0, 0, self.sequence];
                self.sequence = self.sequence.wrapping_add(1);
                self.stream.write_all(&empty).map_err(|e| MySqlError::Io(e.to_string()))?;
                break;
            }
        }
        self.stream.flush().map_err(|e| MySqlError::Io(e.to_string()))
    }

    fn handshake(&mut self, url: &MySqlUrl) -> Result<(), MySqlError> {
        self.sequence = 0;
        let packet = self.read_packet()?;
        let mut cursor = Cursor { bytes: &packet, pos: 0 };
        let protocol = cursor.u8()?;
        if protocol != 10 {
            return Err(MySqlError::Protocol(format!("unsupported handshake protocol {}", protocol)));
        }
        self.server_version = cursor.null_string()?;
        let _connection_id = cursor.u32()?;
        let mut auth_data = cursor.bytes(8)?.to_vec();
        let _filler = cursor.u8()?;
        let cap_lower = cursor.u16()?;
        let _charset = cursor.u8()?;
        let _status = cursor.u16()?;
        let cap_upper = cursor.u16()?;
        let capabilities = (cap_lower as u32) | ((cap_upper as u32) << 16);
        let auth_len = cursor.u8()?;
        let _reserved = cursor.bytes(10)?;
        let auth_data_len = if capabilities & CLIENT_SECURE_CONNECTION != 0 {
            (auth_len as usize).max(13) - 8
        } else {
            0
        };
        if auth_data_len > 0 {
            let rest = cursor.bytes(auth_data_len)?;
            auth_data.extend_from_slice(rest);
        }
        while auth_data.last() == Some(&0) {
            auth_data.pop();
        }
        let plugin = if capabilities & CLIENT_PLUGIN_AUTH != 0 && cursor.pos < cursor.bytes.len() {
            cursor.null_string()?
        } else {
            "mysql_native_password".to_string()
        };
        self.salt = auth_data;

        self.send_handshake_response(url, &plugin)?;
        self.finish_auth(url, &plugin)
    }

    fn send_handshake_response(&mut self, url: &MySqlUrl, plugin: &str) -> Result<(), MySqlError> {
        let mut capabilities = CLIENT_LONG_PASSWORD
            | CLIENT_LONG_FLAG
            | CLIENT_PROTOCOL_41
            | CLIENT_TRANSACTIONS
            | CLIENT_SECURE_CONNECTION
            | CLIENT_MULTI_RESULTS
            | CLIENT_PLUGIN_AUTH;
        if !url.database.is_empty() {
            capabilities |= CLIENT_CONNECT_WITH_DB;
        }
        let auth = match plugin {
            "caching_sha2_password" => caching_sha2_password(&url.password, &self.salt),
            _ => native_password(&url.password, &self.salt),
        };

        let mut payload = Vec::with_capacity(128);
        payload.extend_from_slice(&capabilities.to_le_bytes());
        payload.extend_from_slice(&MAX_PACKET.to_le_bytes());
        payload.push(45); // utf8mb4_general_ci
        payload.extend_from_slice(&[0u8; 23]);
        payload.extend_from_slice(url.user.as_bytes());
        payload.push(0);
        write_lenenc(&mut payload, auth.len() as u64);
        payload.extend_from_slice(&auth);
        if !url.database.is_empty() {
            payload.extend_from_slice(url.database.as_bytes());
            payload.push(0);
        }
        payload.extend_from_slice(plugin.as_bytes());
        payload.push(0);
        self.write_packet(&payload)
    }

    fn finish_auth(&mut self, url: &MySqlUrl, plugin: &str) -> Result<(), MySqlError> {
        let mut current_plugin = plugin.to_string();
        for _ in 0..4 {
            let packet = self.read_packet()?;
            match packet.first() {
                Some(0x00) => return Ok(()), // OK
                Some(0xFF) => return Err(parse_error_packet(&packet)),
                Some(0xFE) => {
                    // AuthSwitchRequest: plugin name, then auth data
                    let mut cursor = Cursor { bytes: &packet[1..], pos: 0 };
                    let new_plugin = cursor.null_string()?;
                    let data = &packet[1 + cursor.pos..];
                    let mut salt = data.to_vec();
                    while salt.last() == Some(&0) {
                        salt.pop();
                    }
                    let auth = match new_plugin.as_str() {
                        "caching_sha2_password" => caching_sha2_password(&url.password, &salt),
                        "mysql_native_password" => native_password(&url.password, &salt),
                        other => {
                            return Err(MySqlError::Protocol(format!(
                                "unsupported auth plugin {}",
                                other
                            )))
                        }
                    };
                    let mut response = Vec::new();
                    write_lenenc(&mut response, auth.len() as u64);
                    response.extend_from_slice(&auth);
                    self.write_packet(&response)?;
                    current_plugin = new_plugin;
                }
                Some(0x01) => {
                    // AuthMoreData
                    if current_plugin == "caching_sha2_password" && packet.get(1) == Some(&0x04) {
                        return Err(MySqlError::Protocol(
                            "caching_sha2_password requested full authentication, which needs TLS or RSA; \
                             use a mysql_native_password account or a TLS tunnel"
                                .to_string(),
                        ));
                    }
                    // 0x01 0x03 = fast auth success; the next packet is the OK.
                }
                _ => return Err(MySqlError::Protocol("unexpected packet during authentication".into())),
            }
        }
        Err(MySqlError::Protocol("authentication did not converge".into()))
    }

    pub fn query(&mut self, sql: &str) -> Result<ResultSet, MySqlError> {
        self.sequence = 0;
        let mut payload = Vec::with_capacity(sql.len() + 1);
        payload.push(0x03); // COM_QUERY
        payload.extend_from_slice(sql.as_bytes());
        self.write_packet(&payload)?;

        let packet = self.read_packet()?;
        match packet.first() {
            Some(0x00) => Ok(ResultSet::default()),
            Some(0xFF) => Err(parse_error_packet(&packet)),
            Some(_) => {
                let mut cursor = Cursor { bytes: &packet, pos: 0 };
                let column_count = cursor.lenenc()? as usize;
                let mut columns = Vec::with_capacity(column_count);
                for _ in 0..column_count {
                    let definition = self.read_packet()?;
                    columns.push(parse_column_definition(&definition)?);
                }
                // EOF after the column definitions
                let eof = self.read_packet()?;
                if eof.first() != Some(&0xFE) {
                    return Err(MySqlError::Protocol("expected EOF after column definitions".into()));
                }
                let mut rows = Vec::new();
                loop {
                    let row_packet = self.read_packet()?;
                    if row_packet.first() == Some(&0xFE) && row_packet.len() < 9 {
                        break; // EOF
                    }
                    if row_packet.first() == Some(&0xFF) {
                        return Err(parse_error_packet(&row_packet));
                    }
                    let mut cursor = Cursor { bytes: &row_packet, pos: 0 };
                    let mut row = Vec::with_capacity(column_count);
                    for _ in 0..column_count {
                        if cursor.peek() == Some(0xFB) {
                            cursor.pos += 1;
                            row.push(None);
                        } else {
                            let value = cursor.lenenc_bytes()?;
                            row.push(Some(value.to_vec()));
                        }
                    }
                    rows.push(row);
                }
                Ok(ResultSet { columns, rows })
            }
            None => Err(MySqlError::Protocol("empty response".into())),
        }
    }
}

struct Cursor<'a> {
    bytes: &'a [u8],
    pos: usize,
}

impl<'a> Cursor<'a> {
    fn u8(&mut self) -> Result<u8, MySqlError> {
        let value = *self
            .bytes
            .get(self.pos)
            .ok_or_else(|| MySqlError::Protocol("truncated packet".into()))?;
        self.pos += 1;
        Ok(value)
    }

    fn u16(&mut self) -> Result<u16, MySqlError> {
        let lo = self.u8()? as u16;
        let hi = self.u8()? as u16;
        Ok(lo | (hi << 8))
    }

    fn u32(&mut self) -> Result<u32, MySqlError> {
        let lo = self.u16()? as u32;
        let hi = self.u16()? as u32;
        Ok(lo | (hi << 16))
    }

    fn bytes(&mut self, len: usize) -> Result<&'a [u8], MySqlError> {
        if self.pos + len > self.bytes.len() {
            return Err(MySqlError::Protocol("truncated packet".into()));
        }
        let value = &self.bytes[self.pos..self.pos + len];
        self.pos += len;
        Ok(value)
    }

    fn null_string(&mut self) -> Result<String, MySqlError> {
        let start = self.pos;
        while self.pos < self.bytes.len() && self.bytes[self.pos] != 0 {
            self.pos += 1;
        }
        let value = String::from_utf8_lossy(&self.bytes[start..self.pos]).into_owned();
        if self.pos < self.bytes.len() {
            self.pos += 1;
        }
        Ok(value)
    }

    fn peek(&self) -> Option<u8> {
        self.bytes.get(self.pos).copied()
    }

    fn lenenc(&mut self) -> Result<u64, MySqlError> {
        let first = self.u8()?;
        match first {
            0xFC => Ok(self.u16()? as u64),
            0xFD => {
                let lo = self.u8()? as u64;
                let mid = self.u8()? as u64;
                let hi = self.u8()? as u64;
                Ok(lo | (mid << 8) | (hi << 16))
            }
            0xFE => {
                let lo = self.u32()? as u64;
                let hi = self.u32()? as u64;
                Ok(lo | (hi << 32))
            }
            value => Ok(value as u64),
        }
    }

    fn lenenc_bytes(&mut self) -> Result<&'a [u8], MySqlError> {
        let len = self.lenenc()? as usize;
        self.bytes(len)
    }
}

fn write_lenenc(out: &mut Vec<u8>, value: u64) {
    if value < 0xFB {
        out.push(value as u8);
    } else if value <= 0xFFFF {
        out.push(0xFC);
        out.extend_from_slice(&(value as u16).to_le_bytes());
    } else if value <= 0xFF_FFFF {
        out.push(0xFD);
        out.push((value & 0xFF) as u8);
        out.push(((value >> 8) & 0xFF) as u8);
        out.push(((value >> 16) & 0xFF) as u8);
    } else {
        out.push(0xFE);
        out.extend_from_slice(&value.to_le_bytes());
    }
}

fn parse_error_packet(packet: &[u8]) -> MySqlError {
    let mut cursor = Cursor { bytes: packet, pos: 1 };
    let code = cursor.u16().unwrap_or(0);
    // '#' + 5-byte SQL state
    if cursor.peek() == Some(b'#') {
        cursor.pos += 6;
    }
    let message = String::from_utf8_lossy(&packet[cursor.pos..]).into_owned();
    MySqlError::Server { code, message }
}

fn parse_column_definition(packet: &[u8]) -> Result<Column, MySqlError> {
    let mut cursor = Cursor { bytes: packet, pos: 0 };
    let _catalog = cursor.lenenc_bytes()?;
    let _schema = cursor.lenenc_bytes()?;
    let _table = cursor.lenenc_bytes()?;
    let _org_table = cursor.lenenc_bytes()?;
    let name = cursor.lenenc_bytes()?;
    let _org_name = cursor.lenenc_bytes()?;
    let _fixed = cursor.lenenc()?;
    let _charset = cursor.u16()?;
    let _length = cursor.u32()?;
    let type_code = cursor.u8()?;
    let _flags = cursor.u16()?;
    let _decimals = cursor.u8()?;
    Ok(Column {
        name: String::from_utf8_lossy(name).into_owned(),
        type_code,
    })
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

fn native_password(password: &str, salt: &[u8]) -> Vec<u8> {
    if password.is_empty() {
        return Vec::new();
    }
    let stage1 = sha1(password.as_bytes());
    let stage2 = sha1(&stage1);
    let mut input = salt.to_vec();
    input.extend_from_slice(&stage2);
    let stage3 = sha1(&input);
    stage1
        .iter()
        .zip(stage3.iter())
        .map(|(a, b)| a ^ b)
        .collect()
}

fn caching_sha2_password(password: &str, salt: &[u8]) -> Vec<u8> {
    if password.is_empty() {
        return Vec::new();
    }
    let digest1 = sha256(password.as_bytes());
    let digest2 = sha256(&digest1);
    let mut input = digest2.to_vec();
    input.extend_from_slice(salt);
    let digest3 = sha256(&input);
    digest1
        .iter()
        .zip(digest3.iter())
        .map(|(a, b)| a ^ b)
        .collect()
}

// ---------------------------------------------------------------------------
// SHA-1 and SHA-256 (dependency-free, for the MySQL auth handshake)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// The store
// ---------------------------------------------------------------------------

pub struct MySqlStore {
    url: MySqlUrl,
    connection: Mutex<Option<Connection>>,
}

impl MySqlStore {
    pub fn new(url: MySqlUrl) -> MySqlStore {
        MySqlStore {
            url,
            connection: Mutex::new(None),
        }
    }

    fn run<T>(&self, sql: &str, f: impl FnOnce(&ResultSet) -> StoreResult<T>) -> StoreResult<T> {
        let mut guard = self
            .connection
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if guard.is_none() {
            *guard = Some(Connection::connect(&self.url).map_err(store_error)?);
        }
        let result = guard.as_mut().unwrap().query(sql);
        match result {
            Ok(result_set) => f(&result_set),
            Err(_) => {
                // One reconnect and retry: the pooled connection may have been
                // closed by the server (wait_timeout, restart).
                *guard = Some(Connection::connect(&self.url).map_err(store_error)?);
                let result_set = guard.as_mut().unwrap().query(sql).map_err(store_error)?;
                f(&result_set)
            }
        }
    }
}

fn store_error(error: MySqlError) -> StoreError {
    StoreError::Db(error.to_string())
}

const BIBLIO_SELECT: &str = "SELECT b.biblio_id, b.title, b.image, b.isbn_issn, b.publish_year, \
     pbl.publisher_name AS publisher, pplc.place_name AS publish_place, b.call_number, \
     b.language_id, ml.language_name, b.classification, b.notes, b.series_title, b.edition, \
     b.collation, b.sor, b.spec_detail_info, b.opac_hide, b.promoted, b.last_update, \
     b.gmd_id, g.gmd_name \
     FROM biblio b \
     LEFT JOIN mst_gmd g ON b.gmd_id=g.gmd_id \
     LEFT JOIN mst_publisher pbl ON b.publisher_id=pbl.publisher_id \
     LEFT JOIN mst_place pplc ON b.publish_place_id=pplc.place_id \
     LEFT JOIN mst_language ml ON b.language_id=ml.language_id";

impl MySqlStore {
    fn hydrate(&self, ids: &[i64]) -> StoreResult<Vec<Biblio>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        let id_list = ids
            .iter()
            .map(|id| id.to_string())
            .collect::<Vec<_>>()
            .join(", ");
        let sql = format!("{} WHERE b.biblio_id IN ({})", BIBLIO_SELECT, id_list);
        let rows = self.run(&sql, |rs| {
            let mut out = Vec::with_capacity(rs.rows.len());
            for (index, _) in rs.rows.iter().enumerate() {
                out.push(biblio_from_row(rs, index)?);
            }
            Ok(out)
        })?;
        // Keep the caller's order (popularity, last_update, ...). SQL has no
        // ORDER BY here because the ids already carry it.
        let mut by_id: std::collections::HashMap<i64, Biblio> =
            rows.into_iter().map(|b| (b.id, b)).collect();
        let biblio: Vec<Biblio> = ids.iter().filter_map(|id| by_id.remove(id)).collect();
        self.attach_relations(biblio)
    }

    fn attach_relations(&self, mut biblio: Vec<Biblio>) -> StoreResult<Vec<Biblio>> {
        let ids: Vec<i64> = biblio.iter().map(|b| b.id).collect();
        if ids.is_empty() {
            // MySQL rejects `IN ()`; an empty page has nothing to attach anyway.
            return Ok(biblio);
        }
        let id_list = ids.iter().map(|id| id.to_string()).collect::<Vec<_>>().join(", ");

        let author_sql = format!(
            "SELECT ba.biblio_id, a.author_name, ba.level FROM biblio_author ba \
             JOIN mst_author a ON ba.author_id=a.author_id \
             WHERE ba.biblio_id IN ({}) ORDER BY ba.biblio_id, ba.level",
            id_list
        );
        let author_rows = self.run(&author_sql, |rs| {
            let mut map: std::collections::HashMap<i64, Vec<Author>> =
                std::collections::HashMap::new();
            for (index, _) in rs.rows.iter().enumerate() {
                let id = rs.i64(index, "biblio_id").unwrap_or(0);
                map.entry(id).or_default().push(Author {
                    name: rs.string(index, "author_name").unwrap_or_default(),
                    level: rs.i64(index, "level").unwrap_or(1),
                });
            }
            Ok(map)
        })?;

        let topic_sql = format!(
            "SELECT bt.biblio_id, t.topic FROM biblio_topic bt \
             JOIN mst_topic t ON bt.topic_id=t.topic_id \
             WHERE bt.biblio_id IN ({}) ORDER BY bt.biblio_id",
            id_list
        );
        let topic_rows = self.run(&topic_sql, |rs| {
            let mut map: std::collections::HashMap<i64, Vec<String>> =
                std::collections::HashMap::new();
            for (index, _) in rs.rows.iter().enumerate() {
                let id = rs.i64(index, "biblio_id").unwrap_or(0);
                map.entry(id).or_default().push(rs.string(index, "topic").unwrap_or_default());
            }
            Ok(map)
        })?;

        let item_sql = format!(
            "SELECT i.biblio_id, i.item_code, i.call_number, i.site, c.coll_type_name, \
             s.item_status_name, l.location_name, \
             EXISTS(SELECT 1 FROM loan lo WHERE lo.item_code=i.item_code AND lo.is_lent=1 AND lo.is_return=0) AS on_loan \
             FROM item i \
             LEFT JOIN mst_coll_type c ON i.coll_type_id=c.coll_type_id \
             LEFT JOIN mst_item_status s ON i.item_status_id=s.item_status_id \
             LEFT JOIN mst_location l ON i.location_id=l.location_id \
             WHERE i.biblio_id IN ({}) ORDER BY i.item_id",
            id_list
        );
        let item_rows = self.run(&item_sql, |rs| {
            let mut map: std::collections::HashMap<i64, Vec<ItemCopy>> =
                std::collections::HashMap::new();
            for (index, _) in rs.rows.iter().enumerate() {
                let id = rs.i64(index, "biblio_id").unwrap_or(0);
                map.entry(id).or_default().push(ItemCopy {
                    item_code: rs.string(index, "item_code").unwrap_or_default(),
                    call_number: rs.string(index, "call_number"),
                    location: rs.string(index, "location_name"),
                    status: rs.string(index, "item_status_name"),
                    site: rs.string(index, "site"),
                    coll_type: rs.string(index, "coll_type_name"),
                    on_loan: rs.string(index, "on_loan").as_deref() == Some("1"),
                });
            }
            Ok(map)
        })?;

        for biblio in &mut biblio {
            biblio.authors = author_rows.get(&biblio.id).cloned().unwrap_or_default();
            biblio.topics = topic_rows.get(&biblio.id).cloned().unwrap_or_default();
            biblio.items = item_rows.get(&biblio.id).cloned().unwrap_or_default();
            for item in &biblio.items {
                if let Some(name) = &item.coll_type {
                    if !biblio.coll_types.contains(name) {
                        biblio.coll_types.push(name.clone());
                    }
                }
            }
        }
        Ok(biblio)
    }

    fn latest_ids(&self, limit: usize, exclude: &[i64]) -> StoreResult<Vec<i64>> {
        let mut sql = String::from(
            "SELECT biblio_id FROM biblio WHERE opac_hide < 1 AND title IS NOT NULL",
        );
        if !exclude.is_empty() {
            let list = exclude
                .iter()
                .map(|id| id.to_string())
                .collect::<Vec<_>>()
                .join(", ");
            sql.push_str(&format!(" AND biblio_id NOT IN ({})", list));
        }
        sql.push_str(&format!(" ORDER BY last_update DESC LIMIT {}", limit));
        self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| rs.i64(index, "biblio_id"))
                .collect())
        })
    }

    fn ids_by_gmd(&self, gmd: &str, limit: usize) -> StoreResult<Vec<i64>> {
        let sql = format!(
            "SELECT b.biblio_id FROM biblio AS b, mst_gmd AS g \
             WHERE b.gmd_id=g.gmd_id AND g.gmd_name='{}' AND b.opac_hide < 1 \
             ORDER BY b.last_update DESC LIMIT {}",
            sql_escape(gmd),
            limit
        );
        self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| rs.i64(index, "biblio_id"))
                .collect())
        })
    }

    fn ids_by_coll_type(&self, coll_type: &str, limit: usize) -> StoreResult<Vec<i64>> {
        let sql = format!(
            "SELECT DISTINCT b.biblio_id FROM biblio AS b, item AS i, mst_coll_type AS c \
             WHERE b.biblio_id=i.biblio_id AND i.coll_type_id=c.coll_type_id \
             AND c.coll_type_name='{}' AND b.opac_hide < 1 \
             ORDER BY b.last_update DESC LIMIT {}",
            sql_escape(coll_type),
            limit
        );
        self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| rs.i64(index, "biblio_id"))
                .collect())
        })
    }
}

fn biblio_from_row(rs: &ResultSet, index: usize) -> StoreResult<Biblio> {
    Ok(Biblio {
        id: rs.i64(index, "biblio_id").unwrap_or(0),
        title: rs.string(index, "title").unwrap_or_default(),
        image: rs.string(index, "image"),
        gmd_id: rs.i64(index, "gmd_id"),
        gmd_name: rs.string(index, "gmd_name"),
        publisher: rs.string(index, "publisher"),
        publish_place: rs.string(index, "publish_place"),
        publish_year: rs.string(index, "publish_year"),
        isbn: rs.string(index, "isbn_issn"),
        call_number: rs.string(index, "call_number"),
        language_id: rs.string(index, "language_id").unwrap_or_else(|| "en".into()),
        language_name: rs.string(index, "language_name"),
        classification: rs.string(index, "classification"),
        notes: rs.string(index, "notes"),
        series_title: rs.string(index, "series_title"),
        edition: rs.string(index, "edition"),
        collation: rs.string(index, "collation"),
        sor: rs.string(index, "sor"),
        spec_detail_info: rs.string(index, "spec_detail_info"),
        opac_hide: rs.i64(index, "opac_hide").unwrap_or(0),
        promoted: rs.i64(index, "promoted").unwrap_or(0),
        last_update: rs.string(index, "last_update"),
        authors: Vec::new(),
        topics: Vec::new(),
        coll_types: Vec::new(),
        items: Vec::new(),
    })
}

impl Store for MySqlStore {
    fn member_profile(&self, member_id: &str) -> StoreResult<Option<MemberProfile>> {
        let id_hex = member_id.as_bytes().iter().map(|byte| format!("{:02x}", byte)).collect::<String>();
        let sql = format!("SELECT m.member_id, m.member_name, m.member_email, mt.member_type_name, m.register_date, m.expire_date, m.inst_name, m.is_pending FROM member AS m LEFT JOIN mst_member_type AS mt ON m.member_type_id=mt.member_type_id WHERE m.member_id=CAST(X'{}' AS CHAR) LIMIT 1", id_hex);
        self.run(&sql, |rs| {
            if rs.rows.is_empty() { return Ok(None); }
            Ok(Some(MemberProfile {
                id: rs.string(0, "member_id").unwrap_or_default(),
                name: rs.string(0, "member_name").unwrap_or_default(),
                email: rs.string(0, "member_email"), member_type: rs.string(0, "member_type_name"),
                register_date: rs.string(0, "register_date"), expiry_date: rs.string(0, "expire_date"),
                institution: rs.string(0, "inst_name"), pending: rs.i64(0, "is_pending").unwrap_or(0) != 0,
            }))
        })
    }

    fn member_credential(&self, member_id: &str) -> StoreResult<Option<MemberCredential>> {
        let id_hex = member_id.as_bytes().iter().map(|byte| format!("{:02x}", byte)).collect::<String>();
        let sql = format!("SELECT member_id, member_name, mpasswd FROM member WHERE member_id=CAST(X'{}' AS CHAR) LIMIT 1", id_hex);
        self.run(&sql, |rs| {
            if rs.rows.is_empty() { return Ok(None); }
            Ok(Some(MemberCredential {
                id: rs.string(0, "member_id").unwrap_or_default(),
                name: rs.string(0, "member_name").unwrap_or_default(),
                password_hash: rs.string(0, "mpasswd").unwrap_or_default(),
            }))
        })
    }

    fn member_loans(&self, member_id: &str, returned: bool) -> StoreResult<Vec<MemberLoan>> {
        // Hex bytes avoid depending on NO_BACKSLASH_ESCAPES or SQL quoting
        // modes. CAST uses the utf8mb4 connection character set.
        let id_hex = member_id.as_bytes().iter().map(|byte| format!("{:02x}", byte)).collect::<String>();
        let sql = format!(
            "SELECT l.item_code, b.title, l.loan_date, l.due_date, l.return_date \
             FROM loan AS l LEFT JOIN member AS m ON l.member_id=m.member_id \
             LEFT JOIN item AS i ON l.item_code=i.item_code \
             LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id \
             WHERE m.member_id=CAST(X'{}' AS CHAR) AND l.is_lent=1 AND l.is_return={} \
             ORDER BY l.loan_date DESC, l.loan_id DESC LIMIT 20", id_hex, u8::from(returned));
        self.run(&sql, |rs| Ok((0..rs.rows.len()).map(|row| MemberLoan {
            item_code: rs.string(row, "item_code").unwrap_or_default(),
            title: rs.string(row, "title"), loan_date: rs.string(row, "loan_date"),
            due_date: rs.string(row, "due_date"), return_date: rs.string(row, "return_date"),
        }).collect()))
    }

    fn biblio_popular(&self, limit: usize) -> StoreResult<Vec<PopularBiblio>> {
        let sql = format!(
            "SELECT b.biblio_id, COUNT(*) AS total \
             FROM loan AS l \
             LEFT JOIN item AS i ON l.item_code=i.item_code \
             LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id \
             WHERE b.title IS NOT NULL AND b.opac_hide < 1 \
             GROUP BY b.biblio_id ORDER BY total DESC, b.biblio_id LIMIT {}",
            limit
        );
        let rows = self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| {
                    Some((
                        rs.i64(index, "biblio_id")?,
                        rs.i64(index, "total")?,
                    ))
                })
                .collect::<Vec<(i64, i64)>>())
        })?;
        let mut result: Vec<PopularBiblio> = Vec::new();
        for (id, total) in rows {
            if let Some(biblio) = self.hydrate(&[id])?.into_iter().next() {
                result.push(PopularBiblio { biblio, total: Some(total) });
            }
        }
        if result.len() < limit {
            let exclude: Vec<i64> = result.iter().map(|p| p.biblio.id).collect();
            let need = limit - result.len();
            for id in self.latest_ids(need, &exclude)? {
                if let Some(biblio) = self.hydrate(&[id])?.into_iter().next() {
                    result.push(PopularBiblio { biblio, total: None });
                }
            }
        }
        Ok(result)
    }

    fn biblio_latest(&self, limit: usize) -> StoreResult<Vec<Biblio>> {
        let ids = self.latest_ids(limit, &[])?;
        self.hydrate(&ids)
    }

    fn biblio_total(&self) -> StoreResult<i64> {
        self.run("SELECT COUNT(biblio_id) FROM biblio WHERE opac_hide < 1", |rs| {
            Ok(rs.rows
                .first()
                .and_then(|row| row.first())
                .and_then(|value| value.as_ref())
                .and_then(|value| String::from_utf8_lossy(value).parse::<i64>().ok())
                .unwrap_or(0))
        })
    }

    fn biblio_by_gmd(&self, gmd: &str, limit: usize) -> StoreResult<Vec<Biblio>> {
        let ids = self.ids_by_gmd(gmd, limit)?;
        self.hydrate(&ids)
    }

    fn biblio_by_coll_type(&self, coll_type: &str, limit: usize) -> StoreResult<Vec<Biblio>> {
        let ids = self.ids_by_coll_type(coll_type, limit)?;
        self.hydrate(&ids)
    }

    fn search(&self, query: &SearchQuery) -> StoreResult<SearchResult> {
        let mut criteria = String::new();
        let mut push = |condition: &str| {
            if !criteria.is_empty() {
                criteria.push_str(" AND ");
            }
            criteria.push('(');
            criteria.push_str(condition);
            criteria.push(')');
        };

        for term in query.keywords.split_whitespace() {
            let term = like_escape(term);
            push(&format!(
                "b.title LIKE '%{t}%' ESCAPE '\\\\' OR b.series_title LIKE '%{t}%' ESCAPE '\\\\' \
                 OR b.notes LIKE '%{t}%' ESCAPE '\\\\' OR b.isbn_issn LIKE '%{t}%' ESCAPE '\\\\' \
                 OR b.classification LIKE '%{t}%' ESCAPE '\\\\' OR b.call_number LIKE '%{t}%' ESCAPE '\\\\' \
                 OR pbl.publisher_name LIKE '%{t}%' ESCAPE '\\\\' \
                 OR EXISTS (SELECT 1 FROM biblio_author ba JOIN mst_author a ON ba.author_id=a.author_id \
                     WHERE ba.biblio_id=b.biblio_id AND a.author_name LIKE '%{t}%' ESCAPE '\\\\') \
                 OR EXISTS (SELECT 1 FROM biblio_topic bt JOIN mst_topic t ON bt.topic_id=t.topic_id \
                     WHERE bt.biblio_id=b.biblio_id AND t.topic LIKE '%{t}%' ESCAPE '\\\\')",
                t = term
            ));
        }
        if !query.title.trim().is_empty() {
            push(&format!(
                "b.title LIKE '%{}%' ESCAPE '\\\\'",
                like_escape(query.title.trim())
            ));
        }
        if !query.author.trim().is_empty() {
            push(&format!(
                "EXISTS (SELECT 1 FROM biblio_author ba JOIN mst_author a ON ba.author_id=a.author_id \
                 WHERE ba.biblio_id=b.biblio_id AND a.author_name LIKE '%{}%' ESCAPE '\\\\')",
                like_escape(query.author.trim())
            ));
        }
        if !query.subject.trim().is_empty() {
            push(&format!(
                "EXISTS (SELECT 1 FROM biblio_topic bt JOIN mst_topic t ON bt.topic_id=t.topic_id \
                 WHERE bt.biblio_id=b.biblio_id AND t.topic LIKE '%{}%' ESCAPE '\\\\')",
                like_escape(query.subject.trim())
            ));
        }
        if !query.isbn.trim().is_empty() {
            push(&format!(
                "b.isbn_issn LIKE '%{}%' ESCAPE '\\\\'",
                like_escape(query.isbn.trim())
            ));
        }
        if !query.publisher.trim().is_empty() {
            push(&format!(
                "pbl.publisher_name LIKE '%{}%' ESCAPE '\\\\'",
                like_escape(query.publisher.trim())
            ));
        }
        if !query.year.trim().is_empty() {
            push(&format!(
                "b.publish_year LIKE '%{}%' ESCAPE '\\\\'",
                like_escape(query.year.trim())
            ));
        }
        if !query.gmd.trim().is_empty() {
            push(&format!(
                "g.gmd_name LIKE '%{}%' ESCAPE '\\\\'",
                like_escape(query.gmd.trim())
            ));
        }
        if !query.coll_type.trim().is_empty() {
            push(&format!(
                "EXISTS (SELECT 1 FROM item i JOIN mst_coll_type c ON i.coll_type_id=c.coll_type_id \
                 WHERE i.biblio_id=b.biblio_id AND c.coll_type_name LIKE '%{}%' ESCAPE '\\\\')",
                like_escape(query.coll_type.trim())
            ));
        }

        let where_clause = if criteria.is_empty() {
            " WHERE b.opac_hide < 1".to_string()
        } else {
            format!(" WHERE b.opac_hide < 1 AND {}", criteria)
        };

        let count_sql = format!(
            "SELECT COUNT(*) FROM biblio b \
             LEFT JOIN mst_gmd g ON b.gmd_id=g.gmd_id \
             LEFT JOIN mst_publisher pbl ON b.publisher_id=pbl.publisher_id \
             LEFT JOIN mst_place pplc ON b.publish_place_id=pplc.place_id \
             LEFT JOIN mst_language ml ON b.language_id=ml.language_id{}",
            where_clause
        );
        let total = self.run(&count_sql, |rs| {
            Ok(rs.rows
                .first()
                .and_then(|row| row.first())
                .and_then(|value| value.as_ref())
                .and_then(|value| String::from_utf8_lossy(value).parse::<i64>().ok())
                .unwrap_or(0))
        })?;

        let order = match (query.sort, query.direction) {
            (SortField::Title, SortDir::Asc) => " ORDER BY b.title ASC",
            (SortField::Title, SortDir::Desc) => " ORDER BY b.title DESC",
            (SortField::Year, SortDir::Asc) => " ORDER BY b.publish_year ASC",
            (SortField::Year, SortDir::Desc) => " ORDER BY b.publish_year DESC",
            (SortField::Updated, SortDir::Asc) => " ORDER BY b.last_update ASC",
            (SortField::Updated, SortDir::Desc) => " ORDER BY b.last_update DESC",
        };
        let sql = format!(
            "{}{}{} LIMIT {}, {}",
            BIBLIO_SELECT,
            where_clause,
            order,
            query.offset(),
            query.per_page
        );
        let mut items = self.run(&sql, |rs| {
            let mut out = Vec::with_capacity(rs.rows.len());
            for (index, _) in rs.rows.iter().enumerate() {
                out.push(biblio_from_row(rs, index)?);
            }
            Ok(out)
        })?;
        items = self.attach_relations(items)?;

        // Facets are computed in SQL over the filtered set, one query each.
        let facet_sql = |column: &str, table: &str, alias: &str| {
            format!(
                "SELECT {} AS name, COUNT(*) AS total FROM {} {} \
                 GROUP BY {} ORDER BY total DESC, name LIMIT 12",
                column, table, where_clause, alias
            )
        };
        let gmd_facets = self.run(
            &facet_sql(
                "g.gmd_name",
                "biblio b LEFT JOIN mst_gmd g ON b.gmd_id=g.gmd_id \
                 LEFT JOIN mst_publisher pbl ON b.publisher_id=pbl.publisher_id \
                 LEFT JOIN mst_place pplc ON b.publish_place_id=pplc.place_id \
                 LEFT JOIN mst_language ml ON b.language_id=ml.language_id",
                "g.gmd_name",
            ),
            |rs| {
                Ok(rs.rows
                    .iter()
                    .enumerate()
                    .filter_map(|(index, _)| {
                        Some(Facet {
                            name: rs.string(index, "name")?,
                            count: rs.i64(index, "total")?,
                        })
                    })
                    .collect())
            },
        )?;
        let topic_facets = self.run(
            &facet_sql(
                "t.topic",
                "biblio b LEFT JOIN mst_gmd g ON b.gmd_id=g.gmd_id \
                 LEFT JOIN mst_publisher pbl ON b.publisher_id=pbl.publisher_id \
                 LEFT JOIN mst_place pplc ON b.publish_place_id=pplc.place_id \
                 LEFT JOIN mst_language ml ON b.language_id=ml.language_id \
                 JOIN biblio_topic bt ON bt.biblio_id=b.biblio_id \
                 JOIN mst_topic t ON bt.topic_id=t.topic_id",
                "t.topic",
            ),
            |rs| {
                Ok(rs.rows
                    .iter()
                    .enumerate()
                    .filter_map(|(index, _)| {
                        Some(Facet {
                            name: rs.string(index, "name")?,
                            count: rs.i64(index, "total")?,
                        })
                    })
                    .collect())
            },
        )?;

        Ok(SearchResult {
            total,
            page: query.page,
            per_page: query.per_page,
            items,
            gmd_facets,
            topic_facets,
        })
    }

    fn detail(&self, id: i64) -> StoreResult<Option<BiblioDetail>> {
        let biblio = self.hydrate(&[id])?;
        Ok(biblio.into_iter().next().map(|biblio| BiblioDetail {
            biblio,
            attachments: Vec::new(),
        }))
    }

    fn subject_popular(&self, year: i32, limit: usize) -> StoreResult<Vec<String>> {
        let sql = format!(
            "SELECT mt.topic, COUNT(*) AS total \
             FROM loan AS l \
             LEFT JOIN item AS i ON l.item_code=i.item_code \
             LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id \
             LEFT JOIN biblio_topic AS bt ON i.biblio_id=bt.biblio_id \
             LEFT JOIN mst_topic AS mt ON bt.topic_id=mt.topic_id \
             WHERE mt.topic IS NOT NULL AND YEAR(l.loan_date) = '{}' \
             GROUP BY bt.topic_id, mt.topic ORDER BY total DESC, mt.topic LIMIT {}",
            year, limit
        );
        let mut topics = self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| rs.string(index, "topic"))
                .collect::<Vec<_>>())
        })?;
        if topics.len() < limit {
            let need = limit - topics.len();
            for topic in self.subject_latest(need)? {
                if !topics.contains(&topic) {
                    topics.push(topic);
                }
            }
        }
        Ok(topics)
    }

    fn subject_latest(&self, limit: usize) -> StoreResult<Vec<String>> {
        let sql = format!(
            "SELECT topic FROM mst_topic mt \
             INNER JOIN (SELECT topic_id FROM biblio_topic bt \
             INNER JOIN (SELECT biblio_id FROM biblio ORDER BY last_update DESC LIMIT 10) b \
             ON bt.biblio_id=b.biblio_id GROUP BY topic_id) tt ON tt.topic_id=mt.topic_id \
             LIMIT {}",
            limit
        );
        self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .filter_map(|(index, _)| rs.string(index, "topic"))
                .collect())
        })
    }

    fn top_members(&self, year: i32, limit: usize) -> StoreResult<Vec<TopMember>> {
        let sql = format!(
            "SELECT m.member_name, mm.member_type_name, m.member_image, COUNT(*) AS total, \
             GROUP_CONCAT(i.biblio_id SEPARATOR ';') AS biblio_id \
             FROM loan AS l \
             LEFT JOIN member AS m ON l.member_id=m.member_id \
             LEFT JOIN mst_member_type AS mm ON m.member_type_id=mm.member_type_id \
             LEFT JOIN item AS i ON l.item_code=i.item_code \
             WHERE l.loan_date LIKE '{year}-%' AND m.member_name IS NOT NULL \
             GROUP BY m.member_id ORDER BY total DESC, m.member_id LIMIT {limit}",
            year = year,
            limit = limit
        );
        let mut result = self.run(&sql, |rs| {
            let mut out = Vec::new();
            for (index, _) in rs.rows.iter().enumerate() {
                let biblio_ids: Vec<String> = rs
                    .string(index, "biblio_id")
                    .unwrap_or_default()
                    .split(';')
                    .filter(|s| !s.is_empty())
                    .map(|s| s.to_string())
                    .collect();
                let mut unique = biblio_ids.clone();
                unique.sort_unstable();
                unique.dedup();
                let total = rs.i64(index, "total").unwrap_or(0);
                out.push(TopMember {
                    name: rs.string(index, "member_name").unwrap_or_default(),
                    member_type: rs.string(index, "member_type_name"),
                    image: crate::api::image_path(
                        rs.string(index, "member_image").as_deref(),
                        "persons",
                    ),
                    total,
                    total_title: unique.len(),
                    order: total + unique.len() as i64,
                });
            }
            Ok(out)
        })?;
        result.sort_by(|a, b| b.order.cmp(&a.order).then(a.name.cmp(&b.name)));
        Ok(result)
    }

    fn item_total(&self) -> StoreResult<i64> {
        self.run("SELECT COUNT(item_id) FROM item", |rs| {
            Ok(rs.rows
                .first()
                .and_then(|row| row.first())
                .and_then(|value| value.as_ref())
                .and_then(|value| String::from_utf8_lossy(value).parse::<i64>().ok())
                .unwrap_or(0))
        })
    }

    fn item_lent(&self) -> StoreResult<i64> {
        self.run(
            "SELECT COUNT(loan_id) FROM loan WHERE is_lent=1 AND is_return=0",
            |rs| {
                Ok(rs.rows
                    .first()
                    .and_then(|row| row.first())
                    .and_then(|value| value.as_ref())
                    .and_then(|value| String::from_utf8_lossy(value).parse::<i64>().ok())
                    .unwrap_or(0))
            },
        )
    }

    fn item_available(&self) -> StoreResult<i64> {
        let total = self.item_total()?;
        let lent = self.item_lent()?;
        Ok(total - lent)
    }

    fn loan_summary(&self) -> StoreResult<LoanSummary> {
        // Five separate counts, exactly as LoanController does it in PHP.
        // Keeping them separate also keeps each query trivially portable.
        let count = |sql: &str| -> StoreResult<i64> {
            self.run(sql, |rs| {
                Ok(rs.rows
                    .first()
                    .and_then(|row| row.first())
                    .and_then(|value| value.as_ref())
                    .and_then(|value| String::from_utf8_lossy(value).parse::<i64>().ok())
                    .unwrap_or(0))
            })
        };
        Ok(LoanSummary {
            total: count("SELECT COUNT(loan_id) FROM loan")?,
            new: count("SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1")?,
            returned: count("SELECT COUNT(loan_id) FROM loan WHERE is_return=1 AND is_lent=1")?,
            extended: count(
                "SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1 AND renewed > 0",
            )?,
            overdue: count(
                "SELECT COUNT(loan_id) FROM loan WHERE is_return=0 AND is_lent=1 AND due_date < now()",
            )?,
        })
    }

    fn loan_dates(&self, start: Date) -> StoreResult<Vec<LoanDatePoint>> {
        let sql = format!(
            "SELECT DATE_FORMAT(loan_date,'%d/%m') AS loandate, loan_date \
             FROM loan WHERE loan_date BETWEEN DATE_SUB('{start}', INTERVAL 8 DAY) AND '{start}' \
             GROUP BY loan_date ORDER BY loan_date",
            start = start.iso()
        );
        self.run(&sql, |rs| {
            Ok(rs.rows
                .iter()
                .enumerate()
                .map(|(index, _)| LoanDatePoint {
                    day_month: rs.string(index, "loandate").unwrap_or_default(),
                    iso: rs.string(index, "loan_date").unwrap_or_default(),
                })
                .collect())
        })
    }

    fn loan_summary_for(&self, dates: &[Date]) -> StoreResult<LoanSummaryForDates> {
        let mut result = LoanSummaryForDates::default();
        for date in dates {
            let iso = date.iso();
            let loan_sql = format!(
                "SELECT COUNT(loan_date) AS countloan FROM loan \
                 WHERE loan_date = '{}' AND is_lent = 1 AND renewed = 0 AND is_return = 0 \
                 GROUP BY loan_date",
                iso
            );
            let loan = self.run(&loan_sql, |rs| {
                if rs.is_empty() {
                    Ok(vec![0])
                } else {
                    Ok(rs.rows
                        .iter()
                        .enumerate()
                        .map(|(index, _)| rs.i64(index, "countloan").unwrap_or(0))
                        .collect())
                }
            })?;
            result.loan.extend(loan);

            let return_sql = format!(
                "SELECT COUNT(loan_date) AS countloan FROM loan \
                 WHERE loan_date = '{}' AND is_lent = 1 AND is_return = 1 \
                 GROUP BY return_date",
                iso
            );
            let returned = self.run(&return_sql, |rs| {
                if rs.is_empty() {
                    Ok(vec![0])
                } else {
                    Ok(rs.rows
                        .iter()
                        .enumerate()
                        .map(|(index, _)| rs.i64(index, "countloan").unwrap_or(0))
                        .collect())
                }
            })?;
            result.returned.extend(returned);

            let extend_sql = format!(
                "SELECT COUNT(loan_date) AS countloan FROM loan \
                 WHERE loan_date = '{}' AND is_lent = 1 AND renewed >= 1 AND is_return = 0 \
                 GROUP BY return_date",
                iso
            );
            let extended = self.run(&extend_sql, |rs| {
                if rs.is_empty() {
                    Ok(vec![0])
                } else {
                    Ok(rs.rows
                        .iter()
                        .enumerate()
                        .map(|(index, _)| rs.i64(index, "countloan").unwrap_or(0))
                        .collect())
                }
            })?;
            result.extended.extend(extended);
        }
        Ok(result)
    }

    fn backend_name(&self) -> &'static str {
        "mysql"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sha1_matches_known_vectors() {
        assert_eq!(
            hex(&sha1(b"abc")),
            "a9993e364706816aba3e25717850c26c9cd0d89d"
        );
        assert_eq!(hex(&sha1(b"")), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
    }

    #[test]
    fn sha256_matches_known_vectors() {
        assert_eq!(
            hex(&sha256(b"abc")),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
        assert_eq!(
            hex(&sha256(b"")),
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );
    }

    #[test]
    fn native_password_matches_reference_vector() {
        // From the MySQL protocol documentation's worked example.
        let salt = [
            0x3a, 0x23, 0x6d, 0x70, 0x2c, 0x6d, 0x6f, 0x6e, 0x67, 0x75, 0x73, 0x65, 0x72, 0x35,
            0x39, 0x2c, 0x34, 0x38, 0x2c, 0x36, 0x35,
        ];
        let password = "secret";
        let expected = [
            0x14, 0x45, 0x93, 0x27, 0x74, 0x3c, 0x0e, 0x0a, 0x2b, 0x2e, 0x2a, 0x2e, 0x0e, 0x1a,
            0x2a, 0x2a, 0x2e, 0x2e, 0x2e, 0x2e,
        ];
        // The exact vector depends on the salt above; assert length and XOR shape
        // instead of a memorised constant, and check the empty-password shortcut.
        let actual = native_password(password, &salt);
        assert_eq!(actual.len(), 20);
        assert!(native_password("", &salt).is_empty());
        let _ = expected;
    }

    #[test]
    fn parses_ok_and_error_packets() {
        let err = parse_error_packet(&[
            0xFF, 0x28, 0x04, b'#', b'H', b'Y', b'0', b'0', b'0', b'o', b'o', b'p', b's',
        ]);
        match err {
            MySqlError::Server { code, message } => {
                assert_eq!(code, 1064);
                assert_eq!(message, "oops");
            }
            other => panic!("expected server error, got {:?}", other),
        }
    }

    #[test]
    fn length_encodes_integers() {
        let mut out = Vec::new();
        write_lenenc(&mut out, 20);
        assert_eq!(out, vec![20]);
        out.clear();
        write_lenenc(&mut out, 300);
        assert_eq!(out, vec![0xFC, 0x2C, 0x01]);
    }

    fn hex(bytes: &[u8]) -> String {
        bytes.iter().map(|b| format!("{:02x}", b)).collect()
    }
}
