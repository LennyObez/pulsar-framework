//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3E.4 implementation will re-export `Supervisor`, `RestartStrategy`,
//! `Worker`, plus the `#[supervisor]` macro entry point.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
