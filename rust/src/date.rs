//! Civil calendar dates, without a dependency.
//!
//! SLiMS compares loan dates with `YEAR(...)`, `BETWEEN` and `now()`, and
//! formats `DATE_FORMAT(loan_date,'%d/%m')`. The OPAC needs the same arithmetic
//! for demo fixtures, so the conversions live here.

use std::time::{SystemTime, UNIX_EPOCH};

#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
pub struct Date {
    pub year: i32,
    pub month: u32,
    pub day: u32,
}

impl Default for Date {
    fn default() -> Self {
        Date {
            year: 1970,
            month: 1,
            day: 1,
        }
    }
}

impl Date {
    pub fn new(year: i32, month: u32, day: u32) -> Date {
        Date { year, month, day }
    }

    /// Days since 1970-01-01.
    pub fn to_days(&self) -> i64 {
        civil_to_days(self.year, self.month, self.day)
    }

    pub fn from_days(days: i64) -> Date {
        days_to_civil(days)
    }

    pub fn today() -> Date {
        let secs = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs() as i64)
            .unwrap_or(0);
        Date::from_days(secs.div_euclid(86_400))
    }

    pub fn add_days(&self, days: i64) -> Date {
        Date::from_days(self.to_days() + days)
    }

    /// `YYYY-MM-DD`
    pub fn iso(&self) -> String {
        format!("{:04}-{:02}-{:02}", self.year, self.month, self.day)
    }

    /// `DD/MM`, the format SLiMS uses in `LoanController::getDate`.
    pub fn day_month(&self) -> String {
        format!("{:02}/{:02}", self.day, self.month)
    }

    /// `YYYY-MM-DD HH:MM:SS`, MySQL `DATETIME` literal shape.
    pub fn iso_datetime(&self, hour: u32, minute: u32, second: u32) -> String {
        format!(
            "{:04}-{:02}-{:02} {:02}:{:02}:{:02}",
            self.year, self.month, self.day, hour, minute, second
        )
    }

    /// Parse the date part of `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS`.
    pub fn parse(text: &str) -> Option<Date> {
        let bytes = text.as_bytes();
        if bytes.len() < 10 {
            return None;
        }
        if bytes[4] != b'-' || bytes[7] != b'-' {
            return None;
        }
        let year = text.get(0..4)?.parse::<i32>().ok()?;
        let month = text.get(5..7)?.parse::<u32>().ok()?;
        let day = text.get(8..10)?.parse::<u32>().ok()?;
        if !(1..=12).contains(&month) || !(1..=31).contains(&day) {
            return None;
        }
        Some(Date { year, month, day })
    }
}

impl std::fmt::Display for Date {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.iso())
    }
}

/// Howard Hinnant's `civil_from_days`.
fn days_to_civil(z: i64) -> Date {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = (z - era * 146_097) as u64; // [0, 146096]
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365; // [0, 399]
    let y = yoe as i64 + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100); // [0, 365]
    let mp = (5 * doy + 2) / 153; // [0, 11]
    let d = (doy - (153 * mp + 2) / 5 + 1) as u32; // [1, 31]
    let m = if mp < 10 { mp + 3 } else { mp - 9 } as u32; // [1, 12]
    Date {
        year: (y + if m <= 2 { 1 } else { 0 }) as i32,
        month: m,
        day: d,
    }
}

/// Howard Hinnant's `days_from_civil`.
fn civil_to_days(y: i32, m: u32, d: u32) -> i64 {
    let y = y as i64 - if m <= 2 { 1 } else { 0 };
    let era = if y >= 0 { y } else { y - 399 } / 400;
    let yoe = (y - era * 400) as u64; // [0, 399]
    let mp = if m > 2 { m - 3 } else { m + 9 } as u64;
    let doy = (153 * mp + 2) / 5 + d as u64 - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe as i64 - 719_468
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trips_known_dates() {
        for (y, m, d) in [(1970, 1, 1), (2000, 2, 29), (2024, 12, 31), (2026, 11, 22)] {
            let date = Date::new(y, m, d);
            assert_eq!(Date::from_days(date.to_days()), date);
            assert_eq!(date.iso(), format!("{:04}-{:02}-{:02}", y, m, d));
        }
    }

    #[test]
    fn parses_iso_dates() {
        assert_eq!(
            Date::parse("2026-11-22 10:00:00"),
            Some(Date::new(2026, 11, 22))
        );
        assert_eq!(Date::parse("2026-11-22"), Some(Date::new(2026, 11, 22)));
        assert_eq!(Date::parse("not-a-date"), None);
    }

    #[test]
    fn formats_day_month() {
        assert_eq!(Date::new(2026, 1, 5).day_month(), "05/01");
    }
}
