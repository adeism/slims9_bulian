//! Read-only public SLiMS content. No CMS writes or raw stored HTML execution.
use crate::app::AppState;
use crate::http::{url_encode, Request, Response};
use crate::store::{StoreError, StoreResult};
use crate::templates::{escape, layout};

pub fn public_path(path: &str) -> bool {
    !path.is_empty() && path.len() <= 20
        && path.as_bytes()[0].is_ascii_alphanumeric()
        && path.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_' || b == b'-')
        && !path.starts_with("admin") && path != "headerinfo"
}

pub fn validate_news(keywords: &str, offset: usize) -> StoreResult<()> {
    if keywords.len() > 200 || keywords.chars().any(char::is_control) || offset > 100_000 {
        return Err(StoreError::Db("news query out of range".into()));
    }
    Ok(())
}

/// Only exact, attribute-free formatting tags become markup. Everything else,
/// including entities, links, images, comments and script tags, is escaped.
/// This is NOT an HTML sanitizer or PHP CKEditor rendering parity.
pub fn formatting(text: &str) -> String {
    const TAGS: &[&str] = &["p", "br", "strong", "em", "b", "i", "ul", "ol", "li", "h2", "h3", "blockquote"];
    let mut out = String::new();
    let mut rest = text;
    while let Some(start) = rest.find('<') {
        out.push_str(&escape(&rest[..start]));
        rest = &rest[start..];
        if let Some(end) = rest.find('>') {
            let token = &rest[1..end];
            let name = token.strip_prefix('/').unwrap_or(token);
            if TAGS.contains(&name) {
                out.push_str(&rest[..=end]);
            } else {
                out.push_str(&escape(&rest[..=end]));
            }
            rest = &rest[end + 1..];
        } else {
            out.push_str(&escape(rest));
            return out;
        }
    }
    out.push_str(&escape(rest));
    out
}

fn response(state: &AppState, status: u16, title: &str, body: &str) -> Response {
    Response::html(status, layout(&state.config, title, "news", body))
        .with_header("Cache-Control", "no-store")
        .with_header("X-Content-Type-Options", "nosniff")
        .with_header("Content-Security-Policy", "default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
}

pub fn handle(request: &Request, state: &AppState, path: Option<&str>) -> Response {
    if !matches!(request.method.as_str(), "GET" | "HEAD") {
        return response(state, 405, "Method not allowed", "<h1>Read-only content</h1>")
            .with_header("Allow", "GET, HEAD");
    }
    let mut seen = std::collections::HashSet::new();
    if request.query.iter().any(|(key, _)| !seen.insert(key.as_str())) {
        return response(state, 400, "Invalid query", "<h1>Duplicate query parameter</h1>");
    }
    if let Some(path) = path {
        if !public_path(path) { return response(state, 404, "Not found", "<h1>Page not found</h1>"); }
        return match state.store.public_content(path) {
            Ok(Some(row)) if row.body.len() <= 256 * 1024 && row.title.len() <= 4096 => {
                let body = format!("<section class=\"page-head\"><h1>{}</h1></section><article>{}</article>",
                                   escape(&row.title), formatting(&row.body));
                response(state, 200, &row.title, &body)
            }
            Ok(Some(_)) => response(state, 413, "Content too large", "<h1>Content exceeds display limit</h1>"),
            Ok(None) => response(state, 404, "Not found", "<h1>Page not found</h1>"),
            Err(_) => response(state, 503, "Unavailable", "<h1>Content is temporarily unavailable</h1>"),
        };
    }
    let keywords = request.query_or("keywords", "").trim();
    let page = request.query_or("page", "1").parse::<usize>().ok();
    let Some(page) = page.filter(|p| (1..=10_001).contains(p)) else {
        return response(state, 400, "Invalid page", "<h1>Invalid news page</h1>");
    };
    let offset = (page - 1) * 10;
    if validate_news(keywords, offset).is_err() {
        return response(state, 400, "Invalid query", "<h1>Invalid news query</h1>");
    }
    let rows = match state.store.public_news(keywords, offset) {
        Ok(rows) => rows,
        Err(_) => return response(state, 503, "Unavailable", "<h1>News is temporarily unavailable</h1>"),
    };
    if rows.iter().any(|r| r.title.len() > 4096 || r.body.len() > 256 * 1024) {
        return response(state, 413, "Content too large", "<h1>Content exceeds display limit</h1>");
    }
    let mut body = format!("<section class=\"page-head\"><h1>Library News</h1></section><form action=\"/news\" method=\"get\"><label>Search news <input name=\"keywords\" maxlength=\"200\" value=\"{}\"></label><button>Search</button></form>", escape(keywords));
    if rows.is_empty() { body.push_str("<p>No published news found.</p>"); }
    for row in rows.iter().take(10) {
        let summary: String = row.body.chars().take(300).collect();
        body.push_str(&format!("<article><h2><a href=\"/pages/{}\">{}</a></h2><p>{}</p><p>{}</p></article>",
            url_encode(&row.path), escape(&row.title), escape(row.published.as_deref().unwrap_or(&row.updated)), escape(&summary)));
    }
    body.push_str("<nav aria-label=\"News pages\">");
    if page > 1 { body.push_str(&format!("<a href=\"/news?page={}&amp;keywords={}\">Previous</a> ", page - 1, url_encode(keywords))); }
    if rows.len() > 10 && page < 10_001 { body.push_str(&format!("<a href=\"/news?page={}&amp;keywords={}\">Next</a>", page + 1, url_encode(keywords))); }
    body.push_str("</nav>");
    response(state, 200, "Library News", &body)
}
