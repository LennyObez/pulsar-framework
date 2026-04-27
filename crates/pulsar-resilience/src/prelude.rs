//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 1.5 implementation will re-export `CircuitBreaker`, `Bulkhead`,
//! `Retry`, `Timeout` builders + the `tower::Layer` impls.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
