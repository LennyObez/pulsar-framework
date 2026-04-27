//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 1.5 implementation will re-export `RateLimit`, `Quota`,
//! `Window`, `Backend` builder + the middleware factory.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
