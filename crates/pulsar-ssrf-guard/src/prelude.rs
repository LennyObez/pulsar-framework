//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 1.5 implementation will re-export `Allowlist`, `Class`, plus
//! the `gated_client(...)` constructor that wraps `reqwest::Client`.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
