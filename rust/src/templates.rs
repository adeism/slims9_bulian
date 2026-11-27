//! Server-rendered HTML for the OPAC.
//!
//! The PHP app renders `template/default/*.php` with Bootstrap, Vue and a
//! minigalnano thumbnail endpoint. This module keeps the same information
//! architecture (search, result list with facets, detail with copies) and
//! renders it from Rust with one small stylesheet and no client framework.

use crate::config::Config;
use crate::http::url_encode;
use crate::models::{Biblio, Facet, PopularBiblio, SearchResult};

pub fn escape(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    for ch in input.chars() {
        match ch {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#39;"),
            c => out.push(c),
        }
    }
    out
}

/// Percent-encode a path segment, keeping `/` intact.
pub fn path_encode(input: &str) -> String {
    let mut out = String::new();
    for byte in input.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' | b'/' => {
                out.push(byte as char)
            }
            _ => out.push_str(&format!("%{:02X}", byte)),
        }
    }
    out
}

/// Cover URL for the OPAC. The REST API keeps PHP's minigalnano URL; the HTML
/// uses the file directly so it works without PHP.
pub fn cover_url(image: Option<&str>) -> String {
    match image {
        Some(image) if !image.trim().is_empty() && has_extension(image) => {
            format!("/images/docs/{}", path_encode(image))
        }
        _ => "/images/default/image.png".to_string(),
    }
}

fn has_extension(name: &str) -> bool {
    std::path::Path::new(name)
        .extension()
        .map(|ext| !ext.is_empty())
        .unwrap_or(false)
}

pub fn query_string(params: &[(&str, &str)]) -> String {
    let mut out = String::new();
    for (key, value) in params {
        if value.trim().is_empty() {
            continue;
        }
        if !out.is_empty() {
            out.push('&');
        }
        out.push_str(&url_encode(key));
        out.push('=');
        out.push_str(&url_encode(value));
    }
    out
}

pub fn build_url(path: &str, params: &[(&str, &str)]) -> String {
    let query = query_string(params);
    if query.is_empty() {
        path.to_string()
    } else {
        format!("{}?{}", path, query)
    }
}

pub fn layout(config: &Config, title: &str, active: &str, content: &str) -> String {
    let nav = |href: &str, label: &str, key: &str| {
        let class = if active == key { "nav-link active" } else { "nav-link" };
        format!("<a class=\"{}\" href=\"{}\">{}</a>", class, href, escape(label))
    };
    format!(
        "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n\
         <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n\
         <title>{title} · {library}</title>\n\
         <meta name=\"description\" content=\"{subname}\">\n\
         <link rel=\"stylesheet\" href=\"/static/opac.css\">\n\
         <link rel=\"icon\" href=\"/favicon.ico\">\n\
         </head>\n<body>\n\
         <header class=\"site-header\">\n  <div class=\"wrap header-inner\">\n    <a class=\"brand\" href=\"/\">\n      <span class=\"brand-mark\">SL</span>\n      <span class=\"brand-text\"><strong>{library}</strong><small>{subname}</small></span>\n    </a>\n    <nav class=\"nav\">\n      {nav_home}\n      {nav_search}\n      {nav_member}\n    </nav>\n  </div>\n</header>\n\
         <main class=\"wrap main\">\n{content}\n</main>\n\
         <footer class=\"site-footer\">\n  <div class=\"wrap footer-inner\">\n    <p><strong>{library}</strong> · {subname}</p>\n    <p class=\"muted\">Powered by <code>slims9-rs</code>, a Rust rewrite of SLiMS 9 Bulian. OPAC and REST API v1 are running from Rust; the original PHP application stays untouched beside it.</p>\n  </div>\n</footer>\n</body>\n</html>\n",
        title = escape(title),
        library = escape(&config.library_name),
        subname = escape(&config.library_subname),
        content = content,
        nav_home = nav("/", "Home", "home"),
        nav_search = nav("/search", "Catalog", "search"),
        nav_member = nav("/member", "Member area", "member"),
    )
}

pub fn search_form(q: &str, advanced: &AdvancedFields) -> String {
    let field = |name: &str, label: &str, value: &str| {
        format!(
            "<label class=\"field\"><span>{}</span><input type=\"text\" name=\"{}\" value=\"{}\" placeholder=\"Any {}\"></label>",
            escape(label),
            name,
            escape(value),
            escape(&label.to_lowercase())
        )
    };
    format!(
        "<form class=\"search-box\" action=\"/search\" method=\"get\" role=\"search\">\n\
         <div class=\"search-row\">\n  <input class=\"search-input\" type=\"search\" name=\"q\" value=\"{}\" \
         placeholder=\"Search by title, author, subject, ISBN, publisher…\" aria-label=\"Search the catalog\" autofocus>\n\
         <button class=\"btn btn-primary\" type=\"submit\">Search</button>\n</div>\n\
         <details class=\"advanced\">\n  <summary>Advanced search</summary>\n  <div class=\"advanced-grid\">\n    {}\n    {}\n    {}\n    {}\n  </div>\n  <button class=\"btn btn-ghost\" type=\"submit\">Search with these fields</button>\n</details>\n</form>\n",
        escape(q),
        field("title", "Title", &advanced.title),
        field("author", "Author", &advanced.author),
        field("subject", "Subject", &advanced.subject),
        field("isbn", "ISBN / ISSN", &advanced.isbn),
    )
}

#[derive(Clone, Debug, Default)]
pub struct AdvancedFields {
    pub title: String,
    pub author: String,
    pub subject: String,
    pub isbn: String,
}

pub fn biblio_card(biblio: &Biblio) -> String {
    let cover = cover_url(biblio.image.as_deref());
    let authors = biblio.author_line();
    let year = biblio.publish_year.clone().unwrap_or_default();
    format!(
        "<a class=\"book-card\" href=\"/biblio/{id}\">\n  <div class=\"cover\"><img src=\"{cover}\" alt=\"Cover of {title}\" loading=\"lazy\"></div>\n  <div class=\"card-body\">\n    <h3>{title}</h3>\n    <p class=\"authors\">{authors}</p>\n    <p class=\"meta\">{year}{publisher}</p>\n  </div>\n</a>\n",
        id = biblio.id,
        cover = escape(&cover),
        title = escape(&biblio.title),
        authors = escape(if authors.is_empty() { "—" } else { &authors }),
        year = escape(&year),
        publisher = biblio
            .publisher
            .as_ref()
            .map(|p| format!(" · {}", escape(p)))
            .unwrap_or_default(),
    )
}

pub fn result_row(biblio: &Biblio) -> String {
    let cover = cover_url(biblio.image.as_deref());
    let authors = biblio.author_line();
    let availability = if biblio.items.is_empty() {
        "<span class=\"badge badge-muted\">No copy data</span>".to_string()
    } else if biblio.available_count() > 0 {
        format!(
            "<span class=\"badge badge-ok\">{} of {} available</span>",
            biblio.available_count(),
            biblio.item_count()
        )
    } else {
        "<span class=\"badge badge-warn\">All copies on loan</span>".to_string()
    };
    let topics = biblio
        .topics
        .iter()
        .take(3)
        .map(|topic| {
            format!(
                "<a class=\"chip\" href=\"/search?subject={}\">{}</a>",
                url_encode(topic),
                escape(topic)
            )
        })
        .collect::<String>();
    format!(
        "<article class=\"result-row\">\n  <a class=\"result-cover\" href=\"/biblio/{id}\"><img src=\"{cover}\" alt=\"Cover of {title}\" loading=\"lazy\"></a>\n  <div class=\"result-body\">\n    <p class=\"eyebrow\">{gmd}</p>\n    <h3><a href=\"/biblio/{id}\">{title}</a></h3>\n    <p class=\"authors\">{authors}</p>\n    <p class=\"meta\">{publisher}{year}{isbn}</p>\n    <p class=\"meta\">{callnumber}</p>\n    <div class=\"result-foot\">{availability}<span class=\"topics\">{topics}</span></div>\n  </div>\n</article>\n",
        id = biblio.id,
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
        callnumber = biblio
            .call_number
            .as_ref()
            .map(|c| format!("Call number: {}", escape(c)))
            .unwrap_or_default(),
        availability = availability,
        topics = topics,
    )
}

pub fn facet_list(title: &str, facets: &[Facet], param: &str, base: &str, extra: &[(String, String)]) -> String {
    let mut out = format!("<section class=\"facet\"><h2>{}</h2>", escape(title));
    if facets.is_empty() {
        out.push_str("<p class=\"muted\">No filters for this search.</p></section>");
        return out;
    }
    out.push_str("<ul>");
    for facet in facets.iter().take(10) {
        let mut params: Vec<(String, String)> = extra.to_vec();
        params.push((param.to_string(), facet.name.clone()));
        let href = build_url_owned(base, &params);
        out.push_str(&format!(
            "<li><a href=\"{}\">{}<span>{}</span></a></li>",
            escape(&href),
            escape(&facet.name),
            facet.count
        ));
    }
    out.push_str("</ul></section>");
    out
}

pub fn pagination(result: &SearchResult, base: &str, params: &[(String, String)]) -> String {
    let pages = result.total_pages();
    if pages <= 1 {
        return String::new();
    }
    let mut out = String::from("<nav class=\"pagination\" aria-label=\"Result pages\">");
    let window = 2;
    for page in 1..=pages {
        let visible = page <= window || page > pages - window || (page as i64 - result.page as i64).abs() <= 2;
        if !visible {
            if page == window + 1 || page == pages - window {
                out.push_str("<span class=\"ellipsis\">…</span>");
            }
            continue;
        }
        let mut page_params: Vec<(String, String)> = params.to_vec();
        page_params.push(("page".into(), page.to_string()));
        let href = build_url_owned(base, &page_params);
        if page == result.page {
            out.push_str(&format!("<span class=\"page current\">{}</span>", page));
        } else {
            out.push_str(&format!(
                "<a class=\"page\" href=\"{}\">{}</a>",
                escape(&href),
                page
            ));
        }
    }
    out.push_str("</nav>");
    out
}

pub fn stat(value: i64, label: &str) -> String {
    format!(
        "<div class=\"stat\"><strong>{}</strong><span>{}</span></div>",
        value,
        escape(label)
    )
}

pub fn home_popular_row(items: &[PopularBiblio]) -> String {
    let mut out = String::from("<div class=\"grid\">");
    for item in items {
        out.push_str(&biblio_card(&item.biblio));
    }
    out.push_str("</div>");
    out
}

pub fn build_url_owned(path: &str, params: &[(String, String)]) -> String {
    let borrowed: Vec<(&str, &str)> = params.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
    build_url(path, &borrowed)
}
