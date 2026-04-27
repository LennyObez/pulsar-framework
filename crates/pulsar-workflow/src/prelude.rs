//! Glob-importable public prelude per plan Section XVII.2 module layout.
//!
//! Sprint 3E.1 implementation will re-export `Workflow`, `Activity`,
//! `Signal`, `WorkflowId`, plus the `#[workflow]` macro entry point.

#[allow(unused_imports)]
pub use crate::error::{Error, Result};
