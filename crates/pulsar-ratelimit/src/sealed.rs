//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `RateLimitBackend` will be sealed so third-party backends cannot
//! bypass the atomic refill+consume invariant proven in
//! `spec/ratelimit.tla`.

#[allow(dead_code)]  // placeholder until per-sprint public traits use Sealed
pub(crate) trait Sealed {}
