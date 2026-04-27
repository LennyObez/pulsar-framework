//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 1.5 implementation will re-export `Severity`, `Incident`,
//! `EscalationPolicy`, plus the `escalate(...)` entry point.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
