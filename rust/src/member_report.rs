//! Rust replacement for the combined PHP member loan report. Printable HTML,
//! not server-generated PDF. Caller must authenticate before invoking this module.
use crate::http::Response;
use crate::member::csv_cell;
use crate::models::{MemberLoanReportRow, MemberProfile, MAX_MEMBER_REPORT_LOANS};
use crate::templates::escape;

const MAX_REPORT_DATA_BYTES: usize = 512 * 1024;

fn fields(row: &MemberLoanReportRow) -> [&str; 6] {
    let l = &row.loan;
    [if row.returned { "Returned" } else { "Current" }, &l.item_code,
     l.title.as_deref().unwrap_or(""), l.loan_date.as_deref().unwrap_or(""),
     l.due_date.as_deref().unwrap_or(""), l.return_date.as_deref().unwrap_or("")]
}

pub(crate) fn render(rows: &[MemberLoanReportRow], profile: &MemberProfile, library: &str, csv: bool) -> Response {
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
    let headings = ["Status", "Item Code", "Title", "Loan Date", "Due Date", "Return Date"];
    if csv {
        let mut output = String::from("\u{feff}");
        for values in std::iter::once(headings).chain(rows.iter().map(fields)) {
            output.push_str(&values.map(csv_cell).join(","));
            output.push_str("\r\n");
        }
        return Response::new(200).with_header("Content-Type", "text/csv; charset=utf-8")
            .with_header("Content-Disposition", "attachment; filename=\"member-loan-report.csv\"")
            .with_body(output.into_bytes());
    }
    let mut profile_html = String::from("<table><tbody>");
    for (label, value) in profile_fields { profile_html.push_str(&format!("<tr><th scope=\"row\">{label}</th><td>{}</td></tr>", escape(value))); }
    profile_html.push_str("</tbody></table>");
    let mut tables = String::new();
    for returned in [false, true] {
        let count = rows.iter().filter(|r| r.returned == returned).count();
        tables.push_str(&format!("<h2>{} ({count})</h2><table><thead><tr>", if returned { "Returned loans" } else { "Current loans" }));
        for heading in &headings[1..] { tables.push_str(&format!("<th scope=\"col\">{heading}</th>")); }
        tables.push_str("</tr></thead><tbody>");
        for row in rows.iter().filter(|r| r.returned == returned) {
            tables.push_str("<tr>");
            for value in &fields(row)[1..] { tables.push_str(&format!("<td>{}</td>", escape(value))); }
            tables.push_str("</tr>");
        }
        if count == 0 { tables.push_str("<tr><td colspan=\"5\">No loans.</td></tr>"); }
        tables.push_str("</tbody></table>");
    }
    Response::html(200, format!("<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Member loan report</title><link rel=\"stylesheet\" href=\"/static/member-report.css\"></head><body><h1>Member loan report</h1><p>{}</p>{profile_html}<p>Combined current and returned loans, read together. Dates are stored values, not a fine calculation. This is a point-in-time read, not a signed or certified statement.</p><p class=\"screen-only\">Use your browser's Print / Save as PDF command. This service does not generate a PDF file. <a href=\"/member?sec=loan_report&amp;format=csv\">Download combined CSV</a> · <a href=\"/member\">Member area</a></p>{tables}</body></html>", escape(library)))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::MemberLoan;
    fn profile(name: &str) -> MemberProfile { MemberProfile { name: name.into(), ..MemberProfile::default() } }
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
