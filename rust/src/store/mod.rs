//! The storage boundary.
//!
//! Everything above this module talks to a [`Store`], never to SQL directly.
//! That is what makes the rewrite checkable: the OPAC and API are written once,
//! the in-process store serves the demo and the tests, and the MySQL store
//! speaks to the existing SLiMS database without a schema migration.

pub mod memory;
pub mod mysql;
pub mod seed;

use crate::date::Date;
use crate::models::*;

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum StoreError {
    Db(String),
    NotFound,
}

impl std::fmt::Display for StoreError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            StoreError::Db(message) => write!(f, "database error: {}", message),
            StoreError::NotFound => write!(f, "not found"),
        }
    }
}

impl std::error::Error for StoreError {}

pub type StoreResult<T> = Result<T, StoreError>;

pub trait Store: Send + Sync {
    fn biblio_popular(&self, limit: usize) -> StoreResult<Vec<PopularBiblio>>;
    fn biblio_latest(&self, limit: usize) -> StoreResult<Vec<Biblio>>;
    fn biblio_total(&self) -> StoreResult<i64>;
    fn biblio_by_gmd(&self, gmd: &str, limit: usize) -> StoreResult<Vec<Biblio>>;
    fn biblio_by_coll_type(&self, coll_type: &str, limit: usize) -> StoreResult<Vec<Biblio>>;

    fn search(&self, query: &SearchQuery) -> StoreResult<SearchResult>;
    fn detail(&self, id: i64) -> StoreResult<Option<BiblioDetail>>;

    fn subject_popular(&self, year: i32, limit: usize) -> StoreResult<Vec<String>>;
    fn subject_latest(&self, limit: usize) -> StoreResult<Vec<String>>;
    fn top_members(&self, year: i32, limit: usize) -> StoreResult<Vec<TopMember>>;

    fn item_total(&self) -> StoreResult<i64>;
    fn item_lent(&self) -> StoreResult<i64>;
    fn item_available(&self) -> StoreResult<i64>;

    fn loan_summary(&self) -> StoreResult<LoanSummary>;
    fn loan_dates(&self, start: Date) -> StoreResult<Vec<LoanDatePoint>>;
    fn loan_summary_for(&self, dates: &[Date]) -> StoreResult<LoanSummaryForDates>;

    /// Internal lookup for native member authentication. Never expose the hash.
    fn member_credential(&self, member_id: &str) -> StoreResult<Option<MemberCredential>>;

    /// Private profile; callers must authorize the supplied member ID.
    fn member_profile(&self, member_id: &str) -> StoreResult<Option<MemberProfile>>;

    /// Return up to 20 rows for exactly this authenticated member. This is a
    /// data primitive only; callers must enforce authentication/authorization.
    fn member_loans(&self, member_id: &str, returned: bool) -> StoreResult<Vec<MemberLoan>>;

    /// Disposable demo-only reservations. Backends must fail closed by default.
    fn demo_reservations(&self, _member_id: &str) -> StoreResult<Vec<DemoReservation>> {
        Err(StoreError::Db("demo reservations disabled".into()))
    }
    fn demo_reserve(&self, _member_id: &str, _biblio_id: i64) -> StoreResult<DemoReserveResult> {
        Err(StoreError::Db("demo reservations disabled".into()))
    }

    /// Name shown in logs and on `/healthz`.
    fn backend_name(&self) -> &'static str;
}

/// Escape a value for a MySQL string literal. Used by the MySQL store's query
/// builders; the in-process store never builds SQL.
pub fn sql_escape(value: &str) -> String {
    let mut out = String::with_capacity(value.len() + 2);
    for ch in value.chars() {
        match ch {
            '\0' => out.push_str("\\0"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\u{1a}' => out.push_str("\\Z"),
            '\'' => out.push_str("\\'"),
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            c => out.push(c),
        }
    }
    out
}

/// Escape `%` and `_` inside a LIKE pattern so user input is literal.
pub fn like_escape(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for ch in value.chars() {
        match ch {
            '%' | '_' | '\\' => {
                out.push('\\');
                out.push(ch);
            }
            '\'' => out.push_str("''"),
            c => out.push(c),
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_quotes_and_backslashes() {
        assert_eq!(sql_escape("O'Reilly\\x"), "O\\'Reilly\\\\x");
    }

    #[test]
    fn escapes_like_wildcards() {
        assert_eq!(like_escape("50%_done"), "50\\%\\_done");
    }
}
