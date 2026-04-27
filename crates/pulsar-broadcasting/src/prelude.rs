//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3B.2 implementation will re-export `Channel`, `Broadcaster`,
//! `Presence`, plus the publish + subscribe entry points.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
