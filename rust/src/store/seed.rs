//! Loader for the SQL dumps shipped with SLiMS (`install/senayan.sql` and
//! `install/sampledata.sql`).
//!
//! The demo store reads the same data the installer would load, so the Rust OPAC
//! starts with the fifteen real sample records instead of a hand-written fixture.
//! Only `INSERT` statements are interpreted; `CREATE TABLE` is ignored because the
//! Rust side already knows the columns it needs.

use std::collections::HashMap;
use std::path::Path;

#[derive(Clone, Debug, PartialEq)]
pub enum SqlValue {
    Null,
    Int(i64),
    Float(f64),
    Text(String),
}

impl SqlValue {
    pub fn as_str(&self) -> Option<&str> {
        match self {
            SqlValue::Text(s) => Some(s.as_str()),
            _ => None,
        }
    }

    pub fn as_i64(&self) -> Option<i64> {
        match self {
            SqlValue::Int(i) => Some(*i),
            SqlValue::Float(f) => Some(*f as i64),
            SqlValue::Text(s) => s.trim().parse::<i64>().ok(),
            _ => None,
        }
    }

    pub fn string(&self) -> Option<String> {
        match self {
            SqlValue::Text(s) => Some(s.clone()),
            SqlValue::Int(i) => Some(i.to_string()),
            SqlValue::Float(f) => Some(f.to_string()),
            SqlValue::Null => None,
        }
    }
}

#[derive(Clone, Debug, Default)]
pub struct Table {
    pub columns: Vec<String>,
    pub rows: Vec<Vec<SqlValue>>,
}

impl Table {
    pub fn get<'a>(&self, row: &'a [SqlValue], column: &str) -> Option<&'a SqlValue> {
        let index = self.columns.iter().position(|c| c == column)?;
        row.get(index)
    }
}

#[derive(Clone, Debug, Default)]
pub struct SeedData {
    pub tables: HashMap<String, Table>,
}

impl SeedData {
    pub fn table(&self, name: &str) -> Option<&Table> {
        self.tables.get(name)
    }

    pub fn rows(&self, name: &str) -> &[Vec<SqlValue>] {
        self.tables
            .get(name)
            .map(|t| t.rows.as_slice())
            .unwrap_or(&[])
    }

    pub fn column<'a>(&'a self, table: &str, row: &'a [SqlValue], column: &str) -> Option<&'a SqlValue> {
        self.tables.get(table)?.get(row, column)
    }
}

/// Load every SQL file that exists under `root/install`.
pub fn load(root: &Path) -> SeedData {
    let mut data = SeedData::default();
    for relative in ["install/senayan.sql", "install/sampledata.sql"] {
        let path = root.join(relative);
        if let Ok(text) = std::fs::read_to_string(&path) {
            parse_into(&text, &mut data);
        }
    }
    data
}

/// Parse `INSERT [IGNORE] INTO \`table\` (cols) VALUES (...),(...);` statements.
pub fn parse_into(sql: &str, data: &mut SeedData) {
    let bytes = sql.as_bytes();
    let len = bytes.len();
    let mut i = 0;
    while i < len {
        if starts_with_ignore_case(bytes, i, b"insert") {
            let mut p = i + 6;
            p = skip_ws(bytes, p);
            if starts_with_ignore_case(bytes, p, b"ignore") {
                p += 6;
                p = skip_ws(bytes, p);
            }
            if starts_with_ignore_case(bytes, p, b"into") {
                p += 4;
                p = skip_ws(bytes, p);
                let (table, next) = match read_identifier(bytes, p) {
                    Some(v) => v,
                    None => {
                        i += 6;
                        continue;
                    }
                };
                let mut p = skip_ws(bytes, next);
                // optional column list
                let mut columns = Vec::new();
                if bytes.get(p) == Some(&b'(') {
                    match read_column_list(bytes, p) {
                        Some((cols, next)) => {
                            columns = cols;
                            p = skip_ws(bytes, next);
                        }
                        None => {
                            i += 6;
                            continue;
                        }
                    }
                }
                if !starts_with_ignore_case(bytes, p, b"values") {
                    i += 6;
                    continue;
                }
                p = skip_ws(bytes, p + 6);
                let entry = data.tables.entry(table.clone()).or_insert_with(|| Table {
                    columns: columns.clone(),
                    rows: Vec::new(),
                });
                if entry.columns.is_empty() {
                    entry.columns = columns;
                }
                let column_count = entry.columns.len();
                loop {
                    p = skip_ws(bytes, p);
                    if bytes.get(p) != Some(&b'(') {
                        break;
                    }
                    p += 1;
                    let mut row = Vec::with_capacity(column_count);
                    loop {
                        p = skip_ws(bytes, p);
                        match read_value(bytes, p) {
                            Some((value, next)) => {
                                row.push(value);
                                p = skip_ws(bytes, next);
                            }
                            None => break,
                        }
                        match bytes.get(p) {
                            Some(b',') => p += 1,
                            Some(b')') => {
                                p += 1;
                                break;
                            }
                            _ => break,
                        }
                    }
                    if !row.is_empty() {
                        // Pad or trim to the declared column count so a malformed
                        // row cannot shift every later column.
                        row.resize(column_count, SqlValue::Null);
                        entry.rows.push(row);
                    }
                    p = skip_ws(bytes, p);
                    if bytes.get(p) == Some(&b',') {
                        p += 1;
                        continue;
                    }
                    break;
                }
                // advance to the end of the statement
                while p < len && bytes[p] != b';' {
                    p += 1;
                }
                i = p + 1;
                continue;
            }
        }
        i += 1;
    }
}

fn starts_with_ignore_case(haystack: &[u8], offset: usize, needle: &[u8]) -> bool {
    if offset + needle.len() > haystack.len() {
        return false;
    }
    haystack[offset..offset + needle.len()]
        .iter()
        .zip(needle)
        .all(|(a, b)| a.to_ascii_lowercase() == *b)
}

fn skip_ws(bytes: &[u8], mut i: usize) -> usize {
    while matches!(bytes.get(i), Some(b' ' | b'\t' | b'\n' | b'\r')) {
        i += 1;
    }
    i
}

fn read_identifier(bytes: &[u8], mut i: usize) -> Option<(String, usize)> {
    if bytes.get(i) == Some(&b'`') {
        i += 1;
        let start = i;
        while i < bytes.len() && bytes[i] != b'`' {
            i += 1;
        }
        let name = String::from_utf8_lossy(&bytes[start..i]).to_string();
        return Some((name, i + 1));
    }
    let start = i;
    while i < bytes.len() && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_') {
        i += 1;
    }
    if i == start {
        None
    } else {
        Some((String::from_utf8_lossy(&bytes[start..i]).to_string(), i))
    }
}

fn read_column_list(bytes: &[u8], mut i: usize) -> Option<(Vec<String>, usize)> {
    i += 1; // '('
    let mut columns = Vec::new();
    loop {
        i = skip_ws(bytes, i);
        let (name, next) = read_identifier(bytes, i)?;
        columns.push(name);
        i = skip_ws(bytes, next);
        match bytes.get(i) {
            Some(b',') => i += 1,
            Some(b')') => return Some((columns, i + 1)),
            _ => return None,
        }
    }
}

fn read_value(bytes: &[u8], mut i: usize) -> Option<(SqlValue, usize)> {
    match bytes.get(i)? {
        b'\'' => {
            i += 1;
            let mut out = String::new();
            loop {
                let b = *bytes.get(i)?;
                match b {
                    b'\\' => {
                        i += 1;
                        let esc = *bytes.get(i)?;
                        i += 1;
                        match esc {
                            b'n' => out.push('\n'),
                            b'r' => out.push('\r'),
                            b't' => out.push('\t'),
                            b'0' => out.push('\0'),
                            b'Z' => out.push('\u{1a}'),
                            b'\\' => out.push('\\'),
                            b'\'' => out.push('\''),
                            b'"' => out.push('"'),
                            other => out.push(other as char),
                        }
                    }
                    b'\'' => {
                        // '' inside a string is an escaped quote
                        if bytes.get(i + 1) == Some(&b'\'') {
                            out.push('\'');
                            i += 2;
                        } else {
                            i += 1;
                            return Some((SqlValue::Text(out), i));
                        }
                    }
                    _ => {
                        let start = i;
                        let len = utf8_len(b);
                        i += len;
                        out.push_str(
                            std::str::from_utf8(&bytes[start..i])
                                .map_err(|_| "invalid utf-8")
                                .ok()?,
                        );
                    }
                }
            }
        }
        b'N' | b'n' => {
            if starts_with_ignore_case(bytes, i, b"null") {
                Some((SqlValue::Null, i + 4))
            } else if starts_with_ignore_case(bytes, i, b"now()") {
                Some((SqlValue::Text(crate::date::Date::today().iso()), i + 5))
            } else {
                None
            }
        }
        b'-' | b'0'..=b'9' => {
            let start = i;
            if bytes[i] == b'-' {
                i += 1;
            }
            let mut is_float = false;
            while matches!(bytes.get(i), Some(b'0'..=b'9')) {
                i += 1;
            }
            if bytes.get(i) == Some(&b'.') {
                is_float = true;
                i += 1;
                while matches!(bytes.get(i), Some(b'0'..=b'9')) {
                    i += 1;
                }
            }
            let text = std::str::from_utf8(&bytes[start..i]).ok()?;
            if is_float {
                Some((SqlValue::Float(text.parse().ok()?), i))
            } else {
                Some((SqlValue::Int(text.parse().ok()?), i))
            }
        }
        _ => {
            // Functions such as DATE(NOW()) or CURDATE(): consume balanced
            // parentheses and store today's date.
            let start = i;
            let mut depth = 0usize;
            while i < bytes.len() {
                match bytes[i] {
                    b'(' => depth += 1,
                    b')' => {
                        depth = depth.saturating_sub(1);
                        if depth == 0 {
                            i += 1;
                            break;
                        }
                    }
                    b',' if depth == 0 => break,
                    _ => {}
                }
                i += 1;
            }
            let text = String::from_utf8_lossy(&bytes[start..i]).to_string();
            if text.is_empty() {
                None
            } else if text.eq_ignore_ascii_case("DATE(NOW())")
                || text.eq_ignore_ascii_case("CURRENT_DATE")
                || text.eq_ignore_ascii_case("CURDATE()")
            {
                Some((SqlValue::Text(crate::date::Date::today().iso()), i))
            } else if text.eq_ignore_ascii_case("CURRENT_TIMESTAMP") || text.eq_ignore_ascii_case("NOW()") {
                Some((SqlValue::Text(crate::date::Date::today().iso()), i))
            } else {
                Some((SqlValue::Text(text), i))
            }
        }
    }
}

fn utf8_len(first: u8) -> usize {
    match first {
        0x00..=0x7F => 1,
        0xC0..=0xDF => 2,
        0xE0..=0xEF => 3,
        _ => 4,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_multi_row_insert() {
        let mut data = SeedData::default();
        parse_into(
            "INSERT IGNORE INTO `biblio` (`biblio_id`, `title`, `opac_hide`) VALUES\n\
             (1, 'PHP 5 for dummies', 0),\n\
             (2, 'It''s a test', 1);",
            &mut data,
        );
        let table = data.table("biblio").unwrap();
        assert_eq!(table.rows.len(), 2);
        assert_eq!(table.get(&table.rows[1], "title").unwrap().as_str(), Some("It's a test"));
    }

    #[test]
    fn loads_the_shipped_sample_data() {
        let root = crate::config::find_root(std::path::Path::new(env!("CARGO_MANIFEST_DIR")));
        let data = load(&root);
        assert!(data.table("biblio").map(|t| t.rows.len()).unwrap_or(0) >= 15);
        assert!(data.table("mst_gmd").map(|t| t.rows.len()).unwrap_or(0) >= 31);
        assert!(data.table("mst_coll_type").map(|t| t.rows.len()).unwrap_or(0) >= 3);
    }
}
