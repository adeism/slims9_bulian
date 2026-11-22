//! Domain types shared by the stores, the REST API and the OPAC.
//!
//! The names follow the SLiMS database columns (`biblio`, `item`, `loan`,
//! `member`, `mst_*`), so a query in `api/v1/controllers/*.php` can be read
//! next to the Rust that replaces it.

use crate::date::Date;

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Author {
    pub name: String,
    /// SLiMS `biblio_author.level`: 1 = main author, 2 = additional.
    pub level: i64,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct ItemCopy {
    pub item_code: String,
    pub call_number: Option<String>,
    pub location: Option<String>,
    pub status: Option<String>,
    pub site: Option<String>,
    pub coll_type: Option<String>,
    /// True when this copy is currently on loan (`is_lent = 1 AND is_return = 0`).
    pub on_loan: bool,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Biblio {
    pub id: i64,
    pub title: String,
    pub image: Option<String>,
    pub gmd_id: Option<i64>,
    pub gmd_name: Option<String>,
    pub publisher: Option<String>,
    pub publish_place: Option<String>,
    pub publish_year: Option<String>,
    pub isbn: Option<String>,
    pub call_number: Option<String>,
    pub language_id: String,
    pub language_name: Option<String>,
    pub classification: Option<String>,
    pub notes: Option<String>,
    pub series_title: Option<String>,
    pub edition: Option<String>,
    pub collation: Option<String>,
    pub sor: Option<String>,
    pub spec_detail_info: Option<String>,
    pub opac_hide: i64,
    pub promoted: i64,
    pub last_update: Option<String>,
    pub authors: Vec<Author>,
    pub topics: Vec<String>,
    pub coll_types: Vec<String>,
    pub items: Vec<ItemCopy>,
}

impl Biblio {
    pub fn item_count(&self) -> i64 {
        self.items.len() as i64
    }

    pub fn available_count(&self) -> i64 {
        self.items.iter().filter(|item| !item.on_loan).count() as i64
    }

    pub fn author_names(&self) -> Vec<String> {
        self.authors.iter().map(|a| a.name.clone()).collect()
    }

    /// `Author One; Author Two`, the shape SLiMS prints in result rows.
    pub fn author_line(&self) -> String {
        self.author_names().join("; ")
    }

    pub fn topic_line(&self) -> String {
        self.topics.join("; ")
    }
}

/// One row of `BiblioController::getPopular`.
///
/// `total` is `None` for the rows PHP appends from `biblio` when there are not
/// enough loaned titles; those rows only carry `biblio_id`, `title` and `image`.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct PopularBiblio {
    pub biblio: Biblio,
    pub total: Option<i64>,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct BiblioDetail {
    pub biblio: Biblio,
    pub attachments: Vec<String>,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Loan {
    pub id: i64,
    pub item_code: String,
    pub member_id: String,
    pub loan_date: Date,
    pub due_date: Date,
    pub renewed: i64,
    pub is_lent: bool,
    pub is_return: bool,
    pub return_date: Option<Date>,
}

/// Internal credential record. Never serialize or log its password hash.
#[derive(Clone)]
pub struct MemberCredential {
    pub id: String,
    pub name: String,
    pub password_hash: String,
}

/// Read-only member information shown only after session authorization.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct MemberProfile {
    pub id: String,
    pub name: String,
    pub email: Option<String>,
    pub member_type: Option<String>,
    pub register_date: Option<String>,
    pub expiry_date: Option<String>,
    pub institution: Option<String>,
    pub pending: bool,
}

/// A reservation in the disposable in-memory demo (never a database write).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct DemoReservation {
    pub member_id: String,
    pub biblio_id: i64,
    pub title: String,
    pub item_code: String,
    pub reserve_date: String,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum DemoReserveResult {
    Created,
    AlreadyReserved,
    LimitReached,
    NotAllowed,
    Unavailable,
}

/// The member area's loan tables. Dates and titles may be NULL in SLiMS.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct MemberLoan {
    pub item_code: String,
    pub title: Option<String>,
    pub loan_date: Option<String>,
    pub due_date: Option<String>,
    pub return_date: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct Member {
    pub id: String,
    pub name: String,
    pub member_type_id: Option<i64>,
    pub member_type_name: Option<String>,
    pub image: Option<String>,
    pub email: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq)]
pub struct TopMember {
    pub name: String,
    pub member_type: Option<String>,
    pub image: String,
    pub total: i64,
    pub total_title: usize,
    pub order: i64,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct LoanSummary {
    pub total: i64,
    pub new: i64,
    pub returned: i64,
    pub extended: i64,
    pub overdue: i64,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct LoanDatePoint {
    /// `DD/MM`, as `DATE_FORMAT(loan_date,'%d/%m')`.
    pub day_month: String,
    /// `YYYY-MM-DD`.
    pub iso: String,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct LoanSummaryForDates {
    pub loan: Vec<i64>,
    pub returned: Vec<i64>,
    pub extended: Vec<i64>,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum SortField {
    #[default]
    Updated,
    Title,
    Year,
}

impl SortField {
    pub fn parse(value: Option<&str>) -> SortField {
        match value.unwrap_or("").to_ascii_lowercase().as_str() {
            "title" => SortField::Title,
            "year" => SortField::Year,
            _ => SortField::Updated,
        }
    }

    pub fn as_str(&self) -> &'static str {
        match self {
            SortField::Updated => "updated",
            SortField::Title => "title",
            SortField::Year => "year",
        }
    }
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum SortDir {
    #[default]
    Desc,
    Asc,
}

impl SortDir {
    pub fn parse(value: Option<&str>) -> SortDir {
        match value.unwrap_or("").to_ascii_lowercase().as_str() {
            "asc" => SortDir::Asc,
            _ => SortDir::Desc,
        }
    }

    pub fn as_str(&self) -> &'static str {
        match self {
            SortDir::Desc => "desc",
            SortDir::Asc => "asc",
        }
    }
}

#[derive(Clone, Debug)]
pub struct SearchQuery {
    pub keywords: String,
    pub title: String,
    pub author: String,
    pub subject: String,
    pub isbn: String,
    pub publisher: String,
    pub year: String,
    pub gmd: String,
    pub coll_type: String,
    pub page: usize,
    pub per_page: usize,
    pub sort: SortField,
    pub direction: SortDir,
}

impl Default for SearchQuery {
    fn default() -> Self {
        SearchQuery {
            keywords: String::new(),
            title: String::new(),
            author: String::new(),
            subject: String::new(),
            isbn: String::new(),
            publisher: String::new(),
            year: String::new(),
            gmd: String::new(),
            coll_type: String::new(),
            page: 1,
            per_page: 10,
            sort: SortField::Updated,
            direction: SortDir::Desc,
        }
    }
}

impl SearchQuery {
    pub fn is_empty(&self) -> bool {
        self.keywords.trim().is_empty()
            && self.title.trim().is_empty()
            && self.author.trim().is_empty()
            && self.subject.trim().is_empty()
            && self.isbn.trim().is_empty()
            && self.publisher.trim().is_empty()
            && self.year.trim().is_empty()
            && self.gmd.trim().is_empty()
            && self.coll_type.trim().is_empty()
    }

    pub fn offset(&self) -> usize {
        self.per_page * self.page.saturating_sub(1)
    }
}

#[derive(Clone, Debug, Default)]
pub struct Facet {
    pub name: String,
    pub count: i64,
}

#[derive(Clone, Debug, Default)]
pub struct SearchResult {
    pub total: i64,
    pub page: usize,
    pub per_page: usize,
    pub items: Vec<Biblio>,
    pub gmd_facets: Vec<Facet>,
    pub topic_facets: Vec<Facet>,
}

impl SearchResult {
    pub fn total_pages(&self) -> usize {
        if self.per_page == 0 {
            1
        } else {
            ((self.total.max(0) as usize) + self.per_page - 1) / self.per_page
        }
    }
}
