//! Rust replacement for the combined PHP member loan report. Printable HTML,
//! not server-generated PDF. Caller must authenticate before invoking this module.
use crate::http::Response;
use crate::member::csv_cell;
use crate::models::{MemberLoanReportRow, MemberLoanReportScope, MemberProfile, MAX_MEMBER_REPORT_LOANS};
use crate::templates::escape;

const MAX_REPORT_DATA_BYTES: usize = 512 * 1024;

fn fields(row: &MemberLoanReportRow) -> [&str; 6] {
    let l = &row.loan;
    [if row.returned { "Returned" } else { "Current" }, &l.item_code,
     l.title.as_deref().unwrap_or(""), l.loan_date.as_deref().unwrap_or(""),
     l.due_date.as_deref().unwrap_or(""), l.return_date.as_deref().unwrap_or("")]
}

#[cfg(test)]
pub(crate) fn render(rows: &[MemberLoanReportRow], profile: &MemberProfile, library: &str, csv: bool) -> Response {
    render_scoped(rows, profile, library, csv, MemberLoanReportScope::All, false)
}

pub(crate) fn render_scoped(rows: &[MemberLoanReportRow], profile: &MemberProfile, library: &str, csv: bool, scope: MemberLoanReportScope, download_html: bool) -> Response {
    if rows.iter().any(|row| !scope.includes(row.returned)) {
        return Response::text(503, "Loan report scope mismatch; no report generated.");
    }
    // Bound raw input before HTML expansion/CSV quoting; never emit a partial
    // report or silently omit the sentinel/oversized rows.
    let profile_fields = [
        ("Member Name", profile.name.as_str()), ("Member ID", profile.id.as_str()),
        ("Member Email", profile.email.as_deref().unwrap_or("")), ("Member Type", profile.member_type.as_deref().unwrap_or("")),
        ("Register Date", profile.register_date.as_deref().unwrap_or("")), ("Expiry Date", profile.expiry_date.as_deref().unwrap_or("")),
    ];
    let bytes = rows.iter().flat_map(fields).chain(profile_fields.iter().map(|(_, value)| *value)).map(str::len)
        .try_fold(library.len(), |n, size| n.checked_add(size));
    if rows.len() > MAX_MEMBER_REPORT_LOANS || bytes.map_or(true, |n| n > MAX_REPORT_DATA_BYTES) {
        return Response::text(413, "Loan report exceeds 1000 loans or the report size limit. Use paginated loan/history views; no partial report was generated.");
    }
    let scope_name = scope.query_value();
    let (title, csv_filename, html_filename) = match scope {
        MemberLoanReportScope::All => ("Member loan report", "member-loan-report.csv", "member-loan-report.html"),
        MemberLoanReportScope::Current => ("Current loan report", "member-current-loans.csv", "current_loan.html"),
        MemberLoanReportScope::History => ("Loan history report", "member-loan-history.csv", "loan_history.html"),
    };
    let headings = ["Status", "Item Code", "Title", "Loan Date", "Due Date", "Return Date"];
    if csv {
        let mut output = String::from("\u{feff}");
        for values in std::iter::once(headings).chain(rows.iter().map(fields)) {
            output.push_str(&values.map(csv_cell).join(","));
            output.push_str("\r\n");
        }
        return Response::new(200).with_header("Content-Type", "text/csv; charset=utf-8")
            .with_header("Content-Disposition", &format!("attachment; filename=\"{csv_filename}\""))
            .with_body(output.into_bytes());
    }
    let mut profile_html = String::from("<table><tbody>");
    for (label, value) in profile_fields { profile_html.push_str(&format!("<tr><th scope=\"row\">{label}</th><td>{}</td></tr>", escape(value))); }
    profile_html.push_str("</tbody></table>");
    let mut tables = String::new();
    for returned in [false, true] {
        if !scope.includes(returned) { continue; }
        let end = if returned { 6 } else { 5 };
        let count = rows.iter().filter(|r| r.returned == returned).count();
        tables.push_str(&format!("<h2>{} ({count})</h2><table><thead><tr>", if returned { "Returned loans" } else { "Current loans" }));
        for heading in &headings[1..end] { tables.push_str(&format!("<th scope=\"col\">{heading}</th>")); }
        tables.push_str("</tr></thead><tbody>");
        for row in rows.iter().filter(|r| r.returned == returned) {
            tables.push_str("<tr>");
            for value in &fields(row)[1..end] { tables.push_str(&format!("<td>{}</td>", escape(value))); }
            tables.push_str("</tr>");
        }
        if count == 0 { tables.push_str(&format!("<tr><td colspan=\"{}\">No loans.</td></tr>", end - 1)); }
        tables.push_str("</tbody></table>");
    }
    let response = Response::html(200, format!("<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{title}</title><link rel=\"stylesheet\" href=\"/static/member-report.css\"></head><body><h1>{title}</h1><p>{}</p>{profile_html}<p>Selected loan records, read together. Dates are stored values, not a fine calculation. This is a point-in-time read, not a signed or certified statement.</p><p class=\"screen-only\">Use your browser's Print / Save as PDF command. This service does not generate a PDF file. <a href=\"/member?sec=loan_report&amp;scope={scope_name}&amp;format=csv\">Download this report (CSV)</a> · <a href=\"/member\">Member area</a></p>{tables}</body></html>", escape(library)));
    if download_html { response.with_header("Content-Disposition", &format!("attachment; filename=\"{html_filename}\"")) }
    else { response }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::MemberLoan;
    fn profile(name: &str) -> MemberProfile { MemberProfile { name: name.into(), ..MemberProfile::default() } }
    #[test]
    fn report_scope_mismatch_fails_closed_and_empty_download_is_valid() {
        let returned = MemberLoanReportRow { returned: true, ..MemberLoanReportRow::default() };
        assert_eq!(render_scoped(&[returned], &profile("Alice"), "Library", true, MemberLoanReportScope::Current, false).status, 503);
        let empty = render_scoped(&[], &profile("Alice"), "Library", false, MemberLoanReportScope::History, true);
        assert_eq!(empty.status, 200);
        assert!(empty.headers.iter().any(|(key, value)| key == "Content-Disposition" && value.contains("loan_history.html")));
        assert!(String::from_utf8(empty.body).unwrap().contains("No loans."));
    }

    #[test]
    fn report_escapes_html_neutralizes_csv_and_refuses_partial_results() {
        let row = MemberLoanReportRow { returned: false, loan: MemberLoan { item_code: "=1+1".into(),
            title: Some("<script>\"x\"</script>".into()), ..MemberLoan::default() } };
        let html = String::from_utf8(render(&[row.clone()], &profile("<Alice>"), "<Library>", false).body).unwrap();
        assert!(html.contains("&lt;script&gt;"));
        assert!(html.contains("&lt;Alice&gt;"));
        assert!(!html.contains("<script>"));
        assert!(html.contains("Current loans (1)") && html.contains("Returned loans (0)"));
        let csv = String::from_utf8(render(&[row.clone()], &profile("Alice"), "Library", true).body).unwrap();
        assert!(csv.starts_with('\u{feff}'));
        assert!(csv.contains("\"'=1+1\""));
        assert_eq!(render(&vec![row.clone(); MAX_MEMBER_REPORT_LOANS], &profile("Alice"), "Library", false).status, 200);
        assert_eq!(render(&vec![row; MAX_MEMBER_REPORT_LOANS + 1], &profile("Alice"), "Library", true).status, 413);
        assert_eq!(render(&[], &profile(&"x".repeat(MAX_REPORT_DATA_BYTES + 1)), "Library", false).status, 413);
        assert_eq!(render(&[], &profile("Alice"), "Library", true).status, 200);
    }
}
