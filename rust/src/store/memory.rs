//! In-process store, seeded from the SQL dumps in `install/`.
//!
//! This is the demo backend and the test backend. It is not a toy: the queries
//! mirror the SQL in `api/v1/controllers/*.php` one for one, so an endpoint that
//! passes here has the same shape as the endpoint it replaces. The MySQL store
//! runs the same queries against the real schema.

use std::collections::{BTreeMap, HashMap};
use std::path::Path;
use std::sync::{Arc, Mutex};

use super::seed::{self, SeedData, SqlValue, Table};
use super::{Store, StoreResult};
use crate::date::Date;
use crate::models::*;

#[derive(Clone, Debug, Default)]
pub struct MemoryStore {
    biblio: Vec<Biblio>,
    loans: Vec<Loan>,
    members: Vec<Member>,
    member_profiles: HashMap<String, MemberProfile>,
    bookmarks: Arc<Mutex<Vec<(String, String, i64, String)>>>,
    member_passwords: Arc<Mutex<HashMap<String, String>>>,
    member_logins: Arc<Mutex<HashMap<String, String>>>,
    demo_reservations_enabled: bool,
    demo_reservations: Arc<Mutex<Vec<DemoReservation>>>,
    stored_reservations: Vec<(i64, DemoReservation)>,
    reserve_policy: HashMap<i64, (bool, usize)>,
    reservable_items: HashMap<i64, Vec<String>>,
    /// Topic names in `mst_topic` order (topic_id ascending). The SQL backend
    /// returns subjects in this order, so the demo backend must too.
    topic_order: Vec<String>,
}

impl MemoryStore {
    /// Load `install/*.sql` and, when the dump has no circulation data, add a
    /// small deterministic demo set so the API endpoints have something to say.
    pub fn load(root: &Path, demo_circulation: bool) -> MemoryStore {
        let data = seed::load(root);
        MemoryStore::from_seed(&data, demo_circulation)
    }

    pub fn from_seed(data: &SeedData, demo_circulation: bool) -> MemoryStore {
        let mut store = MemoryStore::default();
        store.build(data, demo_circulation);
        store
    }

    fn build(&mut self, data: &SeedData, demo_circulation: bool) {
        let gmd = string_map(data, "mst_gmd", "gmd_id", "gmd_name");
        let coll_type = string_map(data, "mst_coll_type", "coll_type_id", "coll_type_name");
        let publisher = string_map(data, "mst_publisher", "publisher_id", "publisher_name");
        let place = string_map(data, "mst_place", "place_id", "place_name");
        let language = string_map(data, "mst_language", "language_id", "language_name");
        let author = string_map(data, "mst_author", "author_id", "author_name");
        let topic = string_map(data, "mst_topic", "topic_id", "topic");
        let member_type = string_map(data, "mst_member_type", "member_type_id", "member_type_name");
        let item_status = string_map(data, "mst_item_status", "item_status_id", "item_status_name");
        let location = string_map(data, "mst_location", "location_id", "location_name");

        if let Some(table) = data.table("mst_member_type") {
            for row in &table.rows {
                if let Some(id) = cell_i64(table, row, "member_type_id") {
                    let allowed = cell_i64(table, row, "enable_reserve").unwrap_or(0) == 1;
                    let limit = cell_i64(table, row, "reserve_limit").unwrap_or(0).max(0) as usize;
                    self.reserve_policy.insert(id, (allowed, limit));
                }
            }
        }

        self.topic_order = data
            .table("mst_topic")
            .map(|table| {
                let mut rows: Vec<(i64, String)> = table
                    .rows
                    .iter()
                    .filter_map(|row| {
                        let id = table.get(row, "topic_id").and_then(SqlValue::as_i64)?;
                        let name = cell_string(table, row, "topic")?;
                        Some((id, name))
                    })
                    .collect();
                rows.sort_by_key(|(id, _)| *id);
                rows.into_iter().map(|(_, name)| name).collect()
            })
            .unwrap_or_default();

        // biblio rows
        let biblio_table = match data.table("biblio") {
            Some(table) => table.clone(),
            None => return,
        };
        for row in &biblio_table.rows {
            let id = biblio_table.get(row, "biblio_id").and_then(SqlValue::as_i64).unwrap_or(0);
            if id == 0 {
                continue;
            }
            let biblio = Biblio {
                id,
                title: cell_string(&biblio_table, row, "title").unwrap_or_default(),
                image: cell_string(&biblio_table, row, "image"),
                gmd_id: cell_i64(&biblio_table, row, "gmd_id"),
                gmd_name: cell_string(&biblio_table, row, "gmd_id")
                    .and_then(|k| gmd.get(&k).cloned()),
                publisher: cell_string(&biblio_table, row, "publisher_id")
                    .and_then(|k| publisher.get(&k).cloned()),
                publish_place: cell_string(&biblio_table, row, "publish_place_id")
                    .and_then(|k| place.get(&k).cloned()),
                publish_year: cell_string(&biblio_table, row, "publish_year"),
                isbn: cell_string(&biblio_table, row, "isbn_issn"),
                call_number: cell_string(&biblio_table, row, "call_number"),
                language_id: cell_string(&biblio_table, row, "language_id").unwrap_or_else(|| "en".into()),
                language_name: cell_string(&biblio_table, row, "language_id")
                    .and_then(|k| language.get(&k).cloned()),
                classification: cell_string(&biblio_table, row, "classification"),
                notes: cell_string(&biblio_table, row, "notes"),
                series_title: cell_string(&biblio_table, row, "series_title"),
                edition: cell_string(&biblio_table, row, "edition"),
                collation: cell_string(&biblio_table, row, "collation"),
                sor: cell_string(&biblio_table, row, "sor"),
                spec_detail_info: cell_string(&biblio_table, row, "spec_detail_info"),
                opac_hide: cell_i64(&biblio_table, row, "opac_hide").unwrap_or(0),
                promoted: cell_i64(&biblio_table, row, "promoted").unwrap_or(0),
                last_update: cell_string(&biblio_table, row, "last_update"),
                authors: Vec::new(),
                topics: Vec::new(),
                coll_types: Vec::new(),
                items: Vec::new(),
            };
            self.biblio.push(biblio);
        }
        self.biblio.sort_by_key(|b| b.id);

        // authors
        if let Some(table) = data.table("biblio_author") {
            for row in &table.rows {
                let biblio_id = table.get(row, "biblio_id").and_then(SqlValue::as_i64);
                let author_id = cell_string(table, row, "author_id");
                let level = table.get(row, "level").and_then(SqlValue::as_i64).unwrap_or(1);
                if let (Some(biblio_id), Some(author_id)) = (biblio_id, author_id) {
                    if let Some(name) = author.get(&author_id).cloned() {
                        if let Some(biblio) = self.biblio_mut(biblio_id) {
                            biblio.authors.push(Author { name, level });
                        }
                    }
                }
            }
        }

        // topics
        if let Some(table) = data.table("biblio_topic") {
            for row in &table.rows {
                let biblio_id = table.get(row, "biblio_id").and_then(SqlValue::as_i64);
                let topic_id = cell_string(table, row, "topic_id");
                if let (Some(biblio_id), Some(topic_id)) = (biblio_id, topic_id) {
                    if let Some(name) = topic.get(&topic_id).cloned() {
                        if let Some(biblio) = self.biblio_mut(biblio_id) {
                            biblio.topics.push(name);
                        }
                    }
                }
            }
        }

        // items
        if let Some(table) = data.table("item") {
            for row in &table.rows {
                let biblio_id = table.get(row, "biblio_id").and_then(SqlValue::as_i64);
                let Some(biblio_id) = biblio_id else { continue };
                let coll_type_id = cell_string(table, row, "coll_type_id");
                let location_id = cell_string(table, row, "location_id");
                let status_id = cell_string(table, row, "item_status_id");
                if status_id.as_deref().map_or(true, |status| status.is_empty() || status == "0") {
                    if let Some(code) = cell_string(table, row, "item_code") {
                        self.reservable_items.entry(biblio_id).or_default().push(code);
                    }
                }
                let copy = ItemCopy {
                    item_code: cell_string(table, row, "item_code").unwrap_or_default(),
                    call_number: cell_string(table, row, "call_number"),
                    location: location_id.as_ref().and_then(|k| location.get(k).cloned()),
                    status: status_id.as_ref().and_then(|k| item_status.get(k).cloned()),
                    site: cell_string(table, row, "site"),
                    coll_type: coll_type_id.as_ref().and_then(|k| coll_type.get(k).cloned()),
                    on_loan: false,
                };
                if let Some(biblio) = self.biblio_mut(biblio_id) {
                    if let Some(name) = copy.coll_type.clone() {
                        if !biblio.coll_types.contains(&name) {
                            biblio.coll_types.push(name);
                        }
                    }
                    biblio.items.push(copy);
                }
            }
        }

        // Existing database reservations are separate from disposable demo
        // mutations. Match the MySQL inner join, sorting and NULL display.
        if let Some(table) = data.table("reserve") {
            for row in &table.rows {
                let (Some(id), Some(title_id), Some(member_id)) = (
                    cell_i64(table, row, "reserve_id"), cell_i64(table, row, "biblio_id"),
                    cell_string(table, row, "member_id")) else { continue; };
                let Some(title) = self.biblio.iter().find(|b| b.id == title_id) else { continue; };
                self.stored_reservations.push((id, DemoReservation {
                    member_id, biblio_id: title_id, title: title.title.clone(),
                    item_code: cell_string(table, row, "item_code").unwrap_or_default(),
                    reserve_date: cell_string(table, row, "reserve_date").unwrap_or_default(),
                }));
            }
            self.stored_reservations.sort_by(|a, b| b.0.cmp(&a.0));
        }

        // members
        if let Some(table) = data.table("member") {
            for row in &table.rows {
                let id = cell_string(table, row, "member_id").unwrap_or_default();
                if id.is_empty() {
                    continue;
                }
                if let Some(hash) = cell_string(table, row, "mpasswd") {
                    self.member_passwords.lock().unwrap_or_else(|e| e.into_inner()).insert(id.clone(), hash);
                }
                let type_id = cell_i64(table, row, "member_type_id");
                self.member_profiles.insert(id.clone(), MemberProfile {
                    id: id.clone(), name: cell_string(table, row, "member_name").unwrap_or_default(),
                    email: cell_string(table, row, "member_email"),
                    member_type: type_id.map(|i| i.to_string()).and_then(|k| member_type.get(&k).cloned()),
                    last_login: cell_string(table, row, "last_login"),
                    register_date: cell_string(table, row, "register_date"),
                    expiry_date: cell_string(table, row, "expire_date"),
                    institution: cell_string(table, row, "inst_name"),
                    pending: cell_i64(table, row, "is_pending").unwrap_or(0) != 0,
                });
                self.members.push(Member {
                    id,
                    name: cell_string(table, row, "member_name").unwrap_or_default(),
                    member_type_id: type_id,
                    member_type_name: type_id
                        .map(|i| i.to_string())
                        .and_then(|k| member_type.get(&k).cloned()),
                    image: cell_string(table, row, "member_image"),
                    email: cell_string(table, row, "member_email"),
                });
            }
        }

        // Preserve distinct bookmark rows, even duplicate member/title pairs,
        // because the SLiMS schema only makes the bookmark ID unique.
        if let Some(table) = data.table("biblio_mark") {
            for row in &table.rows {
                if let (Some(id), Some(member), Some(biblio), Some(created)) = (
                    cell_string(table, row, "id"), cell_string(table, row, "member_id"),
                    cell_i64(table, row, "biblio_id"), cell_string(table, row, "created_at")) {
                    self.bookmarks.lock().unwrap_or_else(|e| e.into_inner()).push((id, member, biblio, created));
                }
            }
        }

        // loans
        if let Some(table) = data.table("loan") {
            for row in &table.rows {
                if let Some(loan) = parse_loan(table, row) {
                    self.loans.push(loan);
                }
            }
        }

        if demo_circulation && self.loans.is_empty() {
            self.seed_demo_circulation(&member_type);
        }

        // Demo members are synthesized rather than read from SQL.
        for member in &self.members {
            self.member_profiles.entry(member.id.clone()).or_insert_with(|| MemberProfile {
                id: member.id.clone(), name: member.name.clone(), email: member.email.clone(),
                member_type: member.member_type_name.clone(), expiry_date: Some("2030-01-01".into()),
                ..MemberProfile::default()
            });
        }

        // mark copies on loan
        let active: std::collections::HashSet<String> = self
            .loans
            .iter()
            .filter(|l| l.is_lent && !l.is_return)
            .map(|l| l.item_code.clone())
            .collect();
        for biblio in &mut self.biblio {
            for item in &mut biblio.items {
                item.on_loan = active.contains(&item.item_code);
            }
        }
    }

    /// Enable ephemeral reservation writes, exclusively for the memory demo.
    pub fn enable_demo_reservations(&mut self) { self.demo_reservations_enabled = true; }

    /// Deliberately explicit test/demo override; never set a default password.
    pub fn set_demo_member_password(&self, member_id: &str, hash: &str) {
        if self.members.iter().any(|member| member.id == member_id) {
            let mut passwords = self.member_passwords.lock().unwrap_or_else(|e| e.into_inner());
            if hash.is_empty() { passwords.remove(member_id); }
            else { passwords.insert(member_id.into(), hash.into()); }
        }
    }

    fn biblio_mut(&mut self, id: i64) -> Option<&mut Biblio> {
        self.biblio.iter_mut().find(|b| b.id == id)
    }

    fn seed_demo_circulation(&mut self, member_type: &HashMap<String, String>) {
        let items: Vec<String> = self
            .biblio
            .iter()
            .flat_map(|b| b.items.iter().map(|i| i.item_code.clone()))
            .take(10)
            .collect();
        if items.is_empty() {
            return;
        }

        self.members = vec![
            Member {
                id: "M001".into(),
                name: "Siti Rahma".into(),
                member_type_id: Some(1),
                member_type_name: member_type.get("1").cloned(),
                image: None,
                email: Some("siti.rahma@example.org".into()),
            },
            Member {
                id: "M002".into(),
                name: "Budi Santoso".into(),
                member_type_id: Some(1),
                member_type_name: member_type.get("1").cloned(),
                image: None,
                email: Some("budi.santoso@example.org".into()),
            },
            Member {
                id: "M003".into(),
                name: "Dewi Lestari".into(),
                member_type_id: Some(1),
                member_type_name: member_type.get("1").cloned(),
                image: None,
                email: Some("dewi.lestari@example.org".into()),
            },
            Member {
                id: "M004".into(),
                name: "Agus Wijaya".into(),
                member_type_id: Some(1),
                member_type_name: member_type.get("1").cloned(),
                image: None,
                email: Some("agus.wijaya@example.org".into()),
            },
        ];

        let today = Date::today();
        let mut id = 1;
        let mut add = |item: &str, member: &str, days_ago: i64, due: i64, returned: bool, renewed: i64| {
            self.loans.push(Loan {
                id,
                item_code: item.to_string(),
                member_id: member.to_string(),
                loan_date: today.add_days(-days_ago),
                due_date: today.add_days(due),
                renewed,
                is_lent: true,
                is_return: returned,
                return_date: if returned {
                    Some(today.add_days(-days_ago + 5))
                } else {
                    None
                },
            });
            id += 1;
        };

        // A few popular titles, then a spread of returned, renewed and overdue loans.
        add(&items[0], "M001", 2, 12, false, 0);
        add(&items[0], "M002", 20, 27, true, 0);
        add(&items[0], "M003", 40, 47, true, 0);
        add(&items[1], "M002", 3, 10, false, 0);
        add(&items[1], "M004", 25, 32, true, 0);
        add(&items[2], "M003", 4, 11, false, 0);
        add(&items[3], "M004", 5, 12, false, 0);
        add(&items[4], "M001", 6, 13, true, 0);
        add(&items[5], "M002", 9, -2, false, 0); // overdue
        add(&items[6], "M003", 7, 14, false, 1); // renewed
        add(&items[7], "M004", 12, -1, false, 0); // overdue
        add(&items[8], "M001", 15, 22, true, 0);
    }

    fn biblio_with_id(&self, id: i64) -> Option<&Biblio> {
        self.biblio.iter().find(|b| b.id == id)
    }

    fn visible_biblio(&self) -> impl Iterator<Item = &Biblio> {
        self.biblio.iter().filter(|b| b.opac_hide < 1)
    }

    fn latest_visible(&self, limit: usize) -> Vec<Biblio> {
        let mut items: Vec<Biblio> = self.visible_biblio().cloned().collect();
        items.sort_by(|a, b| b.last_update.cmp(&a.last_update).then(a.id.cmp(&b.id)));
        items.truncate(limit);
        items
    }

    fn loan_counts_by_biblio(&self) -> HashMap<i64, i64> {
        let mut counts: HashMap<i64, i64> = HashMap::new();
        let item_to_biblio: HashMap<&str, i64> = self
            .biblio
            .iter()
            .flat_map(|b| b.items.iter().map(move |i| (i.item_code.as_str(), b.id)))
            .collect();
        for loan in &self.loans {
            if let Some(biblio_id) = item_to_biblio.get(loan.item_code.as_str()) {
                *counts.entry(*biblio_id).or_insert(0) += 1;
            }
        }
        counts
    }

    fn sort_hits(&self, items: &mut [Biblio], query: &SearchQuery) {
        match query.sort {
            SortField::Title => items.sort_by(|a, b| {
                a.title
                    .to_lowercase()
                    .cmp(&b.title.to_lowercase())
                    .then(a.id.cmp(&b.id))
            }),
            SortField::Year => items.sort_by(|a, b| {
                year_of(a.publish_year.as_deref())
                    .cmp(&year_of(b.publish_year.as_deref()))
                    .then(a.id.cmp(&b.id))
            }),
            SortField::Updated => items.sort_by(|a, b| {
                b.last_update
                    .cmp(&a.last_update)
                    .then(a.id.cmp(&b.id))
            }),
        }
        if query.direction == SortDir::Desc {
            items.reverse();
        }
    }

    fn matches(&self, biblio: &Biblio, query: &SearchQuery) -> bool {
        let haystack = format!(
            "{} {} {} {} {} {} {} {} {} {} {} {}",
            biblio.title,
            biblio.series_title.as_deref().unwrap_or(""),
            biblio.notes.as_deref().unwrap_or(""),
            biblio.isbn.as_deref().unwrap_or(""),
            biblio.publisher.as_deref().unwrap_or(""),
            biblio.call_number.as_deref().unwrap_or(""),
            biblio.classification.as_deref().unwrap_or(""),
            biblio.author_names().join(" "),
            biblio.topic_line(),
            biblio.gmd_name.as_deref().unwrap_or(""),
            biblio.coll_types.join(" "),
            biblio.language_name.as_deref().unwrap_or(""),
        )
        .to_lowercase();

        for term in query.keywords.split_whitespace() {
            let term = term.to_lowercase();
            if !haystack.contains(&term) {
                return false;
            }
        }
        if !query.title.is_empty() && !biblio.title.to_lowercase().contains(&query.title.to_lowercase()) {
            return false;
        }
        if !query.author.is_empty()
            && !biblio
                .author_names()
                .iter()
                .any(|a| a.to_lowercase().contains(&query.author.to_lowercase()))
        {
            return false;
        }
        if !query.subject.is_empty()
            && !biblio
                .topics
                .iter()
                .any(|t| t.to_lowercase().contains(&query.subject.to_lowercase()))
        {
            return false;
        }
        if !query.isbn.is_empty() {
            let needle = query.isbn.to_lowercase();
            let found = biblio
                .isbn
                .as_deref()
                .map(|i| i.to_lowercase().contains(&needle))
                .unwrap_or(false);
            if !found {
                return false;
            }
        }
        if !query.publisher.is_empty()
            && !biblio
                .publisher
                .as_deref()
                .map(|p| p.to_lowercase().contains(&query.publisher.to_lowercase()))
                .unwrap_or(false)
        {
            return false;
        }
        if !query.year.is_empty() {
            let needle = query.year.trim();
            if !biblio
                .publish_year
                .as_deref()
                .map(|y| y.contains(needle))
                .unwrap_or(false)
            {
                return false;
            }
        }
        if !query.gmd.is_empty()
            && !biblio
                .gmd_name
                .as_deref()
                .map(|g| g.to_lowercase().contains(&query.gmd.to_lowercase()))
                .unwrap_or(false)
        {
            return false;
        }
        if !query.coll_type.is_empty()
            && !biblio
                .coll_types
                .iter()
                .any(|c| c.to_lowercase().contains(&query.coll_type.to_lowercase()))
        {
            return false;
        }
        true
    }

    fn facets(items: &[Biblio]) -> (Vec<Facet>, Vec<Facet>) {
        let mut gmd: BTreeMap<String, i64> = BTreeMap::new();
        let mut topics: BTreeMap<String, i64> = BTreeMap::new();
        for biblio in items {
            if let Some(name) = &biblio.gmd_name {
                *gmd.entry(name.clone()).or_insert(0) += 1;
            }
            for topic in &biblio.topics {
                *topics.entry(topic.clone()).or_insert(0) += 1;
            }
        }
        let mut gmd_facets: Vec<Facet> = gmd
            .into_iter()
            .map(|(name, count)| Facet { name, count })
            .collect();
        gmd_facets.sort_by(|a, b| b.count.cmp(&a.count).then(a.name.cmp(&b.name)));
        let mut topic_facets: Vec<Facet> = topics
            .into_iter()
            .map(|(name, count)| Facet { name, count })
            .collect();
        topic_facets.sort_by(|a, b| b.count.cmp(&a.count).then(a.name.cmp(&b.name)));
        topic_facets.truncate(12);
        (gmd_facets, topic_facets)
    }
}

fn parse_loan(table: &Table, row: &[SqlValue]) -> Option<Loan> {
    Some(Loan {
        id: table.get(row, "loan_id").and_then(SqlValue::as_i64)?,
        item_code: cell_string(table, row, "item_code")?,
        member_id: cell_string(table, row, "member_id").unwrap_or_default(),
        loan_date: Date::parse(&cell_string(table, row, "loan_date").unwrap_or_default())?,
        due_date: Date::parse(&cell_string(table, row, "due_date").unwrap_or_default())?,
        renewed: table.get(row, "renewed").and_then(SqlValue::as_i64).unwrap_or(0),
        is_lent: table.get(row, "is_lent").and_then(SqlValue::as_i64).unwrap_or(0) == 1,
        is_return: table.get(row, "is_return").and_then(SqlValue::as_i64).unwrap_or(0) == 1,
        return_date: cell_string(table, row, "return_date").and_then(|s| Date::parse(&s)),
    })
}

fn cell_string(table: &Table, row: &[SqlValue], column: &str) -> Option<String> {
    table.get(row, column).and_then(SqlValue::string)
}

fn cell_i64(table: &Table, row: &[SqlValue], column: &str) -> Option<i64> {
    table.get(row, column).and_then(SqlValue::as_i64)
}

fn string_map(data: &SeedData, table: &str, key: &str, value: &str) -> HashMap<String, String> {
    let mut map = HashMap::new();
    if let Some(table) = data.table(table) {
        for row in &table.rows {
            let key = cell_string(table, row, key);
            let value = cell_string(table, row, value);
            if let (Some(key), Some(value)) = (key, value) {
                map.insert(key, value);
            }
        }
    }
    map
}

fn year_of(value: Option<&str>) -> i32 {
    let digits: String = value
        .unwrap_or("")
        .chars()
        .take_while(|c| c.is_ascii_digit())
        .collect();
    digits.parse().unwrap_or(0)
}

impl Store for MemoryStore {
    fn member_reservations(&self, member_id: &str) -> StoreResult<Vec<DemoReservation>> {
        Ok(self.stored_reservations.iter().filter(|(_, row)| row.member_id == member_id)
            .take(100).map(|(_, row)| row.clone()).collect())
    }

    fn demo_reservations(&self, member_id: &str) -> StoreResult<Vec<DemoReservation>> {
        if !self.demo_reservations_enabled { return Err(super::StoreError::Db("demo reservations disabled".into())); }
        let reservations = self.demo_reservations.lock().unwrap_or_else(|e| e.into_inner());
        Ok(reservations.iter().filter(|r| r.member_id == member_id).cloned().collect())
    }

    fn demo_reserve(&self, member_id: &str, biblio_id: i64) -> StoreResult<DemoReserveResult> {
        if !self.demo_reservations_enabled { return Err(super::StoreError::Db("demo reservations disabled".into())); }
        if !self.member_profiles.get(member_id).map_or(false, |profile| profile.can_reserve_on(Date::today())) {
            return Ok(DemoReserveResult::NotAllowed);
        }
        let policy = self.members.iter().find(|member| member.id == member_id)
            .and_then(|member| member.member_type_id).and_then(|id| self.reserve_policy.get(&id));
        let Some((true, limit)) = policy else { return Ok(DemoReserveResult::NotAllowed); };
        // One lock covers limit, duplicate checks, selection and insertion.
        let mut reservations = self.demo_reservations.lock().unwrap_or_else(|e| e.into_inner());
        if reservations.iter().any(|r| r.member_id == member_id && r.biblio_id == biblio_id) {
            return Ok(DemoReserveResult::AlreadyReserved);
        }
        if reservations.iter().filter(|r| r.member_id == member_id).count() >= *limit {
            return Ok(DemoReserveResult::LimitReached);
        }
        let Some(biblio) = self.biblio_with_id(biblio_id).filter(|b| b.opac_hide < 1) else {
            return Ok(DemoReserveResult::Unavailable);
        };
        let mut items = self.reservable_items.get(&biblio_id).cloned().unwrap_or_default();
        items.sort();
        if items.is_empty() { return Ok(DemoReserveResult::Unavailable); }
        let offset = reservations.iter().filter(|r| r.biblio_id == biblio_id).count() % items.len();
        reservations.push(DemoReservation { member_id: member_id.into(), biblio_id,
            title: biblio.title.clone(), item_code: items[offset].clone(), reserve_date: Date::today().iso() });
        Ok(DemoReserveResult::Created)
    }

    fn member_record_login(&self, member_id: &str, expected_hash: &str) -> StoreResult<bool> {
        let passwords = self.member_passwords.lock().unwrap_or_else(|e| e.into_inner());
        if passwords.get(member_id).map(String::as_str) != Some(expected_hash) { return Ok(false); }
        let seconds = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH)
            .map_err(|_| super::StoreError::Db("system clock".into()))?.as_secs();
        let date = Date::from_days((seconds / 86400) as i64);
        let stamp = date.iso_datetime(((seconds / 3600) % 24) as u32, ((seconds / 60) % 60) as u32, (seconds % 60) as u32);
        self.member_logins.lock().unwrap_or_else(|e| e.into_inner()).insert(member_id.into(), stamp);
        Ok(true)
    }

    fn member_change_password(&self, member_id: &str, old_hash: &str, new_hash: &str) -> StoreResult<bool> {
        let mut passwords = self.member_passwords.lock().unwrap_or_else(|e| e.into_inner());
        if passwords.get(member_id).map(String::as_str) != Some(old_hash) { return Ok(false); }
        passwords.insert(member_id.to_string(), new_hash.to_string());
        Ok(true)
    }

    fn member_bookmark_add(&self, member_id: &str, biblio_id: i64) -> StoreResult<()> {
        if !self.members.iter().any(|m| m.id == member_id) { return Err(super::StoreError::NotFound); }
        if !self.biblio_with_id(biblio_id).map_or(false, |b| b.opac_hide < 1) { return Ok(()); }
        let mut bookmarks = self.bookmarks.lock().unwrap_or_else(|e| e.into_inner());
        if !bookmarks.iter().any(|(_, owner, id, _)| owner == member_id && *id == biblio_id) {
            // Memory demo only; real MySQL uses PHP's MD5 key.
            let id = format!("demo-{}-{}", biblio_id, member_id);
            bookmarks.push((id, member_id.into(), biblio_id, format!("{} 00:00:00", Date::today().iso())));
        }
        Ok(())
    }

    fn member_bookmark_remove(&self, member_id: &str, biblio_id: i64) -> StoreResult<()> {
        let mut bookmarks = self.bookmarks.lock().unwrap_or_else(|e| e.into_inner());
        bookmarks.retain(|(_, owner, id, _)| owner != member_id || *id != biblio_id);
        Ok(())
    }

    fn member_bookmark_write_bound(&self, member_id: &str, biblio_id: i64, expected_version: &str, add: bool) -> StoreResult<bool> {
        if biblio_id <= 0 { return Err(super::StoreError::NotFound); }
        let passwords = self.member_passwords.lock().unwrap_or_else(|e| e.into_inner());
        let Some(hash) = passwords.get(member_id) else { return Ok(false) };
        let actual: String = crate::crypto::sha256(hash.as_bytes()).iter().map(|b| format!("{b:02x}")).collect();
        if actual != expected_version { return Ok(false); }
        // Password mutex remains held until the bookmark mutation completes.
        if add {
            if !self.biblio_with_id(biblio_id).map_or(false, |b| b.opac_hide < 1) { return Err(super::StoreError::NotFound); }
            self.member_bookmark_add(member_id, biblio_id)?;
        } else { self.member_bookmark_remove(member_id, biblio_id)?; }
        Ok(true)
    }

    fn member_bookmarks(&self, member_id: &str, offset: usize) -> StoreResult<Vec<MemberBookmark>> {
        if offset > 100_000 { return Err(super::StoreError::Db("bookmark page out of range".into())); }
        let bookmarks = self.bookmarks.lock().unwrap_or_else(|e| e.into_inner());
        let mut rows: Vec<_> = bookmarks.iter().filter(|(_, owner, biblio, _)|
            owner == member_id && self.biblio_with_id(*biblio).is_some()).collect();
        rows.sort_by(|a, b| b.3.cmp(&a.3).then(b.0.cmp(&a.0)));
        Ok(rows.into_iter().skip(offset).take(21).filter_map(|(_, _, id, created)| {
            self.biblio_with_id(*id).map(|book| MemberBookmark {
                biblio_id: *id, title: book.title.clone(), created_at: created.clone(),
            })
        }).collect())
    }

    fn member_profile(&self, member_id: &str) -> StoreResult<Option<MemberProfile>> {
        let mut profile = self.member_profiles.get(member_id).cloned();
        if let Some(profile) = &mut profile {
            if let Some(stamp) = self.member_logins.lock().unwrap_or_else(|e| e.into_inner()).get(member_id) {
                profile.last_login = Some(stamp.clone());
            }
        }
        Ok(profile)
    }

    fn member_credential(&self, member_id: &str) -> StoreResult<Option<MemberCredential>> {
        let passwords = self.member_passwords.lock().unwrap_or_else(|e| e.into_inner());
        Ok(self.members.iter().find(|m| m.id == member_id).and_then(|m| {
            passwords.get(&m.id).map(|password_hash| MemberCredential {
                id: m.id.clone(), name: m.name.clone(), password_hash: password_hash.clone(),
            })
        }))
    }

    fn member_loan_report(&self, member_id: &str) -> StoreResult<Vec<MemberLoanReportRow>> {
        if !self.members.iter().any(|m| m.id == member_id) { return Ok(Vec::new()); }
        let mut loans: Vec<_> = self.loans.iter().filter(|l| l.member_id == member_id && l.is_lent).collect();
        loans.sort_by(|a, b| a.is_return.cmp(&b.is_return).then(b.loan_date.cmp(&a.loan_date)).then(b.id.cmp(&a.id)));
        Ok(loans.into_iter().take(MAX_MEMBER_REPORT_LOANS + 1).map(|l| MemberLoanReportRow {
            returned: l.is_return,
            loan: MemberLoan {
                item_code: l.item_code.clone(),
                title: self.biblio.iter().find(|b| b.items.iter().any(|i| i.item_code == l.item_code)).map(|b| b.title.clone()),
                loan_date: Some(l.loan_date.iso()), due_date: Some(l.due_date.iso()),
                return_date: l.return_date.map(|d| d.iso()),
            },
        }).collect())
    }

    fn member_loans_page(&self, member_id: &str, returned: bool, offset: usize) -> StoreResult<Vec<MemberLoan>> {
        if offset > 100_000 { return Err(super::StoreError::Db("loan page out of range".into())); }
        // Mirror the PHP inner filter on the member table even if orphaned
        // loans exist; never return another member's records.
        if !self.members.iter().any(|member| member.id == member_id) {
            return Ok(Vec::new());
        }
        let mut loans: Vec<&Loan> = self.loans.iter()
            .filter(|loan| loan.member_id == member_id && loan.is_lent && loan.is_return == returned)
            .collect();
        loans.sort_by(|a, b| b.loan_date.cmp(&a.loan_date).then(b.id.cmp(&a.id)));
        Ok(loans.into_iter().skip(offset).take(21).map(|loan| {
            let title = self.biblio.iter().find(|b| b.items.iter().any(|item| item.item_code == loan.item_code))
                .map(|b| b.title.clone());
            MemberLoan {
                item_code: loan.item_code.clone(), title,
                loan_date: Some(loan.loan_date.iso()), due_date: Some(loan.due_date.iso()),
                return_date: loan.return_date.map(|date| date.iso()),
            }
        }).collect())
    }

    fn biblio_popular(&self, limit: usize) -> StoreResult<Vec<PopularBiblio>> {
        let counts = self.loan_counts_by_biblio();
        let mut popular: Vec<(i64, i64)> = counts
            .iter()
            .filter(|(id, _)| self.biblio_with_id(**id).map(|b| b.opac_hide < 1).unwrap_or(false))
            .map(|(id, count)| (*id, *count))
            .collect();
        popular.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
        popular.truncate(limit);

        let mut result: Vec<PopularBiblio> = popular
            .iter()
            .filter_map(|(id, total)| {
                self.biblio_with_id(*id).map(|biblio| PopularBiblio {
                    biblio: biblio.clone(),
                    total: Some(*total),
                })
            })
            .collect();

        if result.len() < limit {
            let taken: std::collections::HashSet<i64> = result.iter().map(|p| p.biblio.id).collect();
            for biblio in self.latest_visible(limit) {
                if result.len() >= limit {
                    break;
                }
                if !taken.contains(&biblio.id) && !result.iter().any(|p| p.biblio.id == biblio.id) {
                    result.push(PopularBiblio { biblio, total: None });
                }
            }
        }
        Ok(result)
    }

    fn biblio_latest(&self, limit: usize) -> StoreResult<Vec<Biblio>> {
        Ok(self.latest_visible(limit))
    }

    fn biblio_total(&self) -> StoreResult<i64> {
        Ok(self.visible_biblio().count() as i64)
    }

    fn biblio_by_gmd(&self, gmd: &str, limit: usize) -> StoreResult<Vec<Biblio>> {
        let mut items: Vec<Biblio> = self
            .visible_biblio()
            .filter(|b| {
                b.gmd_name
                    .as_deref()
                    .map(|g| g.eq_ignore_ascii_case(gmd))
                    .unwrap_or(false)
            })
            .cloned()
            .collect();
        items.sort_by(|a, b| b.last_update.cmp(&a.last_update).then(a.id.cmp(&b.id)));
        items.truncate(limit);
        Ok(items)
    }

    fn biblio_by_coll_type(&self, coll_type: &str, limit: usize) -> StoreResult<Vec<Biblio>> {
        let mut items: Vec<Biblio> = self
            .visible_biblio()
            .filter(|b| {
                b.items
                    .iter()
                    .any(|i| i.coll_type.as_deref().map(|c| c.eq_ignore_ascii_case(coll_type)).unwrap_or(false))
            })
            .cloned()
            .collect();
        items.sort_by(|a, b| b.last_update.cmp(&a.last_update).then(a.id.cmp(&b.id)));
        items.truncate(limit);
        Ok(items)
    }

    fn search(&self, query: &SearchQuery) -> StoreResult<SearchResult> {
        let mut hits: Vec<Biblio> = self
            .visible_biblio()
            .filter(|b| self.matches(b, query))
            .cloned()
            .collect();
        self.sort_hits(&mut hits, query);
        let total = hits.len() as i64;
        let (gmd_facets, topic_facets) = Self::facets(&hits);
        let start = query.offset().min(hits.len());
        let end = (start + query.per_page).min(hits.len());
        Ok(SearchResult {
            total,
            page: query.page,
            per_page: query.per_page,
            items: hits[start..end].to_vec(),
            gmd_facets,
            topic_facets,
        })
    }

    fn detail(&self, id: i64) -> StoreResult<Option<BiblioDetail>> {
        Ok(self.biblio_with_id(id).map(|biblio| BiblioDetail {
            biblio: biblio.clone(),
            attachments: Vec::new(),
        }))
    }

    fn subject_popular(&self, year: i32, limit: usize) -> StoreResult<Vec<String>> {
        let item_to_biblio: HashMap<&str, i64> = self
            .biblio
            .iter()
            .flat_map(|b| b.items.iter().map(move |i| (i.item_code.as_str(), b.id)))
            .collect();
        let mut counts: HashMap<String, i64> = HashMap::new();
        for loan in &self.loans {
            if loan.loan_date.year != year {
                continue;
            }
            if let Some(biblio_id) = item_to_biblio.get(loan.item_code.as_str()) {
                if let Some(biblio) = self.biblio_with_id(*biblio_id) {
                    for topic in &biblio.topics {
                        *counts.entry(topic.clone()).or_insert(0) += 1;
                    }
                }
            }
        }
        let mut topics: Vec<(String, i64)> = counts.into_iter().collect();
        topics.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
        topics.truncate(limit);
        let mut result: Vec<String> = topics.into_iter().map(|(t, _)| t).collect();
        if result.len() < limit {
            let need = limit - result.len();
            for topic in self.subject_latest(need)? {
                if !result.contains(&topic) {
                    result.push(topic);
                }
            }
        }
        Ok(result)
    }

    fn subject_latest(&self, limit: usize) -> StoreResult<Vec<String>> {
        // PHP: take the ten most recently updated titles, collect their topics,
        // then let the database return them in `mst_topic` order. Keeping that
        // order (instead of "most recent title first") is what makes the two
        // backends answer alike.
        let mut biblio: Vec<&Biblio> = self.biblio.iter().collect();
        biblio.sort_by(|a, b| b.last_update.cmp(&a.last_update).then(a.id.cmp(&b.id)));
        let mut names: Vec<String> = Vec::new();
        for b in biblio.iter().take(10) {
            for topic in &b.topics {
                if !names.contains(topic) {
                    names.push(topic.clone());
                }
            }
        }
        let mut ordered: Vec<(usize, String)> = names
            .into_iter()
            .filter_map(|name| {
                self.topic_order
                    .iter()
                    .position(|known| *known == name)
                    .map(|index| (index, name))
            })
            .collect();
        ordered.sort();
        let result: Vec<String> = ordered.into_iter().take(limit).map(|(_, name)| name).collect();
        Ok(result)
    }

    fn top_members(&self, year: i32, limit: usize) -> StoreResult<Vec<TopMember>> {
        let item_to_biblio: HashMap<&str, i64> = self
            .biblio
            .iter()
            .flat_map(|b| b.items.iter().map(move |i| (i.item_code.as_str(), b.id)))
            .collect();
        let member_by_id: HashMap<&str, &Member> =
            self.members.iter().map(|m| (m.id.as_str(), m)).collect();
        let mut rows: Vec<(String, i64, Vec<i64>)> = Vec::new();
        for loan in &self.loans {
            if loan.loan_date.year != year {
                continue;
            }
            if !member_by_id.contains_key(loan.member_id.as_str()) {
                continue;
            }
            let biblio_id = item_to_biblio.get(loan.item_code.as_str()).copied();
            match rows.iter_mut().find(|(id, _, _)| *id == loan.member_id) {
                Some(row) => {
                    row.1 += 1;
                    if let Some(id) = biblio_id {
                        row.2.push(id);
                    }
                }
                None => rows.push((
                    loan.member_id.clone(),
                    1,
                    biblio_id.into_iter().collect(),
                )),
            }
        }
        // The SQL applies LIMIT before PHP's usort(), so take the top rows by
        // loan count first, then reorder by total + distinct titles.
        rows.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
        rows.truncate(limit);

        let mut result: Vec<TopMember> = rows
            .into_iter()
            .filter_map(|(member_id, total, biblio_ids)| {
                let member = member_by_id.get(member_id.as_str())?;
                let mut unique = biblio_ids.clone();
                unique.sort_unstable();
                unique.dedup();
                Some(TopMember {
                    name: member.name.clone(),
                    member_type: member.member_type_name.clone(),
                    image: crate::api::image_path(member.image.as_deref(), "persons"),
                    total,
                    total_title: unique.len(),
                    order: total + unique.len() as i64,
                })
            })
            .collect();
        result.sort_by(|a, b| b.order.cmp(&a.order).then(a.name.cmp(&b.name)));
        Ok(result)
    }

    fn item_total(&self) -> StoreResult<i64> {
        Ok(self.biblio.iter().map(|b| b.items.len() as i64).sum())
    }

    fn item_lent(&self) -> StoreResult<i64> {
        Ok(self.loans.iter().filter(|l| l.is_lent && !l.is_return).count() as i64)
    }

    fn item_available(&self) -> StoreResult<i64> {
        Ok(self.item_total()? - self.item_lent()?)
    }

    fn loan_summary(&self) -> StoreResult<LoanSummary> {
        let today = Date::today();
        Ok(LoanSummary {
            total: self.loans.len() as i64,
            new: self.loans.iter().filter(|l| l.is_lent && !l.is_return).count() as i64,
            returned: self.loans.iter().filter(|l| l.is_return && l.is_lent).count() as i64,
            extended: self
                .loans
                .iter()
                .filter(|l| l.is_lent && !l.is_return && l.renewed > 0)
                .count() as i64,
            overdue: self
                .loans
                .iter()
                .filter(|l| l.is_lent && !l.is_return && l.due_date < today)
                .count() as i64,
        })
    }

    fn loan_dates(&self, start: Date) -> StoreResult<Vec<LoanDatePoint>> {
        let from = start.add_days(-8);
        let mut by_date: BTreeMap<Date, ()> = BTreeMap::new();
        for loan in &self.loans {
            if loan.loan_date >= from && loan.loan_date <= start {
                by_date.insert(loan.loan_date, ());
            }
        }
        Ok(by_date
            .keys()
            .map(|date| LoanDatePoint {
                day_month: date.day_month(),
                iso: date.iso(),
            })
            .collect())
    }

    fn loan_summary_for(&self, dates: &[Date]) -> StoreResult<LoanSummaryForDates> {
        let mut result = LoanSummaryForDates::default();
        for date in dates {
            let mut loan_count = 0;
            let mut returns: BTreeMap<Option<Date>, i64> = BTreeMap::new();
            let mut extended: BTreeMap<Option<Date>, i64> = BTreeMap::new();
            for loan in &self.loans {
                if loan.loan_date != *date || !loan.is_lent {
                    continue;
                }
                if loan.renewed == 0 && !loan.is_return {
                    loan_count += 1;
                }
                if loan.is_return {
                    *returns.entry(loan.return_date).or_insert(0) += 1;
                }
                if loan.renewed >= 1 && !loan.is_return {
                    *extended.entry(loan.return_date).or_insert(0) += 1;
                }
            }
            result.loan.push(loan_count);
            let return_values: Vec<i64> = returns.values().copied().collect();
            result.returned.extend(if return_values.is_empty() {
                vec![0]
            } else {
                return_values
            });
            let extended_values: Vec<i64> = extended.values().copied().collect();
            result.extended.extend(if extended_values.is_empty() {
                vec![0]
            } else {
                extended_values
            });
        }
        Ok(result)
    }

    fn backend_name(&self) -> &'static str {
        "memory (install/*.sql + demo circulation)"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn store() -> MemoryStore {
        MemoryStore::load(&crate::config::find_root(std::path::Path::new(env!("CARGO_MANIFEST_DIR"))), true)
    }

    #[test]
    fn bookmarks_use_member_and_title_join_and_deterministic_order() {
        let store = store();
        *store.bookmarks.lock().unwrap() = vec![
            ("a".into(), "M001".into(), 2, "2024-01-01".into()),
            ("b".into(), "M001".into(), 4, "2024-01-01".into()),
            ("c".into(), "M001".into(), 6, "2023-01-01".into()),
            ("d".into(), "M002".into(), 4, "2024-02-01".into()),
            ("e".into(), "M001".into(), 999999, "2024-03-01".into()),
        ];
        let result = store.member_bookmarks("M001", 0).unwrap();
        assert_eq!(result.iter().map(|b| b.biblio_id).collect::<Vec<_>>(), vec![4, 2, 6]);
        assert_eq!(store.member_bookmarks("M001", 1).unwrap()[0].biblio_id, 2);
        assert_eq!(store.member_bookmarks("M002", 0).unwrap().len(), 1);
        assert!(store.member_bookmarks("M001' OR 1=1 --", 0).unwrap().is_empty());
        assert!(store.member_bookmarks("M001", 100_001).is_err());
    }

    #[test]
    fn loads_sample_biblio_with_authors_and_topics() {
        let store = store();
        let php = store.detail(1).unwrap().unwrap();
        assert_eq!(php.biblio.title, "PHP 5 for dummies");
        assert!(!php.biblio.authors.is_empty());
        assert!(!php.biblio.topics.is_empty());

        let ajax = store.detail(8).unwrap().unwrap();
        assert!(!ajax.biblio.items.is_empty());
        assert_eq!(ajax.biblio.items[0].item_code, "B00001");
    }

    #[test]
    fn search_matches_keywords_across_fields() {
        let store = store();
        let mut query = SearchQuery::default();
        query.keywords = "linux".into();
        let result = store.search(&query).unwrap();
        assert!(result.total >= 1);
        assert!(result.items.iter().all(|b| b.opac_hide < 1));
    }

    #[test]
    fn search_paginates() {
        let store = store();
        let mut query = SearchQuery::default();
        query.per_page = 5;
        query.page = 2;
        let result = store.search(&query).unwrap();
        assert_eq!(result.items.len(), 5);
        assert_eq!(result.total, 15);
        assert_eq!(result.total_pages(), 3);
    }

    #[test]
    fn popular_fills_with_latest_when_loans_are_few() {
        let store = store();
        let popular = store.biblio_popular(6).unwrap();
        assert_eq!(popular.len(), 6);
        assert!(popular.iter().any(|p| p.total.is_some()));
    }

    #[test]
    fn demo_circulation_drives_the_api() {
        let store = store();
        let summary = store.loan_summary().unwrap();
        assert!(summary.total > 0);
        assert!(summary.new > 0);
        assert!(summary.returned > 0);
        assert!(summary.extended > 0);
        assert!(summary.overdue > 0);

        let members = store.top_members(Date::today().year, 3).unwrap();
        assert_eq!(members.len(), 3);
        assert!(members.windows(2).all(|w| w[0].order >= w[1].order));

        let dates = store.loan_dates(Date::today()).unwrap();
        assert!(!dates.is_empty());
    }

    #[test]
    fn member_loans_are_isolated_and_filtered() {
        let store = store();
        let current = store.member_loans("M001", false).unwrap();
        let history = store.member_loans("M001", true).unwrap();
        assert_eq!(current.len(), 1);
        assert_eq!(history.len(), 2);
        assert!(current[0].return_date.is_none());
        assert!(history.iter().all(|row| row.return_date.is_some()));
        assert!(history[0].loan_date >= history[1].loan_date);
        assert!(store.member_loans("M001' OR 1=1 --", false).unwrap().is_empty());
        assert!(store.member_loans("not-a-member", false).unwrap().is_empty());
        assert!(current.iter().all(|row| row.title.is_some()));
    }

    #[test]
    fn member_loans_limit_results_and_exclude_unlent_and_orphans() {
        let mut store = store();
        let today = Date::today();
        for id in 100..125 {
            store.loans.push(Loan {
                id, item_code: "missing".into(), member_id: "M001".into(),
                loan_date: today.add_days(-id), due_date: today, is_lent: true,
                is_return: true, return_date: Some(today), ..Loan::default()
            });
        }
        store.loans.push(Loan {
            id: 1000, member_id: "M001".into(), loan_date: today,
            is_lent: false, is_return: false, ..Loan::default()
        });
        store.loans.push(Loan {
            id: 1001, member_id: "orphan".into(), loan_date: today,
            is_lent: true, is_return: false, ..Loan::default()
        });
        assert_eq!(store.member_loans("M001", true).unwrap().len(), 20);
        assert_eq!(store.member_loans_page("M001", true, 0).unwrap().len(), 21);
        assert_eq!(store.member_loans_page("M001", true, 20).unwrap().len(), 7);
        assert!(store.member_loans_page("M001", true, 100_001).is_err());
        assert_eq!(store.member_loans("M001", false).unwrap().len(), 1);
        assert!(store.member_loans("orphan", false).unwrap().is_empty());
    }

    #[test]
    fn demo_reservation_rechecks_membership_before_each_write() {
        let mut store = store();
        store.enable_demo_reservations();
        for expiry in [None, Some("2000-01-01"), Some("2030-02-30")] {
            store.member_profiles.get_mut("M001").unwrap().expiry_date = expiry.map(str::to_string);
            assert_eq!(store.demo_reserve("M001", 2).unwrap(), DemoReserveResult::NotAllowed);
        }
        let profile = store.member_profiles.get_mut("M001").unwrap();
        profile.expiry_date = Some(Date::today().iso());
        profile.pending = true;
        assert_eq!(store.demo_reserve("M001", 2).unwrap(), DemoReserveResult::NotAllowed);
        assert!(store.demo_reservations("M001").unwrap().is_empty());
        store.member_profiles.get_mut("M001").unwrap().pending = false;
        assert_eq!(store.demo_reserve("M001", 2).unwrap(), DemoReserveResult::Created);
    }

    #[test]
    fn demo_reservations_enforce_permissions_limit_and_duplicates_atomically() {
        let mut store = store();
        assert!(store.demo_reserve("M001", 2).is_err());
        store.enable_demo_reservations();
        let store = std::sync::Arc::new(store);
        assert_eq!(store.demo_reserve("missing", 2).unwrap(), DemoReserveResult::NotAllowed);
        assert_eq!(store.demo_reserve("M001", 1).unwrap(), DemoReserveResult::Unavailable);
        let threads: Vec<_> = (0..12).map(|_| {
            let store = store.clone();
            std::thread::spawn(move || store.demo_reserve("M001", 2).unwrap())
        }).collect();
        let results: Vec<_> = threads.into_iter().map(|t| t.join().unwrap()).collect();
        assert_eq!(results.iter().filter(|r| **r == DemoReserveResult::Created).count(), 1);
        assert_eq!(results.iter().filter(|r| **r == DemoReserveResult::AlreadyReserved).count(), 11);
        assert_eq!(store.demo_reserve("M001", 4).unwrap(), DemoReserveResult::Created);
        assert_eq!(store.demo_reserve("M001", 6).unwrap(), DemoReserveResult::LimitReached);
        assert_eq!(store.demo_reservations("M001").unwrap().len(), 2);
        assert!(store.demo_reservations("M002").unwrap().is_empty());
        assert_eq!(store.demo_reserve("M002", 2).unwrap(), DemoReserveResult::Created);
        // PHP round-robin chooses the second item for the next member.
        assert_ne!(store.demo_reservations("M001").unwrap()[0].item_code, store.demo_reservations("M002").unwrap()[0].item_code);
    }

    #[test]
    fn simultaneous_distinct_demo_reservations_cannot_exceed_limit() {
        let mut store = store();
        store.enable_demo_reservations();
        let store = std::sync::Arc::new(store);
        let threads: Vec<_> = [2, 4, 6, 8, 12, 13, 14, 15].into_iter().map(|id| {
            let store = store.clone();
            std::thread::spawn(move || store.demo_reserve("M001", id).unwrap())
        }).collect();
        let results: Vec<_> = threads.into_iter().map(|t| t.join().unwrap()).collect();
        assert_eq!(results.iter().filter(|r| **r == DemoReserveResult::Created).count(), 2);
        assert_eq!(store.demo_reservations("M001").unwrap().len(), 2);
    }

    #[test]
    fn hidden_records_never_leak() {
        let store = store();
        let all = store.search(&SearchQuery::default()).unwrap();
        assert!(all.items.iter().all(|b| b.opac_hide < 1));
        assert_eq!(all.total, 15);
    }
}
