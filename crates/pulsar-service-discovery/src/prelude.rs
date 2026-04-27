//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3E.4 implementation will re-export `Resolver`, `Endpoint`,
//! `HealthState`, plus the `subscribe(...)` stream API.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
