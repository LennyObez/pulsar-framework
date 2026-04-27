//! Sealed trait markers per plan Section XVII.4 trait naming convention.
//!
//! `RateLimitBackend` will be sealed so third-party backends cannot
//! bypass the atomic refill+consume invariant proven in
//! `spec/ratelimit.tla`.

pub(crate) trait Sealed {}
