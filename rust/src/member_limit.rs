//! Process-local defense-in-depth for the experimental login; not a distributed
//! rate limiter or a replacement for edge throttling and a bounded HTTP server.
use std::collections::{HashMap, VecDeque};
use std::sync::Mutex;
use std::time::{Duration, Instant};

const WINDOW: Duration = Duration::from_secs(600);
const PER_MEMBER: usize = 8;
const GLOBAL: usize = 200;
const MAX_CONCURRENT: usize = 4;

#[derive(Default)]
struct State {
    attempts: VecDeque<Instant>,
    members: HashMap<String, VecDeque<Instant>>,
    active: usize,
}

#[derive(Default)]
pub struct LoginLimiter(Mutex<State>);

pub struct Permit<'a>(&'a LoginLimiter);
impl Drop for Permit<'_> {
    fn drop(&mut self) {
        let mut state = self.0.0.lock().unwrap_or_else(|e| e.into_inner());
        state.active = state.active.saturating_sub(1);
    }
}

impl LoginLimiter {
    /// Counts every accepted attempt, including successes and nonexistent IDs.
    /// A permit is held around both the DB lookup and bcrypt verification.
    pub fn acquire(&self, id: &str) -> Option<Permit<'_>> {
        self.acquire_at(id, Instant::now())
    }
    fn acquire_at(&self, id: &str, now: Instant) -> Option<Permit<'_>> {
        let mut state = self.0.lock().unwrap_or_else(|e| e.into_inner());
        let expired = |time: &Instant| now.saturating_duration_since(*time) >= WINDOW;
        while state.attempts.front().map(&expired).unwrap_or(false) { state.attempts.pop_front(); }
        state.members.retain(|_, times| {
            while times.front().map(&expired).unwrap_or(false) { times.pop_front(); }
            !times.is_empty()
        });
        // ASCII case and trailing-space variants commonly compare equal in
        // MySQL. Global limit still applies to other collation variants.
        let key = id.trim_end().to_ascii_lowercase();
        if state.active >= MAX_CONCURRENT || state.attempts.len() >= GLOBAL ||
            state.members.get(&key).map_or(false, |times| times.len() >= PER_MEMBER) {
            return None;
        }
        state.attempts.push_back(now);
        state.members.entry(key).or_default().push_back(now);
        state.active += 1;
        Some(Permit(self))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn case_variants_and_fresh_sessions_cannot_bypass_member_limit() {
        let limiter = LoginLimiter::default();
        let now = Instant::now();
        for _ in 0..PER_MEMBER { drop(limiter.acquire_at("M001", now).unwrap()); }
        assert!(limiter.acquire_at("m001 ", now).is_none());
        assert!(limiter.acquire_at("other", now).is_some());
        assert!(limiter.acquire_at("M001", now + WINDOW).is_some());
    }
    #[test]
    fn concurrency_and_global_limits_are_bounded() {
        let limiter = LoginLimiter::default();
        let now = Instant::now();
        let permits: Vec<_> = (0..MAX_CONCURRENT).map(|i| limiter.acquire_at(&format!("{i}"), now).unwrap()).collect();
        assert!(limiter.acquire_at("another", now).is_none());
        drop(permits);
        for i in MAX_CONCURRENT..GLOBAL { drop(limiter.acquire_at(&format!("{i}"), now).unwrap()); }
        assert!(limiter.acquire_at("another", now).is_none());
        assert!(limiter.acquire_at("another", now + WINDOW).is_some());
    }
}
