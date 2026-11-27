//! REST API v1, a route-for-route port of `api/v1/`.
//!
//! The JSON shapes are intentionally identical to the PHP controllers, including
//! the bits that look like accidents: every value that comes out of mysqli is a
//! string, so `{"data":"15"}` and `{"total":"3"}` stay strings. The behavior
//! gate compares against exactly these bytes.

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

use crate::http::{url_decode, url_encode, Request, Response};
use crate::json::Json;
use crate::models::*;
use crate::store::{Store, StoreError};

/// Five hours, the TTL of `api/v1/helpers/Cache.php`.
pub const CACHE_TTL_SECS: u64 = 18_000;

pub struct Cache {
    entries: Mutex<HashMap<String, (Instant, String)>>,
    ttl: Duration,
}

impl Cache {
    pub fn new(ttl_secs: u64) -> Cache {
        Cache {
            entries: Mutex::new(HashMap::new()),
            ttl: Duration::from_secs(ttl_secs),
        }
    }

    pub fn get(&self, name: &str) -> Option<String> {
        if self.ttl.is_zero() {
            return None;
        }
        let mut entries = self.entries.lock().unwrap_or_else(|p| p.into_inner());
        let (created, value) = entries.get(name)?;
        if created.elapsed() > self.ttl {
            entries.remove(name);
            return None;
        }
        Some(value.clone())
    }

    pub fn set(&self, name: &str, value: String) {
        if self.ttl.is_zero() {
            return;
        }
        let mut entries = self.entries.lock().unwrap_or_else(|p| p.into_inner());
        entries.insert(name.to_string(), (Instant::now(), value));
    }
}

pub struct ApiContext<'a> {
    pub store: &'a dyn Store,
    pub cache: &'a Cache,
    pub http_cache_lifetime: u32,
    pub popular_limit: usize,
}

/// The `Image` trait in `api/v1/helpers/Image.php`.
///
/// Note the double `urlencode`: PHP encodes the file name, then encodes the
/// whole path again when it builds the query string.
pub fn image_path(image: Option<&str>, path: &str) -> String {
    let image = image.unwrap_or("");
    let encoded_image = url_encode(image);
    let images_loc = format!("images/{}/{}", path, encoded_image);
    let has_extension = std::path::Path::new(&images_loc)
        .extension()
        .map(|ext| !ext.is_empty())
        .unwrap_or(false);
    if has_extension {
        format!(
            "./lib/minigalnano/createthumb.php?filename={}&width=120",
            url_encode(&images_loc)
        )
    } else {
        "./lib/minigalnano/createthumb.php?filename=images%2Fdefault%2Fimage.png&width=120"
            .to_string()
    }
}

pub fn handle(request: &Request, ctx: &ApiContext) -> Response {
    let segments: Vec<String> = request
        .path
        .trim_start_matches('/')
        .split('/')
        .filter(|s| !s.is_empty())
        .map(|s| url_decode(s))
        .collect();

    // `/api` and `/api/` are the home route.
    if segments.len() <= 1 {
        return finish(request, ctx, home());
    }

    let response = match segments[1].as_str() {
        "biblio" => match segments.get(2).map(|s| s.as_str()) {
            Some("popular") => biblio_popular(ctx),
            Some("latest") => biblio_latest(ctx),
            Some("total") if segments.get(3).map(|s| s.as_str()) == Some("all") => {
                biblio_total(ctx)
            }
            Some("gmd") => segments
                .get(3)
                .map(|gmd| biblio_by_gmd(ctx, gmd))
                .unwrap_or_else(|| bad_request("missing gmd")),
            Some("coll_type") => segments
                .get(3)
                .map(|ct| biblio_by_coll_type(ctx, ct))
                .unwrap_or_else(|| bad_request("missing collection type")),
            _ => not_found(),
        },
        "subject" => match segments.get(2).map(|s| s.as_str()) {
            Some("popular") => subject_popular(ctx),
            Some("latest") => subject_latest(ctx),
            _ => not_found(),
        },
        "member" => match segments.get(2).map(|s| s.as_str()) {
            Some("top") => member_top(ctx),
            _ => not_found(),
        },
        "item" => match segments.get(2).map(|s| s.as_str()) {
            Some("total") => match segments.get(3).map(|s| s.as_str()) {
                Some("all") => item_total(ctx, Total::All),
                Some("lent") => item_total(ctx, Total::Lent),
                Some("available") => item_total(ctx, Total::Available),
                _ => not_found(),
            },
            _ => not_found(),
        },
        "loan" => match segments.get(2).map(|s| s.as_str()) {
            Some("summary") => match segments.get(3) {
                Some(date) => loan_summary_date(ctx, date),
                None => loan_summary(ctx),
            },
            Some("getdate") => segments
                .get(3)
                .map(|date| loan_dates(ctx, date))
                .unwrap_or_else(|| bad_request("missing start date")),
            _ => not_found(),
        },
        _ => not_found(),
    };

    finish(request, ctx, response)
}

fn finish(request: &Request, ctx: &ApiContext, response: Response) -> Response {
    let wants_cache = request
        .header("slims-http-cache")
        .map(|_| true)
        .unwrap_or_else(|| request.header("SLiMS-Http-Cache").is_some());
    if wants_cache && ctx.http_cache_lifetime > 0 {
        response.with_header(
            "Cache-Control",
            &format!("max-age={}", ctx.http_cache_lifetime),
        )
    } else {
        response.with_header("Cache-Control", "no-store")
    }
}

fn home() -> Response {
    let mut body = Json::obj();
    body.set("error", Json::Bool(false));
    body.set("message", Json::str("Sugeng rawuh is selamat datang."));
    Response::json(200, &body)
}

fn biblio_popular(ctx: &ApiContext) -> Response {
    if let Some(cached) = ctx.cache.get("biblio_popular") {
        return Response::new(200)
            .with_header("Content-Type", "application/json")
            .with_body(cached.into_bytes());
    }
    match ctx.store.biblio_popular(ctx.popular_limit) {
        Ok(items) => {
            let mut array = Json::arr();
            for popular in items {
                let mut item = Json::obj();
                item.set("biblio_id", Json::str(popular.biblio.id.to_string()));
                item.set("title", Json::str(popular.biblio.title.clone()));
                item.set("image", Json::str(image_path(popular.biblio.image.as_deref(), "docs")));
                if let Some(total) = popular.total {
                    item.set("total", Json::str(total.to_string()));
                }
                if let Json::Arr(entries) = &mut array {
                    entries.push(item);
                }
            }
            let body = array.to_string();
            ctx.cache.set("biblio_popular", body.clone());
            Response::new(200)
                .with_header("Content-Type", "application/json")
                .with_body(body.into_bytes())
        }
        Err(error) => internal_error(error),
    }
}

fn biblio_latest(ctx: &ApiContext) -> Response {
    match ctx.store.biblio_latest(6) {
        Ok(items) => {
            let mut array = Json::arr();
            for biblio in items {
                let mut item = Json::obj();
                item.set("biblio_id", Json::str(biblio.id.to_string()));
                item.set("title", Json::str(biblio.title));
                item.set("image", Json::str(image_path(biblio.image.as_deref(), "docs")));
                if let Json::Arr(entries) = &mut array {
                    entries.push(item);
                }
            }
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

fn biblio_total(ctx: &ApiContext) -> Response {
    match ctx.store.biblio_total() {
        Ok(total) => {
            let mut body = Json::obj();
            body.set("data", Json::str(total.to_string()));
            Response::json(200, &body)
        }
        Err(error) => internal_error(error),
    }
}

fn biblio_by_gmd(ctx: &ApiContext, gmd: &str) -> Response {
    match ctx.store.biblio_by_gmd(gmd, 3) {
        Ok(items) => {
            let mut array = Json::arr();
            for biblio in items {
                if let Json::Arr(entries) = &mut array {
                    entries.push(biblio_summary(&biblio));
                }
            }
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

fn biblio_by_coll_type(ctx: &ApiContext, coll_type: &str) -> Response {
    match ctx.store.biblio_by_coll_type(coll_type, 3) {
        Ok(items) => {
            let mut array = Json::arr();
            for biblio in items {
                if let Json::Arr(entries) = &mut array {
                    entries.push(biblio_summary(&biblio));
                }
            }
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

fn biblio_summary(biblio: &Biblio) -> Json {
    let mut item = Json::obj();
    item.set("biblio_id", Json::str(biblio.id.to_string()));
    item.set("title", Json::str(biblio.title.clone()));
    item.set("image", Json::str(image_path(biblio.image.as_deref(), "docs")));
    item.set(
        "notes",
        biblio
            .notes
            .clone()
            .map(Json::Str)
            .unwrap_or(Json::Null),
    );
    item
}

fn subject_popular(ctx: &ApiContext) -> Response {
    if let Some(cached) = ctx.cache.get("subject_popular") {
        if cached != "null" {
            return Response::new(200)
                .with_header("Content-Type", "application/json")
                .with_body(cached.into_bytes());
        }
    }
    match ctx.store.subject_popular(crate::date::Date::today().year, 5) {
        Ok(topics) => {
            let mut array = Json::arr();
            for topic in topics {
                if let Json::Arr(entries) = &mut array {
                    entries.push(Json::str(topic));
                }
            }
            let body = array.to_string();
            ctx.cache.set("subject_popular", body.clone());
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

fn subject_latest(ctx: &ApiContext) -> Response {
    match ctx.store.subject_latest(5) {
        Ok(topics) => {
            let mut array = Json::arr();
            for topic in topics {
                if let Json::Arr(entries) = &mut array {
                    entries.push(Json::str(topic));
                }
            }
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

fn member_top(ctx: &ApiContext) -> Response {
    match ctx.store.top_members(crate::date::Date::today().year, 3) {
        Ok(members) => {
            let mut array = Json::arr();
            for member in members {
                let mut item = Json::obj();
                item.set("name", Json::str(member.name));
                item.set("type", member.member_type.map(Json::Str).unwrap_or(Json::Null));
                item.set("image", Json::str(member.image));
                item.set("total", Json::str(member.total.to_string()));
                item.set("total_title", Json::int(member.total_title as i64));
                item.set("order", Json::int(member.order));
                if let Json::Arr(entries) = &mut array {
                    entries.push(item);
                }
            }
            Response::json(200, &array)
        }
        Err(error) => internal_error(error),
    }
}

enum Total {
    All,
    Lent,
    Available,
}

fn item_total(ctx: &ApiContext, which: Total) -> Response {
    let result = match which {
        Total::All => ctx.store.item_total(),
        Total::Lent => ctx.store.item_lent(),
        Total::Available => ctx.store.item_available(),
    };
    match result {
        Ok(total) => {
            let mut body = Json::obj();
            body.set("data", Json::str(total.to_string()));
            Response::json(200, &body)
        }
        Err(error) => internal_error(error),
    }
}

fn loan_summary(ctx: &ApiContext) -> Response {
    match ctx.store.loan_summary() {
        Ok(summary) => {
            let mut data = Json::obj();
            data.set("total", Json::str(summary.total.to_string()));
            data.set("new", Json::str(summary.new.to_string()));
            data.set("return", Json::str(summary.returned.to_string()));
            data.set("extend", Json::str(summary.extended.to_string()));
            data.set("overdue", Json::str(summary.overdue.to_string()));
            let mut body = Json::obj();
            body.set("data", data);
            Response::json(200, &body)
        }
        Err(error) => internal_error(error),
    }
}

fn loan_dates(ctx: &ApiContext, start: &str) -> Response {
    let Some(start) = crate::date::Date::parse(start) else {
        return bad_request("invalid date, expected YYYY-MM-DD");
    };
    match ctx.store.loan_dates(start) {
        Ok(points) => {
            let mut raw = Json::arr();
            let mut dates = Json::arr();
            let mut text = Vec::new();
            for point in points {
                if let Json::Arr(entries) = &mut raw {
                    entries.push(Json::str(point.day_month.clone()));
                }
                if let Json::Arr(entries) = &mut dates {
                    entries.push(Json::str(point.iso.clone()));
                }
                text.push(point.day_month);
            }
            let mut body = Json::obj();
            body.set("raw", raw);
            body.set("date", dates);
            body.set("text", Json::str(text.join(", ")));
            Response::json(200, &body)
        }
        Err(error) => internal_error(error),
    }
}

fn loan_summary_date(ctx: &ApiContext, date: &str) -> Response {
    let Some(date) = crate::date::Date::parse(date) else {
        return bad_request("invalid date, expected YYYY-MM-DD");
    };
    match ctx.store.loan_dates(date) {
        Ok(points) => {
            let dates: Vec<crate::date::Date> = points
                .iter()
                .filter_map(|point| crate::date::Date::parse(&point.iso))
                .collect();
            match ctx.store.loan_summary_for(&dates) {
                Ok(summary) => {
                    let mut data = Json::obj();
                    data.set("loan", ints(&summary.loan));
                    data.set("return", ints(&summary.returned));
                    data.set("extend", ints(&summary.extended));
                    let mut body = Json::obj();
                    body.set("data", data);
                    Response::json(200, &body)
                }
                Err(error) => internal_error(error),
            }
        }
        Err(error) => internal_error(error),
    }
}

fn ints(values: &[i64]) -> Json {
    let mut array = Json::arr();
    for value in values {
        if let Json::Arr(entries) = &mut array {
            entries.push(Json::int(*value));
        }
    }
    array
}

fn bad_request(message: &str) -> Response {
    let mut body = Json::obj();
    body.set("error", Json::Bool(true));
    body.set("message", Json::str(message));
    Response::json(400, &body)
}

fn not_found() -> Response {
    let mut body = Json::obj();
    body.set("error", Json::Bool(true));
    body.set("message", Json::str("route not found"));
    Response::json(404, &body)
}

fn internal_error(error: StoreError) -> Response {
    let mut body = Json::obj();
    body.set("error", Json::Bool(true));
    body.set("message", Json::str(error.to_string()));
    Response::json(500, &body)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Config;
    use crate::store::memory::MemoryStore;

    fn context<'a>(store: &'a MemoryStore, cache: &'a Cache, config: &'a Config) -> ApiContext<'a> {
        ApiContext {
            store,
            cache,
            http_cache_lifetime: config.http_cache_lifetime,
            popular_limit: config.popular_limit,
        }
    }

    fn request(path: &str) -> Request {
        Request::new("GET", path)
    }

    fn body(response: &Response) -> Json {
        crate::json::parse(&String::from_utf8(response.body.clone()).unwrap()).unwrap()
    }

    #[test]
    fn home_matches_php() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/"), &ctx);
        assert_eq!(response.status, 200);
        assert_eq!(
            String::from_utf8(response.body).unwrap(),
            r#"{"error":false,"message":"Sugeng rawuh is selamat datang."}"#
        );
    }

    #[test]
    fn image_path_matches_php() {
        assert_eq!(
            image_path(Some("php5_dummies.jpg"), "docs"),
            "./lib/minigalnano/createthumb.php?filename=images%2Fdocs%2Fphp5_dummies.jpg&width=120"
        );
        assert_eq!(
            image_path(None, "docs"),
            "./lib/minigalnano/createthumb.php?filename=images%2Fdefault%2Fimage.png&width=120"
        );
    }

    #[test]
    fn popular_has_string_fields() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/biblio/popular"), &ctx);
        let json = body(&response);
        let first = json.as_array().unwrap()[0].as_object().unwrap();
        assert_eq!(first[0].0, "biblio_id");
        assert!(matches!(first[0].1, Json::Str(_)));
        assert!(matches!(first[3].1, Json::Str(_)));
    }

    #[test]
    fn totals_are_strings_like_mysqli() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/biblio/total/all"), &ctx);
        assert_eq!(String::from_utf8(response.body).unwrap(), r#"{"data":"15"}"#);

        let response = handle(&request("/api/item/total/all"), &ctx);
        assert_eq!(String::from_utf8(response.body).unwrap(), r#"{"data":"10"}"#);
    }

    #[test]
    fn loan_endpoints_agree_with_the_demo_circulation() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/loan/summary"), &ctx);
        let json = body(&response);
        let data = json.get("data").unwrap();
        assert_eq!(data.get("total").unwrap().as_str(), Some("12"));
        assert_eq!(data.get("new").unwrap().as_str(), Some("7"));
        assert_eq!(data.get("return").unwrap().as_str(), Some("5"));
        assert_eq!(data.get("extend").unwrap().as_str(), Some("1"));
        assert_eq!(data.get("overdue").unwrap().as_str(), Some("2"));
    }

    #[test]
    fn cache_short_circuits_popular() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(60);
        let ctx = context(&store, &cache, &config);
        let first = handle(&request("/api/biblio/popular"), &ctx);
        let second = handle(&request("/api/biblio/popular"), &ctx);
        assert_eq!(first.body, second.body);
        assert!(cache.get("biblio_popular").is_some());
    }

    #[test]
    fn unknown_route_is_json_404() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/nope"), &ctx);
        assert_eq!(response.status, 404);
        assert!(body(&response).get("error").unwrap().as_bool().unwrap());
    }

    #[test]
    fn invalid_date_is_400() {
        let config = Config::default();
        let store = MemoryStore::load(&config.root, true);
        let cache = Cache::new(0);
        let ctx = context(&store, &cache, &config);
        let response = handle(&request("/api/loan/getdate/22-11-2026"), &ctx);
        assert_eq!(response.status, 400);
    }
}
