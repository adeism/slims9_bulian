//! OPAC pages: home, search results, biblio detail and the legacy
//! `index.php?p=...` URLs that bookmarks and citations still use.

use crate::http::{url_decode, url_encode, Request, Response};
use crate::models::*;
use crate::templates::*;
use crate::app::AppState;

pub fn handle(request: &Request, state: &AppState) -> Response {
    let path = request.path.trim_end_matches('/');
    let path = if path.is_empty() { "/" } else { path };

    match path {
        "/" => home(request, state),
        "/search" => search(request, state, false),
        "/member" => member(state),
        "/index.php" => legacy(request, state),
        _ => {
            if let Some(id) = path.strip_prefix("/biblio/") {
                return detail(request, state, &url_decode(id));
            }
            not_found_page(state)
        }
    }
}

fn home(_request: &Request, state: &AppState) -> Response {
    let config = &state.config;
    let latest = state.store.biblio_latest(config.latest_limit).unwrap_or_default();
    let popular = state.store.biblio_popular(config.popular_limit).unwrap_or_default();
    let topics = state.store.subject_latest(12).unwrap_or_default();
    let total = state.store.biblio_total().unwrap_or(0);
    let items = state.store.item_total().unwrap_or(0);
    let available = state.store.item_available().unwrap_or(0);

    let mut topic_chips = String::new();
    for topic in &topics {
        topic_chips.push_str(&format!(
            "<a class=\"chip\" href=\"/search?subject={}\">{}</a>",
            url_encode(topic),
            escape(topic)
        ));
    }

    let content = format!(
        "<section class=\"hero\">\n  <p class=\"eyebrow\">{subname}</p>\n  <h1>Find your next read.</h1>\n  <p class=\"lead\">Search the catalog by title, author, subject or ISBN. The public catalog and the REST API are served by a single Rust binary.</p>\n  {search}\n  <div class=\"stats\">\n    {stat_total}\n    {stat_items}\n    {stat_available}\n  </div>\n</section>\n\
         <section class=\"section\">\n  <div class=\"section-head\"><h2>New in the catalog</h2><a href=\"/search\">Browse all {total} titles →</a></div>\n  {latest_grid}\n</section>\n\
         <section class=\"section\">\n  <div class=\"section-head\"><h2>Popular this year</h2><a href=\"/api/biblio/popular\">JSON endpoint →</a></div>\n  {popular_grid}\n</section>\n\
         <section class=\"section\">\n  <div class=\"section-head\"><h2>Browse topics</h2></div>\n  <div class=\"chips\">{topics}</div>\n</section>\n",
        subname = escape(&config.library_subname),
        search = search_form("", &AdvancedFields::default()),
        stat_total = stat(total, "titles in the catalog"),
        stat_items = stat(items, "copies"),
        stat_available = stat(available, "available now"),
        total = total,
        latest_grid = grid(&latest),
        popular_grid = popular_grid(&popular),
        topics = topic_chips,
    );

    Response::html(200, layout(config, "Home", "home", &content))
}

fn grid(items: &[Biblio]) -> String {
    let mut out = String::from("<div class=\"grid\">");
    for biblio in items {
        out.push_str(&biblio_card(biblio));
    }
    out.push_str("</div>");
    out
}

fn popular_grid(items: &[PopularBiblio]) -> String {
    let mut out = String::from("<div class=\"grid\">");
    for item in items {
        out.push_str(&biblio_card(&item.biblio));
    }
    out.push_str("</div>");
    out
}

fn search(request: &Request, state: &AppState, legacy: bool) -> Response {
    let (query, advanced) = build_query(request, legacy);
    if query.is_empty() {
        return home(request, state);
    }

    let result = match state.store.search(&query) {
        Ok(result) => result,
        Err(error) => return error_page(state, &error.to_string()),
    };

    let base_params: Vec<(String, String)> = vec![
        ("q".into(), query.keywords.clone()),
        ("title".into(), advanced.title.clone()),
        ("author".into(), advanced.author.clone()),
        ("subject".into(), advanced.subject.clone()),
        ("isbn".into(), advanced.isbn.clone()),
        ("publisher".into(), query.publisher.clone()),
        ("year".into(), query.year.clone()),
        ("gmd".into(), query.gmd.clone()),
        ("coll_type".into(), query.coll_type.clone()),
        ("sort".into(), query.sort.as_str().into()),
        ("dir".into(), query.direction.as_str().into()),
    ]
    .into_iter()
    .filter(|(_, value)| !value.is_empty())
    .collect();

    let mut rows = String::new();
    for biblio in &result.items {
        rows.push_str(&result_row(biblio));
    }

    let sort_link = |field: &str, label: &str| {
        let mut params = base_params.clone();
        params.retain(|(key, _)| key != "sort" && key != "dir");
        params.push(("sort".into(), field.into()));
        let href = build_url_owned("/search", &params);
        let active = query.sort.as_str() == field;
        format!(
            "<a class=\"{}\" href=\"{}\">{}</a>",
            if active { "chip" } else { "chip muted" },
            escape(&href),
            escape(label)
        )
    };

    let content = if result.total == 0 {
        format!(
            "<section class=\"page-head\">\n  <p class=\"eyebrow\">Search results</p>\n  <h1>No matches</h1>\n  <p>Nothing matched {query}. Try fewer words or another field.</p>\n</section>\n{search}\n<div class=\"empty\"><h2>Nothing in the catalog</h2><p>The search covered titles, authors, subjects, ISBNs and publishers.</p></div>\n",
            query = escape(&query.keywords),
            search = search_form(&query.keywords, &advanced),
        )
    } else {
        let mut facet_params = base_params.clone();
        facet_params.retain(|(key, _)| key != "gmd" && key != "subject");
        let gmd_facets = facet_list(
            "Type",
            &result.gmd_facets,
            "gmd",
            "/search",
            &facet_params,
        );
        let mut topic_params = base_params.clone();
        topic_params.retain(|(key, _)| key != "subject");
        let topic_facets = facet_list(
            "Subjects",
            &result.topic_facets,
            "subject",
            "/search",
            &topic_params,
        );

        format!(
            "<section class=\"page-head\">\n  <p class=\"eyebrow\">Search results</p>\n  <h1>{total} title{plural}</h1>\n  <p>{query} · sorted by <strong>{sort}</strong> {dir}</p>\n</section>\n{search}\n\
             <div class=\"search-layout\">\n  <aside>{gmd_facets}{topic_facets}</aside>\n  <div>\n    <div class=\"section-head\"><p class=\"muted\">Showing {from}–{to} of {total}</p><div class=\"chips\">{sort_updated}{sort_title}{sort_year}</div></div>\n    {rows}\n    {pagination}\n  </div>\n</div>\n",
            total = result.total,
            plural = if result.total == 1 { "" } else { "s" },
            query = escape(&query.keywords),
            sort = escape(query.sort.as_str()),
            dir = escape(query.direction.as_str()),
            search = search_form(&query.keywords, &advanced),
            gmd_facets = gmd_facets,
            topic_facets = topic_facets,
            from = result.items.first().map(|_| query.offset() + 1).unwrap_or(0),
            to = query.offset() + result.items.len(),
            sort_updated = sort_link("updated", "Newest"),
            sort_title = sort_link("title", "Title"),
            sort_year = sort_link("year", "Year"),
            rows = rows,
            pagination = pagination(&result, "/search", &base_params),
        )
    };

    Response::html(200, layout(&state.config, "Search", "search", &content))
}

fn detail(request: &Request, state: &AppState, raw_id: &str) -> Response {
    let Ok(id) = raw_id.parse::<i64>() else {
        return not_found_page(state);
    };
    let detail = match state.store.detail(id) {
        Ok(Some(detail)) if detail.biblio.opac_hide < 1 => detail,
        Ok(_) => return not_found_page(state),
        Err(error) => return error_page(state, &error.to_string()),
    };
    let biblio = &detail.biblio;
    let cover = cover_url(biblio.image.as_deref());
    let authors = biblio.author_line();
    let back = request
        .query("keywords")
        .or_else(|| request.query("q"))
        .map(|value| build_url("/search", &[("q", value)]))
        .unwrap_or_else(|| "/search".to_string());

    let mut info = Vec::new();
    let mut add = |label: &str, value: Option<String>| {
        let text = value.filter(|v| !v.trim().is_empty()).unwrap_or_else(|| "—".into());
        info.push(format!(
            "<dt>{}</dt><dd>{}</dd>",
            escape(label),
            escape(&text)
        ));
    };
    add("Series title", biblio.series_title.clone());
    add("Call number", biblio.call_number.clone());
    add("Publisher", biblio.publisher.clone());
    add("Publish place", biblio.publish_place.clone());
    add("Publish year", biblio.publish_year.clone());
    add("Collation", biblio.collation.clone());
    add("Language", biblio.language_name.clone());
    add("ISBN / ISSN", biblio.isbn.clone());
    add("Classification", biblio.classification.clone());
    add("Edition", biblio.edition.clone());
    add("Statement of responsibility", biblio.sor.clone());
    add("Specific detail", biblio.spec_detail_info.clone());

    let topics = if biblio.topics.is_empty() {
        "—".to_string()
    } else {
        biblio
            .topics
            .iter()
            .map(|topic| {
                format!(
                    "<a class=\"chip\" href=\"/search?subject={}\">{}</a>",
                    url_encode(topic),
                    escape(topic)
                )
            })
            .collect::<Vec<_>>()
            .join(" ")
    };

    let copies = if biblio.items.is_empty() {
        "<p class=\"muted\">No copy data.</p>".to_string()
    } else {
        let mut rows = String::new();
        for item in &biblio.items {
            let status = if item.on_loan {
                "<span class=\"badge badge-warn\">On loan</span>"
            } else {
                "<span class=\"badge badge-ok\">Available</span>"
            };
            rows.push_str(&format!(
                "<tr><td><code>{}</code></td><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>\n",
                escape(&item.item_code),
                escape(item.call_number.as_deref().unwrap_or("—")),
                escape(item.location.as_deref().unwrap_or("—")),
                escape(item.status.as_deref().unwrap_or("—")),
                status,
            ));
        }
        format!(
            "<table class=\"copies\">\n<thead><tr><th>Item code</th><th>Call number</th><th>Location</th><th>Status</th><th>Availability</th></tr></thead>\n<tbody>\n{}</tbody>\n</table>\n",
            rows
        )
    };

    let notes = biblio
        .notes
        .clone()
        .filter(|notes| !notes.trim().is_empty())
        .unwrap_or_else(|| "No description available.".into());

    let content = format!(
        "<a class=\"backlink\" href=\"{back}\">← Back to search results</a>\n\
         <article class=\"detail\">\n  <div class=\"detail-cover\"><img src=\"{cover}\" alt=\"Cover of {title}\"></div>\n  <div class=\"detail-body\">\n    <p class=\"eyebrow\">{gmd}</p>\n    <h1>{title}</h1>\n    <p class=\"authors\">{authors}</p>\n    <p class=\"meta\">{publisher}{year}{isbn}</p>\n    <p class=\"notes\">{notes}</p>\n    <h2>Details</h2>\n    <dl class=\"info\">{info}</dl>\n    <h2>Availability</h2>\n    {copies}\n    <h2>Subjects</h2>\n    <p>{topics}</p>\n    <h2>File attachment</h2>\n    <p class=\"muted\">No digital file attached.</p>\n  </div>\n</article>\n",
        back = escape(&back),
        cover = escape(&cover),
        title = escape(&biblio.title),
        gmd = escape(biblio.gmd_name.as_deref().unwrap_or("Collection")),
        authors = escape(if authors.is_empty() { "—" } else { &authors }),
        publisher = biblio
            .publisher
            .as_ref()
            .map(|p| escape(p))
            .unwrap_or_default(),
        year = biblio
            .publish_year
            .as_ref()
            .map(|y| format!(" · {}", escape(y)))
            .unwrap_or_default(),
        isbn = biblio
            .isbn
            .as_ref()
            .map(|i| format!(" · ISBN {}", escape(i)))
            .unwrap_or_default(),
        notes = escape(&notes),
        info = info.join(""),
        copies = copies,
        topics = topics,
    );

    Response::html(200, layout(&state.config, &biblio.title, "search", &content))
}

fn member(state: &AppState) -> Response {
    let top = state
        .store
        .top_members(crate::date::Date::today().year, 5)
        .unwrap_or_default();
    let mut rows = String::new();
    for member in &top {
        rows.push_str(&format!(
            "<tr><td><strong>{}</strong></td><td>{}</td><td>{}</td><td>{}</td></tr>\n",
            escape(&member.name),
            escape(member.member_type.as_deref().unwrap_or("—")),
            member.total,
            member.total_title,
        ));
    }

    let content = format!(
        "<section class=\"page-head\">\n  <p class=\"eyebrow\">Member services</p>\n  <h1>Your library account</h1>\n  <p>Sign-in is not part of this phase of the rewrite. The catalog, search and REST API are already running from Rust.</p>\n</section>\n\
         <div class=\"notice\"><strong>What is ported:</strong> the public OPAC (home, search, detail, legacy <code>index.php</code> URLs) and all fifteen REST API v1 routes. <strong>What is next:</strong> member sign-in, loans and reserves, then the admin modules. See <code>docs/rust-port.md</code>.</div>\n\
         <section class=\"section\"><div class=\"section-head\"><h2>Top borrowers this year</h2><a href=\"/api/member/top\">JSON endpoint →</a></div>\n  <table class=\"copies\">\n    <thead><tr><th>Member</th><th>Type</th><th>Loans</th><th>Distinct titles</th></tr></thead>\n    <tbody>{rows}</tbody>\n  </table>\n</section>\n",
        rows = rows,
    );
    Response::html(200, layout(&state.config, "Member area", "member", &content))
}

fn legacy(request: &Request, state: &AppState) -> Response {
    match request.query_or("p", "") {
        "show_detail" => detail(request, state, request.query_or("id", "")),
        "member" => member(state),
        _ => {
            if request.query("search").is_some()
                || request.query("keywords").is_some()
                || request.query("title").is_some()
                || request.query("author").is_some()
                || request.query("subject").is_some()
                || request.query("isbn").is_some()
            {
                search(request, state, true)
            } else {
                home(request, state)
            }
        }
    }
}

fn build_query(request: &Request, legacy: bool) -> (SearchQuery, AdvancedFields) {
    let value = |name: &str| request.query(name).unwrap_or("").trim().to_string();
    let keywords = if legacy {
        request.query("keywords").unwrap_or("").trim().to_string()
    } else {
        request
            .query("q")
            .or_else(|| request.query("keywords"))
            .unwrap_or("")
            .trim()
            .to_string()
    };

    let page = request
        .query("page")
        .and_then(|p| p.parse::<usize>().ok())
        .unwrap_or(1)
        .max(1);
    let per_page = request
        .query("per_page")
        .or_else(|| request.query("result_num"))
        .and_then(|p| p.parse::<usize>().ok())
        .unwrap_or(10)
        .clamp(1, 50);

    let mut query = SearchQuery {
        keywords,
        title: value("title"),
        author: value("author"),
        subject: value("subject"),
        isbn: value("isbn"),
        publisher: value("publisher"),
        year: value("year"),
        gmd: value("gmd"),
        coll_type: value("coll_type"),
        page,
        per_page,
        sort: SortField::parse(request.query("sort")),
        direction: SortDir::parse(request.query("dir")),
    };
    if legacy && query.sort == SortField::Updated && request.query("sort").is_none() {
        query.sort = SortField::Updated;
    }
    let advanced = AdvancedFields {
        title: query.title.clone(),
        author: query.author.clone(),
        subject: query.subject.clone(),
        isbn: query.isbn.clone(),
    };
    (query, advanced)
}

pub fn error_page(state: &AppState, message: &str) -> Response {
    let content = format!(
        "<section class=\"page-head\"><p class=\"eyebrow\">Something went wrong</p><h1>The catalog is unavailable</h1></section>\
         <div class=\"notice\"><strong>Backend error.</strong> {}</div>",
        escape(message)
    );
    Response::html(500, layout(&state.config, "Error", "", &content))
}

pub fn not_found_page(state: &AppState) -> Response {
    let content = "<section class=\"page-head\"><p class=\"eyebrow\">404</p><h1>Page not found</h1><p>The page or record you asked for is not in this catalog.</p></section>\
         <p><a class=\"btn btn-primary\" href=\"/\">Back to the catalog</a></p>";
    Response::html(404, layout(&state.config, "Not found", "", content))
}
