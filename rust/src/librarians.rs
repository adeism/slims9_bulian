//! Public librarian directory; read-only projection of PHP user types 1 and 2.
use crate::app::AppState;
use crate::http::{Request, Response};
use crate::templates::{escape, layout};

/// Local raster basenames only. No SVG, URLs, traversal, escapes or attributes.
pub fn portrait_url(image: Option<&str>) -> String {
    let image = image.unwrap_or("");
    let valid = image.len() <= 250 && image.rsplit_once('.').map_or(false, |(stem, ext)| {
        !stem.is_empty() && stem.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
            && matches!(ext.to_ascii_lowercase().as_str(), "png" | "jpg" | "jpeg" | "gif" | "webp")
    });
    format!("/images/persons/{}", if valid { image } else { "person.png" })
}

fn response(state: &AppState, status: u16, body: &str) -> Response {
    Response::html(status, layout(&state.config, "Profile of our Librarian", "librarians", body))
        .with_header("Cache-Control", "no-store")
        .with_header("X-Content-Type-Options", "nosniff")
        .with_header("Referrer-Policy", "no-referrer")
        .with_header("Content-Security-Policy", "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
}

pub fn handle(request: &Request, state: &AppState) -> Response {
    if !matches!(request.method.as_str(), "GET" | "HEAD") {
        return response(state, 405, "<h1>Read-only librarian directory</h1>")
            .with_header("Allow", "GET, HEAD");
    }
    // No user selector, search, role override or pagination controls. The only
    // supported query is the legacy dispatch key, exactly once when present.
    if request.query.len() > 1 || request.query.iter().any(|(k, v)| k != "p" || v != "librarian") {
        return response(state, 400, "<h1>Invalid directory query</h1>");
    }
    render(state, state.store.public_librarians())
}

fn render(state: &AppState, result: crate::store::StoreResult<Vec<crate::models::PublicLibrarian>>) -> Response {
    let profiles = match result {
        Ok(profiles) => profiles,
        Err(_) => return response(state, 503, "<h1>Librarian directory is temporarily unavailable</h1>"),
    };
    if profiles.len() > 20 || profiles.iter().any(|p| p.name.len() > 4096
            || p.email.as_ref().map_or(false, |s| s.len() > 4096)
            || p.image.as_ref().map_or(false, |s| s.len() > 1024)) {
        return response(state, 413, "<h1>Directory exceeds display limits</h1>");
    }
    let mut body = String::from("<section class=\"page-head\"><h1>Profile of our Librarian</h1></section>");
    if profiles.is_empty() { body.push_str("<p>No librarian data yet.</p>"); }
    for p in profiles {
        body.push_str(&format!(
            "<article class=\"section\"><img src=\"{}\" alt=\"{}\" width=\"96\" height=\"96\" loading=\"lazy\"><h2>{}</h2><dl><dt>Position</dt><dd>{}</dd><dt>E-Mail</dt><dd>{}</dd></dl></article>",
            portrait_url(p.image.as_deref()), escape(&p.name), escape(&p.name),
            p.role.label(), escape(p.email.as_deref().unwrap_or("Not provided"))));
    }
    response(state, 200, &body)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Config;
    use crate::models::{LibrarianRole, PublicLibrarian};
    use crate::store::StoreError;
    #[test]
    fn backend_failure_never_exposes_details_or_empty_success() {
        let state = AppState::new(Config::default()).unwrap();
        let response = render(&state, Err(StoreError::Db("credential_secret@host".into())));
        assert_eq!(response.status, 503);
        let text = String::from_utf8(response.body).unwrap();
        assert!(!text.contains("credential_secret"));
        assert!(!text.contains("No librarian data yet"));
    }
    #[test]
    fn renderer_rejects_store_overflow_and_fields_without_partial_profiles() {
        let state = AppState::new(Config::default()).unwrap();
        let profile = PublicLibrarian { name: "DO_NOT_RENDER".into(), email: None,
            image: None, role: LibrarianRole::Librarian };
        let mut oversized = profile.clone(); oversized.email = Some("x".repeat(4097));
        for profiles in [vec![profile; 21], vec![oversized]] {
            let response = render(&state, Ok(profiles));
            assert_eq!(response.status, 413);
            assert!(!String::from_utf8(response.body).unwrap().contains("DO_NOT_RENDER"));
        }
    }
}
