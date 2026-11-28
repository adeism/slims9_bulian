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
    /// A write may have committed before the database connection failed.
    WriteUnknown,
}

impl std::fmt::Display for StoreError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            StoreError::Db(message) => write!(f, "database error: {}", message),
            StoreError::NotFound => write!(f, "not found"),
            StoreError::WriteUnknown => write!(f, "write outcome unknown"),
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

    /// Single compare-and-set: never retry after a possible write.
    fn member_change_password(&self, _member_id: &str, _old_hash: &str, _new_hash: &str) -> StoreResult<bool> {
        Err(StoreError::Db("member writes disabled".into()))
    }

    /// Test-only login timestamp write, conditional on the verified credential.
    fn member_record_login(&self, _member_id: &str, _expected_hash: &str) -> StoreResult<bool> {
        Err(StoreError::Db("member login writes disabled".into()))
    }

    /// Upgrade a legacy hash using the same compare-and-set semantics.
    fn member_upgrade_password(&self, member_id: &str, old_hash: &str, new_hash: &str) -> StoreResult<bool> {
        self.member_change_password(member_id, old_hash, new_hash)
    }

    /// Operations use only the authenticated member ID supplied by the caller.
    fn member_bookmark_add(&self, _member_id: &str, _biblio_id: i64) -> StoreResult<()> {
        Err(StoreError::Db("member writes disabled".into()))
    }
    fn member_bookmark_remove(&self, _member_id: &str, _biblio_id: i64) -> StoreResult<()> {
        Err(StoreError::Db("member writes disabled".into()))
    }

    /// HTTP bookmark mutation: check the session credential version under
    /// the mutation lock. False means stale/missing credential; NotFound means
    /// an added title is no longer visible. Removal permits orphaned titles.
    /// Unsupported stores must not fall back to unbound helpers.
    fn member_bookmark_write_bound(&self, _member_id: &str, _biblio_id: i64, _credential_version: &str, _add: bool) -> StoreResult<bool> {
        Err(StoreError::Db("credential-bound bookmark writes disabled".into()))
    }

    /// Private profile; callers must authorize the supplied member ID.
    fn member_profile(&self, member_id: &str) -> StoreResult<Option<MemberProfile>>;

    /// Up to 21 rows (20 plus a next-page sentinel), newest first. Only
    /// callers with an authenticated member ID may use this private lookup.
    fn member_bookmarks(&self, member_id: &str, offset: usize) -> StoreResult<Vec<MemberBookmark>>;

    /// Return up to 21 rows for exactly this authenticated member. The extra
    /// row is a next-page sentinel; callers must enforce authorization.
    fn member_loans_page(&self, member_id: &str, returned: bool, offset: usize) -> StoreResult<Vec<MemberLoan>>;
    fn member_loans(&self, member_id: &str, returned: bool) -> StoreResult<Vec<MemberLoan>> {
        let mut rows = self.member_loans_page(member_id, returned, 0)?;
        rows.truncate(20);
        Ok(rows)
    }

    /// One bounded read for both active and returned loans, current first.
    /// Return at most MAX_MEMBER_REPORT_LOANS + 1 rows; never silently truncate
    /// a report in the HTTP layer. Callers authenticate the exact member ID.
    fn member_loan_report(&self, _member_id: &str) -> StoreResult<Vec<MemberLoanReportRow>> {
        Err(StoreError::Db("member loan reports unavailable".into()))
    }

    /// Apply scope BEFORE the 1001-row sentinel limit, not after fetching a
    /// capped combined report. Unsupported backends must fail closed.
    fn member_loan_report_scoped(&self, member_id: &str, scope: MemberLoanReportScope) -> StoreResult<Vec<MemberLoanReportRow>> {
        if scope == MemberLoanReportScope::All { self.member_loan_report(member_id) }
        else { Err(StoreError::Db("scoped member loan reports unavailable".into())) }
    }

    /// Latest 100 existing reservations for a member, no writes enabled.
    /// Callers must authenticate; orphaned catalog references are omitted.
    fn member_reservations(&self, _member_id: &str) -> StoreResult<Vec<DemoReservation>> {
        Err(StoreError::Db("reservation reads unavailable".into()))
    }

    /// Experimental exclusive-writer reservation flow, for disposable tests.
    fn test_reserve(&self, _member_id: &str, _biblio_id: i64) -> StoreResult<DemoReserveResult> {
        Err(StoreError::Db("test reservations disabled".into()))
    }
    /// HTTP reservation writes must recheck the session's SHA-256 credential
    /// version under the same database lock as policy checks and insertion.
    /// Never fall back to the unbound administrative/test helper.
    fn test_reserve_bound(&self, _member_id: &str, _biblio_id: i64, _credential_version: &str) -> StoreResult<DemoReserveResult> {
        Err(StoreError::Db("credential-bound test reservations disabled".into()))
    }
    fn test_reservations(&self, _member_id: &str) -> StoreResult<Vec<DemoReservation>> {
        Err(StoreError::Db("test reservations disabled".into()))
    }

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
